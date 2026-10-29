<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Core\Permissions\PermissionRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Every Arabic key has an English counterpart and vice versa (ADR-0007). */
class LanguageParityTest extends TestCase
{
    public function test_framework_language_files_have_identical_keys(): void
    {
        foreach (File::files(lang_path('en')) as $file) {
            $this->assertKeysMatch(lang_path('en/'.$file->getFilename()), lang_path('ar/'.$file->getFilename()));
        }
    }

    public function test_module_language_files_have_identical_keys(): void
    {
        foreach (glob(app_path('Modules/*/Lang/en/*.php')) as $en) {
            $this->assertKeysMatch($en, str_replace(DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR.'ar'.DIRECTORY_SEPARATOR, str_replace('/en/', '/ar/', $en)));
        }
    }

    public function test_every_error_code_has_both_titles(): void
    {
        foreach (ErrorCode::cases() as $code) {
            foreach (['ar', 'en'] as $locale) {
                $key = 'core::errors.'.$code->value;
                $this->assertNotSame($key, __($key, [], $locale), "Missing {$locale} title for {$code->value}");
            }
        }
    }

    public function test_every_permission_has_arabic_and_english_metadata(): void
    {
        $registry = app(PermissionRegistry::class);

        foreach ($registry->permissions() as $name => $permission) {
            foreach ([$permission->label(), $permission->description()] as $text) {
                $this->assertSame(['ar', 'en'], array_keys($text), $name);
                $this->assertNotSame('', trim($text['ar']), $name);
                $this->assertNotSame('', trim($text['en']), $name);
            }
        }

        foreach ($registry->groups() as $group) {
            $this->assertSame(['ar', 'en'], array_keys($group->name), $group->key);
        }
    }

    private function assertKeysMatch(string $enPath, string $arPath): void
    {
        $this->assertFileExists($arPath);
        $en = array_keys(Arr::dot(require $enPath));
        $ar = array_keys(Arr::dot(require $arPath));

        $this->assertSame([], array_values(array_diff($en, $ar)), "Missing in {$arPath}");
        $this->assertSame([], array_values(array_diff($ar, $en)), "Extra in {$arPath}");
    }
}
