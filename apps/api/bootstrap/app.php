<?php

declare(strict_types=1);

use App\Domain\Ai\Exceptions\BudgetExceededException;
use App\Domain\Ingestion\Exceptions\SourceQuarantinedException;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | The API is stateless: clients authenticate with a Bearer token, not a
        | session cookie. Sanctum's EnsureFrontendRequestsAreStateful is
        | deliberately NOT registered — it would treat requests from the
        | configured SPA domains as cookie-session requests and enforce CSRF,
        | which returns 419 for a token-authenticated browser client. It also
        | could never have worked here: CORS runs with
        | supports_credentials = false, so cookies are not sent cross-origin.
        */

        // Tenant resolution runs on every authenticated API request. It is
        // registered globally rather than per-route so a new route cannot
        // accidentally opt out of tenant isolation.
        $middleware->api(append: [
            ResolveTenant::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // RFC 7807 problem+json for the whole API surface.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = (string) $request->header('X-Request-Id', (string) Str::uuid7());

            if ($e instanceof BudgetExceededException) {
                return response()->json([
                    'type' => 'https://api.examforge.in/problems/quota-exceeded',
                    'title' => 'Quota exceeded',
                    'status' => 402,
                    'detail' => $e->getMessage(),
                    'instance' => $request->path(),
                    'meta' => [
                        'metric' => $e->metric,
                        'limit' => $e->limit,
                        'used' => $e->used,
                        'resets_at' => $e->resetsAt,
                        'request_id' => $requestId,
                    ],
                ], 402);
            }

            if ($e instanceof SourceQuarantinedException) {
                return response()->json([
                    'type' => 'https://api.examforge.in/problems/source-quarantined',
                    'title' => 'File failed the malware scan',
                    'status' => 422,
                    'detail' => $e->getMessage(),
                    'instance' => $request->path(),
                    'meta' => ['request_id' => $requestId],
                ], 422);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'type' => 'https://api.examforge.in/problems/validation-failed',
                    'title' => 'Validation failed',
                    'status' => 422,
                    'detail' => 'The request payload failed validation.',
                    'instance' => $request->path(),
                    'errors' => $e->errors(),
                    'meta' => ['request_id' => $requestId],
                ], 422);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'type' => 'https://api.examforge.in/problems/'.$status,
                'title' => match ($status) {
                    401 => 'Unauthenticated',
                    403 => 'Forbidden',
                    // Never distinguish "does not exist" from "not yours":
                    // that difference is itself a cross-tenant information leak.
                    404 => 'Not found',
                    400 => 'Bad request',
                    409 => 'Conflict',
                    422 => 'Request cannot be processed',
                    429 => 'Too many requests',
                    default => $status >= 500 ? 'Server error' : 'Request failed',
                },
                'status' => $status,
                'detail' => $status === 500 && ! config('app.debug')
                    ? 'An unexpected error occurred.'
                    : $e->getMessage(),
                'instance' => $request->path(),
                'meta' => ['request_id' => $requestId],
            ], $status);
        });
    })->create();
