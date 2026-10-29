<?php

namespace App\Modules\AccessControl\Http\Resources;

use App\Modules\AccessControl\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->translate('display_name'),
            'description' => $this->translate('description'),
            'is_protected' => $this->is_protected,
            $this->mergeWhen($request->query('include') === 'translations', fn () => [
                'display_name_translations' => $this->display_name,
                'description_translations' => $this->description,
            ]),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
