<?php

namespace App\Modules\AccessControl\Http\Resources;

use App\Modules\Core\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString('microsecond'),
            'event' => $this->event,
            'outcome' => $this->outcome->value,
            'actor' => ['type' => $this->actor_type, 'id' => $this->actor_id],
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id] : null,
            'context' => $this->context ?? (object) [],
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
        ];
    }
}
