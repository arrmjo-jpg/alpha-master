<?php

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Rules\ValidUsername;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
            $this->only(['username', 'email']),
        ));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', new ValidUsername, Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'preferred_locale' => ['nullable', 'string', Rule::in(config('app.supported_locales'))],
            // Protected fields are rejected outright, not silently ignored.
            'account_type' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
            'banned_at' => ['prohibited'],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
        ];
    }
}
