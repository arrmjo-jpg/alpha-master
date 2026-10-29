<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A device token as listed to its owner. The secret itself is never listed.
 *
 * @mixin PersonalAccessToken
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $this->id,
            'device_name' => $this->name,
            'client' => in_array('client:admin', $this->abilities ?? [], true) ? 'admin' : 'public',
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'is_current' => $current?->getKey() === $this->id,
        ];
    }
}
