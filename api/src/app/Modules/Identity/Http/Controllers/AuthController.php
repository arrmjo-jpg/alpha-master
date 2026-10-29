<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Identity\Actions\VerifyCredentials;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Cookie-session login for first-party SPAs (React admin). Only requests from
     * SANCTUM_STATEFUL_DOMAINS get a session; others must use POST /auth/tokens.
     */
    public function login(LoginRequest $request, VerifyCredentials $verify): MeResource
    {
        if (! $request->hasSession()) {
            throw DomainError::because(ErrorCode::StatefulRequestRequired);
        }

        $user = $verify($request->validated('identifier'), $request->validated('password'));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record('auth.login', $user, ['method' => 'session'], actor: $user);

        return new MeResource($user->load('roles'));
    }

    /** Ends the current credential only (this token, or this session). */
    public function logout(Request $request): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->audit->record('auth.logout', $user, ['method' => $token instanceof PersonalAccessToken ? 'token' : 'session'], actor: $user);

        return response()->noContent();
    }

    public function me(Request $request): MeResource
    {
        return new MeResource($request->user()->load('roles'));
    }
}
