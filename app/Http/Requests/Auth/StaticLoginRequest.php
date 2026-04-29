<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StaticLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $configuredUsername = config('static-auth.username');
        $configuredPasswordHash = config('static-auth.password_hash');

        if (
            ! is_string($configuredUsername)
            || ! is_string($configuredPasswordHash)
            || ! hash_equals($configuredUsername, (string) $this->string('username'))
            || ! Hash::check((string) $this->string('password'), $configuredPasswordHash)
        ) {
            throw ValidationException::withMessages([
                'username' => __('The provided credentials are incorrect.'),
            ]);
        }
    }
}
