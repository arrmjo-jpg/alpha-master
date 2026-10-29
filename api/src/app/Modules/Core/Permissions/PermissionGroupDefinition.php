<?php

namespace App\Modules\Core\Permissions;

final readonly class PermissionGroupDefinition
{
    /**
     * @param  array{ar: string, en: string}  $name
     * @param  array{ar: string, en: string}  $description
     */
    public function __construct(
        public string $key,
        public array $name,
        public array $description,
        public int $sortOrder = 0,
    ) {}
}
