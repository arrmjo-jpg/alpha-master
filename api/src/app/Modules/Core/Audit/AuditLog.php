<?php

namespace App\Modules\Core\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only security audit record (ADR-0009). Rows are never updated or
 * deleted through the application.
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string $event
 * @property AuditOutcome $outcome
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $context
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'outcome' => AuditOutcome::class,
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit records are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
