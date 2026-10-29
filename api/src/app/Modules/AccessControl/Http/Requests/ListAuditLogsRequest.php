<?php

namespace App\Modules\AccessControl\Http\Requests;

use App\Modules\Core\Audit\AuditOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'event' => ['sometimes', 'string', 'max:64'],
            'outcome' => ['sometimes', Rule::enum(AuditOutcome::class)],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'string', 'max:64'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
