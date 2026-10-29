<?php

namespace App\Modules\Core\Permissions;

use BackedEnum;
use InvalidArgumentException;

/**
 * In-memory catalog of permissions declared by modules. Declaring a permission
 * never grants it to anyone; AccessControl mirrors the catalog to the database.
 */
final class PermissionRegistry
{
    /** @var array<string, PermissionGroupDefinition> */
    private array $groups = [];

    /** @var array<string, BackedEnum&DefinesPermissions> */
    private array $permissions = [];

    public function addGroup(PermissionGroupDefinition $group): void
    {
        $this->groups[$group->key] = $group;
    }

    /**
     * @param  class-string<BackedEnum&DefinesPermissions>  $enum
     */
    public function addPermissions(string $enum): void
    {
        if (! is_subclass_of($enum, BackedEnum::class) || ! is_subclass_of($enum, DefinesPermissions::class)) {
            throw new InvalidArgumentException("{$enum} must be a backed enum implementing DefinesPermissions.");
        }

        foreach ($enum::cases() as $case) {
            if (! preg_match('/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/', (string) $case->value)) {
                throw new InvalidArgumentException("Invalid permission identifier [{$case->value}].");
            }
            $this->permissions[(string) $case->value] = $case;
        }
    }

    /** @return array<string, PermissionGroupDefinition> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<string, BackedEnum&DefinesPermissions> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->permissions);
    }
}
