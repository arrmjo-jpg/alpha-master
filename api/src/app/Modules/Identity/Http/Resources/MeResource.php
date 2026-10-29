<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\AccessControl\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own view. `permissions` is for hiding UI only;
 * the server enforces every check (ADR-0004 §6).
 *
 * @mixin User
 */
class MeResource extends JsonResource
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
            'preferred_locale' => $this->preferred_locale,
            'roles' => $this->roles->map(fn (Role $role) => [
                'name' => $role->name,
                'display_name' => $role->translate('display_name'),
            ])->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->unique()->sort()->values(),
        ];
    }
}
