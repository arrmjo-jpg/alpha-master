<?php

namespace Tests\Unit;

use App\Modules\Core\Audit\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit redaction policy. All values are placeholders: assertion failures
 * print key names only, never anything that resembles a real secret.
 */
class SecretRedactorTest extends TestCase
{
    private const PLACEHOLDER = 'placeholder-value';

    /** Every key named in the hardening request, plus the original set. */
    public static function secretKeys(): array
    {
        $keys = [
            'bearer_token', 'api_token', 'authorization', 'private_key', 'cookie', 'session_id',
            'passcode', 'pwd', 'pin', 'app_secret', 'new_password_confirmation',
            'password', 'password_confirmation', 'current_password', 'token', 'plain_text_token',
            'access_token', 'refresh_token', 'secret', 'client_secret', 'otp', 'otp_code',
            'api_key', 'remember_token', 'session', 'cookies', 'jwt', 'credentials',
            'verification_code', 'reset_code', 'signing_key', 'csrf_token', 'X-XSRF-TOKEN',
        ];

        $cases = [];
        foreach ($keys as $key) {
            foreach (self::variants($key) as $variant) {
                $cases[$variant] = [$variant];
            }
        }

        return $cases;
    }

    /** Safe metadata, including every context key the application writes today. */
    public static function safeKeys(): array
    {
        return array_map(fn ($k) => [$k], array_combine($keys = [
            // token references and counts
            'token_id', 'TokenId', 'TOKEN_ID', 'token-id', 'tokens', 'tokens_count', 'token_type',
            'token_expires_at', 'token_ids', 'sessions',
            // harmless words that contain or resemble sensitive ones
            'key', 'code', 'error_code', 'permission_key', 'group_key', 'passed', 'pinned',
            'is_private', 'keyboard', 'spinner',
            // current application context keys (AuditLogger callers)
            'account_type', 'additional', 'affected_user_ids', 'client', 'fields', 'identifier_type',
            'method', 'name', 'reason', 'from', 'to', 'roles_removed', 'added', 'removed', 'route',
            'groups', 'permissions', 'deprecated', 'path', 'role_ids', 'device_name',
        ], $keys));
    }

    #[DataProvider('secretKeys')]
    public function test_secret_keys_are_redacted(string $key): void
    {
        $this->assertTrue(SecretRedactor::isSecretKey($key), "Key [{$key}] must be treated as secret.");
        $this->assertArrayNotHasKey($key, SecretRedactor::redact([$key => self::PLACEHOLDER]));
    }

    #[DataProvider('safeKeys')]
    public function test_safe_keys_are_preserved(string $key): void
    {
        $this->assertFalse(SecretRedactor::isSecretKey($key), "Key [{$key}] must be preserved.");
        $this->assertSame([$key => 7], SecretRedactor::redact([$key => 7]));
    }

    public function test_nested_arrays_and_lists_are_redacted_recursively(): void
    {
        $input = [
            'token_id' => 12,
            'tokens' => 3,
            'request' => [
                'Authorization' => self::PLACEHOLDER,
                'headers' => ['Cookie' => self::PLACEHOLDER, 'Accept-Language' => 'ar'],
                'body' => ['newPasswordConfirmation' => self::PLACEHOLDER, 'name' => 'Sara'],
            ],
            'devices' => [
                ['id' => 1, 'bearerToken' => self::PLACEHOLDER, 'device_name' => 'phone'],
                ['id' => 2, 'API_TOKEN' => self::PLACEHOLDER],
            ],
            'keys' => [['PrivateKey' => self::PLACEHOLDER, 'key' => 'users']],
        ];

        $this->assertSame([
            'token_id' => 12,
            'tokens' => 3,
            'request' => [
                'headers' => ['Accept-Language' => 'ar'],
                'body' => ['name' => 'Sara'],
            ],
            'devices' => [
                ['id' => 1, 'device_name' => 'phone'],
                ['id' => 2],
            ],
            'keys' => [['key' => 'users']],
        ], SecretRedactor::redact($input));
    }

    public function test_credential_shaped_values_are_dropped_under_any_key(): void
    {
        $result = SecretRedactor::redact([
            'note' => 'Bearer placeholder.value',
            'value' => '17|'.str_repeat('a', 40),
            'list' => ['ok', 'bearer placeholder'],
            'reason' => 'INVALID_CREDENTIALS',
        ]);

        $this->assertSame(['list' => ['ok'], 'reason' => 'INVALID_CREDENTIALS'], $result);
    }

    public function test_key_words_are_split_deterministically(): void
    {
        $this->assertSame(['bearer', 'token'], SecretRedactor::words('bearerToken'));
        $this->assertSame(['bearer', 'token'], SecretRedactor::words('BEARER_TOKEN'));
        $this->assertSame(['api', 'key'], SecretRedactor::words('APIKey'));
        $this->assertSame(['x', 'xsrf', 'token'], SecretRedactor::words('X-XSRF-TOKEN'));
        $this->assertSame(['new', 'password', 'confirmation'], SecretRedactor::words('newPasswordConfirmation'));
        $this->assertSame(['token', 'id'], SecretRedactor::words('token.id'));
    }

    /** @return list<string> snake, UPPER, kebab, camelCase and PascalCase spellings */
    private static function variants(string $key): array
    {
        $words = SecretRedactor::words($key);
        $camel = $words[0].implode('', array_map('ucfirst', array_slice($words, 1)));

        return array_values(array_unique([
            $key,
            strtoupper(implode('_', $words)),
            implode('-', $words),
            $camel,
            ucfirst($camel),
        ]));
    }
}
