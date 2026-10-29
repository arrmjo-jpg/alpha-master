<?php

namespace App\Modules\Users\Http\Requests;

use App\Modules\Users\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['sometimes', Rule::enum(AccountType::class)],
            'status' => ['sometimes', Rule::in(['active', 'deactivated', 'banned'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
