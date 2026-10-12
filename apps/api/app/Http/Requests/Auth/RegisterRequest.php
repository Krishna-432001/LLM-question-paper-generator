<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // DNS validation blocks typo'd and disposable domains in
            // production, but also blocks .test/.local addresses, so it is
            // relaxed outside production rather than forcing real inboxes.
            'email' => [
                'required',
                app()->isProduction() ? 'email:rfc,dns' : 'email:rfc',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required', 'string', 'confirmed',
                // uncompromised() checks the breach corpus: reused passwords
                // are the dominant account-takeover vector for this audience.
                Password::min(10)->letters()->numbers()->uncompromised(),
            ],
            'language_code' => ['sometimes', 'string', 'exists:languages,code'],
        ];
    }
}
