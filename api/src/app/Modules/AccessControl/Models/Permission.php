<?php

namespace App\Modules\AccessControl\Models;

use App\Modules\Core\Localization\HasTranslations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie permission + translated metadata. `name` stays the stable,
 * language-independent identifier; labels live in display_name (ADR-0004).
 *
 * @property string $name
 * @property array<string, string>|null $display_name
 * @property array<string, string>|null $description
 * @property bool $is_sensitive
 * @property bool $is_deprecated
 */
class Permission extends SpatiePermission
{
    use HasTranslations;

    protected function casts(): array
    {
        return [
            'display_name' => 'array',
            'description' => 'array',
            'is_sensitive' => 'boolean',
            'is_deprecated' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class, 'permission_group_id');
    }
}
