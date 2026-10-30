<?php

namespace App\Modules\AccessControl\Models;

use App\Modules\Core\Localization\HasTranslations;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Support\Config;

/**
 * @property int $id
 * @property string $name
 * @property array<string, string>|null $display_name
 * @property array<string, string>|null $description
 * @property bool $is_protected
 */
class Role extends SpatieRole
{
    use HasTranslations;

    /** The protected owner role (approved decision D1). Managed only via CLI + sync. */
    public const SYSTEM_OWNER = 'system-owner';

    protected function casts(): array
    {
        return [
            'display_name' => 'array',
            'description' => 'array',
            'is_protected' => 'boolean',
        ];
    }

    /**
     * Spatie resolves the user model from the role's guard_name. A role built for a query
     * (withCount('users')) gets the current default guard as guard_name, and auth:sanctum
     * makes that "sanctum", which has no user provider, so the relation broke (HTTP 500).
     * Every role uses the single "web" guard (ADR-0003), so fall back to its model.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            getModelForGuard($this->attributes['guard_name'] ?? 'web') ?? getModelForGuard('web'),
            'model',
            Config::modelHasRolesTable(),
            app(PermissionRegistrar::class)->pivotRole,
            Config::morphKey()
        );
    }

    public function isSystemOwner(): bool
    {
        return $this->name === self::SYSTEM_OWNER;
    }
}
