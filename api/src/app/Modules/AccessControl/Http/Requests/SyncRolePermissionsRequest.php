<?php

namespace App\Modules\AccessControl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRolePermissionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => [
                'string', 'distinct',
                Rule::exists(config('permission.table_names.permissions'), 'name')
                    ->where('guard_name', 'web')->where('is_deprecated', false),
            ],
            // Sensitive operation: re-confirm the actor's password.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
