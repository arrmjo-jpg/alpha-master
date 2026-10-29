<?php

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Http\Resources\PermissionGroupResource;
use App\Modules\AccessControl\Models\PermissionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /** The permission catalog, grouped and localized. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $withDeprecated = $request->boolean('include_deprecated');

        $groups = PermissionGroup::query()
            ->with(['permissions' => fn ($q) => $q->when(! $withDeprecated, fn ($q) => $q->where('is_deprecated', false))->orderBy('name')])
            ->orderBy('sort_order')->orderBy('key')
            ->get();

        return PermissionGroupResource::collection($groups);
    }
}
