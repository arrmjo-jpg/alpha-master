<?php

namespace App\Modules\Users\Http\Resources;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of an account. Never exposes password, remember_token or tokens.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            'account_type' => $this->account_type->value,
            'status' => [
                'state' => match (true) {
                    $this->isBanned() => 'banned',
                    $this->isDeactivated() => 'deactivated',
                    default => 'active',
                },
                'deactivated_at' => $this->deactivated_at?->toIso8601ZuluString(),
                'banned_at' => $this->banned_at?->toIso8601ZuluString(),
                'ban_reason' => $this->ban_reason,
            ],
            'preferred_locale' => $this->preferred_locale,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->translate('display_name'),
            ])->values()),
            'is_system_owner' => $this->whenLoaded('roles', fn () => $this->roles->contains('name', Role::SYSTEM_OWNER)),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
