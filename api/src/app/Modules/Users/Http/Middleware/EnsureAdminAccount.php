<?php

namespace App\Modules\Users\Http\Middleware;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\AuditOutcome;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only `admin` accounts may enter /api/v1/admin/*. This opens the door only;
 * every operation still needs its own permission (no implicit full access).
 */
class EnsureAdminAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isAdmin()) {
            $this->audit->record('admin.access_denied', $user, [
                'account_type' => $user?->account_type->value,
                'path' => $request->path(),
            ], AuditOutcome::Denied);

            throw DomainError::because(ErrorCode::AdminAccessRequired);
        }

        return $next($request);
    }
}
