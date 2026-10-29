<?php

namespace App\Modules\AccessControl\Services;

use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionGroup;
use App\Modules\AccessControl\Models\Role;
use App\Modules\Core\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mirrors the code-defined catalog into the database (idempotent). It never
 * grants anything to any role or user except re-syncing the protected
 * system-owner role to "all non-deprecated permissions" (approved decision D1).
 */
class PermissionSynchronizer
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @return array{groups: int, permissions: int, deprecated: list<string>}
     */
    public function sync(): array
    {
        $result = DB::transaction(function () {
            $groupIds = [];
            foreach ($this->registry->groups() as $definition) {
                $group = PermissionGroup::updateOrCreate(['key' => $definition->key], [
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'sort_order' => $definition->sortOrder,
                ]);
                $groupIds[$definition->key] = $group->id;
            }

            foreach ($this->registry->permissions() as $name => $permission) {
                if (! isset($groupIds[$permission->group()])) {
                    throw new \LogicException("Permission [{$name}] references unknown group [{$permission->group()}].");
                }

                Permission::query()->updateOrCreate(['name' => $name, 'guard_name' => 'web'], [
                    'permission_group_id' => $groupIds[$permission->group()],
                    'display_name' => $permission->label(),
                    'description' => $permission->description(),
                    'is_sensitive' => $permission->isSensitive(),
                    'is_deprecated' => false,
                ]);
            }

            $stale = Permission::query()->whereNotIn('name', $this->registry->names())->where('is_deprecated', false);
            $deprecated = $stale->pluck('name')->all();
            $stale->update(['is_deprecated' => true]);

            $owner = Role::query()->firstOrCreate(
                ['name' => Role::SYSTEM_OWNER, 'guard_name' => 'web'],
                ['is_protected' => true],
            );
            $owner->forceFill([
                'is_protected' => true,
                'display_name' => ['ar' => 'مالك النظام', 'en' => 'System owner'],
                'description' => [
                    'ar' => 'دور محمي يملك جميع الصلاحيات، ولا يُمنح أو يُسحب إلا من سطر أوامر الخادم',
                    'en' => 'Protected role holding every permission; granted or revoked only from the server command line',
                ],
            ])->save();
            $owner->syncPermissions(Permission::query()->where('is_deprecated', false)->get());

            return [
                'groups' => count($groupIds),
                'permissions' => count($this->registry->permissions()),
                'deprecated' => $deprecated,
            ];
        });

        $this->registrar->forgetCachedPermissions();

        return $result;
    }
}
