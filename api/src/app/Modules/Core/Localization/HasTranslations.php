<?php

namespace App\Modules\Core\Localization;

/**
 * Reads `{ "ar": "...", "en": "..." }` JSON attributes (cast to array) in the
 * current locale, falling back to app.fallback_locale, then any non-empty value.
 */
trait HasTranslations
{
    public function translate(string $attribute, ?string $locale = null): ?string
    {
        $values = $this->getAttribute($attribute);

        if (! is_array($values) || $values === []) {
            return null;
        }

        $locale ??= app()->getLocale();

        return $values[$locale]
            ?? $values[config('app.fallback_locale')]
            ?? collect($values)->first(fn ($value) => is_string($value) && $value !== '');
    }
}
