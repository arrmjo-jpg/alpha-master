<?php

namespace App\Modules\AccessControl\Models;

use App\Modules\Core\Localization\HasTranslations;
use Spatie\Permission\Models\Role as SpatieRole;

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

    public function isSystemOwner(): bool
    {
        return $this->name === self::SYSTEM_OWNER;
    }
}
