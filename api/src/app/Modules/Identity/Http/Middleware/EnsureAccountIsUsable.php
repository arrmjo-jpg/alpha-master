<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account status on every authenticated request, so a banned or
 * deactivated account cannot keep using an earlier token or session even if
 * revocation was missed. The offending credential is destroyed on the spot.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isUsable()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            } elseif ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            throw DomainError::because($user->isBanned() ? ErrorCode::AccountBanned : ErrorCode::AccountInactive);
        }

        return $next($request);
    }
}
