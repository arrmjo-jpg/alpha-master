<?php

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Rules\ValidUsername;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $id = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'username' => ['sometimes', 'required', 'string', new ValidUsername, Rule::unique('users', 'username')->ignore($id)],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'preferred_locale' => ['sometimes', 'nullable', 'string', Rule::in(config('app.supported_locales'))],
            'password' => ['prohibited'],
            'account_type' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
            'banned_at' => ['prohibited'],
            'roles' => ['prohibited'],
            'permissions' => ['prohibited'],
        ];
    }
}
