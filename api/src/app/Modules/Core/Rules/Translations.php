<?php

namespace App\Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a translated value: an object with exactly the supported locales
 * as keys (all required unless $required is false) and string values.
 */
class Translations implements ValidationRule
{
    public function __construct(private readonly int $max = 255, private readonly bool $required = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $locales = config('app.supported_locales');

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            $fail('core::validation.translations')->translate(['locales' => implode(', ', $locales)]);

            return;
        }

        if (array_diff(array_keys($value), $locales) !== []) {
            $fail('core::validation.translations')->translate(['locales' => implode(', ', $locales)]);

            return;
        }

        foreach ($locales as $locale) {
            $text = $value[$locale] ?? null;

            if ($text === null || $text === '') {
                if ($this->required) {
                    $fail('core::validation.translations')->translate(['locales' => implode(', ', $locales)]);

                    return;
                }

                continue;
            }

            if (! is_string($text) || mb_strlen(trim($text)) === 0 || mb_strlen($text) > $this->max) {
                $fail('core::validation.translation_length')->translate(['max' => $this->max]);

                return;
            }
        }
    }
}
