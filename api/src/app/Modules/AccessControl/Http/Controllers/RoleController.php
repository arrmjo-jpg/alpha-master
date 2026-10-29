<?php

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Actions\ManageRoles;
use App\Modules\AccessControl\Http\Requests\StoreRoleRequest;
use App\Modules\AccessControl\Http\Requests\SyncRolePermissionsRequest;
use App\Modules\AccessControl\Http\Requests\UpdateRoleRequest;
use App\Modules\AccessControl\Http\Resources\RoleResource;
use App\Modules\AccessControl\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    public function __construct(private readonly ManageRoles $roles) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->withCount('users')->orderBy('name')->get());
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->roles->update($request->user(), $role, $request->validated())->load('permissions'));
    }

    public function destroy(Request $request, Role $role): Response
    {
        $this->roles->delete($request->user(), $role);

        return response()->noContent();
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->roles->syncPermissions($request->user(), $role, $request->validated('permissions')));
    }
}
