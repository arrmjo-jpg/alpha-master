<?php

namespace App\Modules\Core\Audit;

/**
 * Deterministic redaction policy for audit context (ADR-0009).
 *
 * A key is split into lowercase words: separators (`_`, `-`, `.`, space) and
 * camelCase/PascalCase boundaries both split, so `bearerToken`, `bearer_token`,
 * `Bearer-Token` and `BEARER_TOKEN` all become [bearer, token]. The key is
 * secret when:
 *
 *  1. any word is always secret (password, pwd, secret, otp, cookie, session, …); or
 *  2. a word is `token`/`tokens`/`jwt`, unless the LAST word is a reference
 *     suffix (`id`, `count`, `type`, `at`, …) — so `token_id`, `tokens_count`
 *     and `token_expires_at` stay; `api_token`, `plainTextToken` go; or
 *  3. `key`/`keys` follows a credential qualifier (api, private, secret, …) —
 *     a plain `key` (e.g. a permission-group key) stays; or
 *  4. `code` follows a one-time-code qualifier (otp, verification, reset, …) —
 *     a plain `code` (e.g. an error code) stays.
 *
 * The exact key `tokens` (a revocation count) is explicitly safe. Independently
 * of the key, string values shaped like credentials (an HTTP `Bearer …` header
 * or a Sanctum `id|secret` token) are dropped. Redacted entries are removed,
 * not masked. Arrays and lists are processed recursively.
 */
final class SecretRedactor
{
    private const ALWAYS_SECRET = [
        'password', 'passwd', 'pwd', 'pass', 'passcode', 'passphrase',
        'secret', 'secrets', 'otp', 'totp', 'pin',
        'cookie', 'cookies', 'session', 'authorization', 'bearer',
        'credential', 'credentials', 'csrf', 'xsrf', 'salt',
    ];

    private const TOKEN_WORDS = ['token', 'tokens', 'jwt'];

    /** Last words that make a token-ish key a reference, not a secret value. */
    private const REFERENCE_SUFFIXES = ['id', 'ids', 'count', 'type', 'name', 'at', 'prefix', 'hint', 'length'];

    private const KEY_QUALIFIERS = ['api', 'private', 'secret', 'access', 'signing', 'encryption', 'client', 'ssh', 'app', 'license', 'master'];

    private const CODE_QUALIFIERS = ['otp', 'totp', 'verification', 'verify', 'reset', 'auth', 'recovery', 'backup', 'mfa', 'sms', 'confirmation', 'activation', 'login', 'security'];

    private const SAFE_EXACT_KEYS = ['tokens'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];
        $isList = array_is_list($data);

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                continue;
            }
            if (is_string($value) && self::looksLikeCredential($value)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $isList ? array_values($clean) : $clean;
    }

    public static function isSecretKey(string $key): bool
    {
        if (in_array(strtolower($key), self::SAFE_EXACT_KEYS, true)) {
            return false;
        }

        $words = self::words($key);
        if ($words === []) {
            return false;
        }

        $last = $words[array_key_last($words)];

        foreach ($words as $i => $word) {
            $previous = $words[$i - 1] ?? null;

            if (in_array($word, self::ALWAYS_SECRET, true)) {
                return true;
            }
            if (in_array($word, self::TOKEN_WORDS, true) && ! in_array($last, self::REFERENCE_SUFFIXES, true)) {
                return true;
            }
            if (in_array($word, ['key', 'keys'], true) && in_array($previous, self::KEY_QUALIFIERS, true)) {
                return true;
            }
            if ($word === 'code' && in_array($previous, self::CODE_QUALIFIERS, true)) {
                return true;
            }
            // Compact spellings without a boundary: apikey, privatekey, accesstoken, …
            if (preg_match('/^(api|private|secret|access|signing|client|app)keys?$|^(access|refresh|bearer|api|auth|plaintext|remember)tokens?$/', $word)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function words(string $key): array
    {
        $split = preg_replace(['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'], ' ', $key);
        $parts = preg_split('/[^a-z0-9]+/', strtolower((string) $split), -1, PREG_SPLIT_NO_EMPTY);

        return array_values($parts ?: []);
    }

    private static function looksLikeCredential(string $value): bool
    {
        return (bool) preg_match('/^\s*Bearer\s+\S+|^\d+\|[A-Za-z0-9]{40,}$/i', $value);
    }
}
