<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Errors\DomainError;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Runs a security-critical change in one DB transaction. The caller writes its
 * success audit record inside $work (so it commits or rolls back with the change);
 * a business-rule denial is recorded as `denied` after the rollback.
 */
class GuardedOperation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  array<string, mixed>  $context
     * @return T
     */
    public function run(string $event, ?Model $subject, array $context, Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (DomainError $e) {
            $this->audit->record($event, $subject, $context + ['reason' => $e->errorCode->value], AuditOutcome::Denied);

            throw $e;
        }
    }
}
