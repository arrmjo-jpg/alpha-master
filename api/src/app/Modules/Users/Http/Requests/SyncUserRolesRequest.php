<?php

namespace App\Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:50'],
            'roles.*' => ['integer', 'distinct', Rule::exists(config('permission.table_names.roles'), 'id')],
            // Sensitive operation: re-confirm the actor's password.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
