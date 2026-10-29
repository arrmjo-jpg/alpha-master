<?php

namespace App\Modules\Core\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes audit records. Callers pass only allow-listed, non-secret context;
 * keys that look like secrets are additionally stripped as a safety net.
 */
class AuditLogger
{
    // Whole-key match only: strips secret values (password, token, otp, …) while
    // keeping references such as token_id or counts like `tokens`.
    private const SECRET_KEY_PATTERN = '/^(password|password_confirmation|current_password|new_password|pass|token|plain_?text_?token|access_?token|refresh_?token|secret|client_?secret|otp|otp_?code|code|api_?key|remember_?token)$/i';

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $context = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?User $actor = null,
        ?string $actorType = null,
    ): AuditLog {
        $actor ??= $this->request->user();
        $actorType ??= $actor ? 'user' : (app()->runningInConsole() ? 'cli' : 'system');
        $console = app()->runningInConsole() && ! app()->runningUnitTests();

        return AuditLog::create([
            'occurred_at' => now(),
            'event' => $event,
            'outcome' => $outcome,
            'actor_type' => $actorType,
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'context' => self::scrub($context) ?: null,
            'ip' => $console ? null : $this->request->ip(),
            'user_agent' => $console ? null : Str::limit((string) $this->request->userAgent(), 252),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function scrub(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }
}
