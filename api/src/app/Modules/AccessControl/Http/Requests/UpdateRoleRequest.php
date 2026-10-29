<?php

namespace App\Modules\AccessControl\Http\Requests;

use App\Modules\Core\Rules\Translations;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', new Translations(100)],
            'description' => ['sometimes', 'nullable', new Translations(500)],
            // The key is the stable identifier; it never changes.
            'name' => ['prohibited'],
            'permissions' => ['prohibited'],
            'is_protected' => ['prohibited'],
        ];
    }
}
