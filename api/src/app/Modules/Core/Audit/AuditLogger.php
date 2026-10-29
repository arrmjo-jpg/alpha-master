<?php

namespace App\Modules\Core\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes audit records. Callers pass only allow-listed, non-secret context;
 * SecretRedactor additionally strips anything that looks like a secret.
 */
class AuditLogger
{
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
            'context' => SecretRedactor::redact($context) ?: null,
            'ip' => $console ? null : $this->request->ip(),
            'user_agent' => $console ? null : Str::limit((string) $this->request->userAgent(), 252),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }
}
