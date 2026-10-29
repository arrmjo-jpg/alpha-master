<?php

namespace App\Modules\AccessControl\Http\Resources;

use App\Modules\AccessControl\Models\PermissionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PermissionGroup */
class PermissionGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->translate('name'),
            'description' => $this->translate('description'),
            'sort_order' => $this->sort_order,
            $this->mergeWhen($request->query('include') === 'translations', fn () => [
                'name_translations' => $this->name,
                'description_translations' => $this->description,
            ]),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
