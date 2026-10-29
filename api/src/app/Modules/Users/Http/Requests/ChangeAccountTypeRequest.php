<?php

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAccountTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            // Sensitive operation: re-confirm the actor's password.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
