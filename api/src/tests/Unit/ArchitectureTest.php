<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Module dependency direction (ADR-0001): Core ← AccessControl ← Users ← Identity.
 * A module may only import modules it is allowed to depend on.
 */
class ArchitectureTest extends TestCase
{
    private const ALLOWED = [
        'Core' => [],
        'AccessControl' => ['Core'],
        'Users' => ['Core', 'AccessControl'],
        'Identity' => ['Core', 'AccessControl', 'Users'],
    ];

    public static function modules(): array
    {
        return array_map(fn ($m) => [$m], array_combine(array_keys(self::ALLOWED), array_keys(self::ALLOWED)));
    }

    #[DataProvider('modules')]
    public function test_module_only_depends_on_allowed_modules(string $module): void
    {
        $violations = [];

        foreach ($this->phpFiles(__DIR__."/../../app/Modules/{$module}") as $file) {
            preg_match_all('/^use App\\\\Modules\\\\(\w+)\\\\/m', file_get_contents($file), $matches);
            foreach (array_unique($matches[1]) as $used) {
                if ($used !== $module && ! in_array($used, self::ALLOWED[$module], true)) {
                    $violations[] = basename($file)." uses {$used}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_no_global_authorization_bypass_exists(): void
    {
        foreach ($this->phpFiles(__DIR__.'/../../app') as $file) {
            $this->assertStringNotContainsString('Gate::before', file_get_contents($file), $file);
        }
    }

    public function test_protected_user_columns_are_not_mass_assignable(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Models/User.php');
        preg_match('/#\[Fillable\(\[(.*?)\]\)\]/s', $source, $m);

        foreach (['account_type', 'deactivated_at', 'banned_at', 'banned_by', 'ban_reason', 'email_verified_at'] as $column) {
            $this->assertStringNotContainsString("'{$column}'", $m[1]);
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
