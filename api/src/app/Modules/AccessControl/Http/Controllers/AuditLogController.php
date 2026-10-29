<?php

namespace App\Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\Http\Requests\ListAuditLogsRequest;
use App\Modules\AccessControl\Http\Resources\AuditLogResource;
use App\Modules\Core\Audit\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $logs = AuditLog::query()
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->orderByDesc('id')
            ->cursorPaginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
