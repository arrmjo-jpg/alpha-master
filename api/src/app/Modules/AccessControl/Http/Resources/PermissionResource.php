<?php

namespace App\Modules\AccessControl\Http\Resources;

use App\Modules\AccessControl\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Permission */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->translate('display_name'),
            'description' => $this->translate('description'),
            'is_sensitive' => $this->is_sensitive,
            'is_deprecated' => $this->is_deprecated,
            $this->mergeWhen($request->query('include') === 'translations', fn () => [
                'display_name_translations' => $this->display_name,
                'description_translations' => $this->description,
            ]),
        ];
    }
}
