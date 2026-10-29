<?php

namespace App\Modules\Users\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 3–30 chars of [a-z0-9._], starting and ending alphanumeric, at least one
 * letter (never all digits) and never containing "@" — so a login identifier
 * is unambiguously an email or a username (ADR-0003 §2).
 */
class ValidUsername implements ValidationRule
{
    public const RESERVED = [
        'admin', 'administrator', 'root', 'system', 'support', 'api', 'owner',
        'security', 'help', 'info', 'null', 'undefined', 'me', 'alphamaster',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(?=[a-z0-9._]*[a-z])[a-z0-9](?:[a-z0-9._]{1,28})[a-z0-9]$/', $value)) {
            $fail('core::validation.username_format')->translate();

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail('core::validation.username_reserved')->translate();
        }
    }
}
