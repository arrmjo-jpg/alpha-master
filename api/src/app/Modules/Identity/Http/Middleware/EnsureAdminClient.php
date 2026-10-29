<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin endpoints accept only an admin client (ADR-0003 §1): a token issued
 * with the `client:admin` ability, or a first-party SPA session (Sanctum only
 * starts a session for origins listed in SANCTUM_STATEFUL_DOMAINS).
 */
class EnsureAdminClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can('client:admin')) {
            throw DomainError::because(ErrorCode::AdminAccessRequired);
        }

        return $next($request);
    }
}
