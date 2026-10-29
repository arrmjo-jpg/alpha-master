<?php

namespace App\Modules\AccessControl\Http\Requests;

use App\Modules\Core\Rules\Translations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                'regex:/^[a-z][a-z0-9-]{1,49}$/',
                Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', 'web'),
            ],
            'display_name' => ['required', new Translations(100)],
            'description' => ['nullable', new Translations(500)],
            'permissions' => ['prohibited'],
            'is_protected' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['name.regex' => __('core::validation.role_key_format')];
    }
}
