<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Domain\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->execute(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            languageCode: $request->string('language_code', 'en')->toString(),
        );

        // Registration provisions an organisation and a plan as well as a
        // user, which makes it more consequential than a login and worth its
        // own entry.
        $this->audit()->record('auth.registered', $user, [
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email],
                'token' => $user->createToken('api')->plainTextToken,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $throttleKey = 'login:'.mb_strtolower($email).'|'.$request->ip();

        // Credential stuffing is the realistic attack here, so the limiter is
        // keyed on email AND ip: neither rotating one alone gets you further.
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            // The limiter tripping is itself the signal worth keeping: it means
            // someone got five attempts wrong against this address from this
            // IP, which is what credential stuffing looks like from here.
            $this->audit()->record('auth.throttled', null, [
                'email' => $email,
                'available_in_seconds' => RateLimiter::availableIn($throttleKey),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.'],
            ])->status(429);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), (string) $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            // Recorded against the user when one exists, so "someone is trying
            // this account" is answerable. The distinction between a wrong
            // password and an unknown address is kept here but never returned
            // to the caller, which would enumerate accounts.
            $this->audit()->record('auth.failed', $user, [
                'email' => $email,
                'reason' => $user === null ? 'unknown_email' : 'bad_password',
            ]);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($throttleKey);
        $user->forceFill(['last_login_at' => now()])->save();

        // The token itself is never recorded — only that one was issued.
        $this->audit()->record('auth.login', $user, ['email' => $user->email]);

        return response()->json([
            'data' => [
                'user' => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email],
                'token' => $user->createToken('api')->plainTextToken,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user?->currentAccessToken()?->delete();

        if ($user !== null) {
            $this->audit()->record('auth.logout', $user, []);
        }

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }

    /**
     * Resolved lazily rather than injected: these endpoints run before a
     * tenant exists, and the recorder tolerates that while a constructor
     * dependency would drag container wiring into the unauthenticated path.
     */
    private function audit(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }
}
