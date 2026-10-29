<?php

namespace App\Modules\Core\Permissions;

/**
 * Implemented by each module's string-backed permission enum. The enum value
 * is the stable, language-independent permission identifier (ADR-0004).
 */
interface DefinesPermissions
{
    /** Stable key of the permission group this permission belongs to. */
    public function group(): string;

    /** @return array{ar: string, en: string} */
    public function label(): array;

    /** @return array{ar: string, en: string} */
    public function description(): array;

    /** Sensitive permissions require the granting actor's current password. */
    public function isSensitive(): bool;
}
