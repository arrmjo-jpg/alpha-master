<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\AuditOutcome;
use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ErrorCode;
use App\Modules\Identity\Actions\RevokeCredentials;
use App\Modules\Identity\Actions\VerifyCredentials;
use App\Modules\Identity\Http\Requests\IssueTokenRequest;
use App\Modules\Identity\Http\Resources\TokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** Per-device personal access tokens for mobile clients (ADR-0003 §1). */
class TokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(IssueTokenRequest $request, VerifyCredentials $verify): JsonResponse
    {
        $user = $verify($request->validated('identifier'), $request->validated('password'));
        $client = $request->validated('client');

        if ($client === 'admin' && ! $user->isAdmin()) {
            $this->audit->record('auth.token_denied', $user, ['client' => $client], AuditOutcome::Denied, actorType: 'anonymous');

            throw DomainError::because(ErrorCode::AdminAccessRequired);
        }

        $expiresAt = now()->addDays(config("sanctum.token_ttl_days.{$client}"));
        $token = $user->createToken($request->validated('device_name'), ["client:{$client}"], $expiresAt);

        $this->audit->record('auth.token_issued', $user, ['client' => $client, 'token_id' => $token->accessToken->id], actor: $user);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'client' => $client,
                'expires_at' => $expiresAt->toIso8601ZuluString(),
            ],
        ], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($request->user()->tokens()->orderByDesc('id')->get());
    }

    public function destroy(Request $request, int $token): Response
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        if ($deleted === 0) {
            throw DomainError::because(ErrorCode::ResourceNotFound);
        }

        $this->audit->record('auth.token_revoked', $request->user(), ['token_id' => $token]);

        return response()->noContent();
    }

    /** Logs out every device and every session of the current user. */
    public function destroyAll(Request $request, RevokeCredentials $revoke): Response
    {
        $user = $request->user();
        $counts = $revoke($user);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->audit->record('auth.all_credentials_revoked', $user, $counts);

        return response()->noContent();
    }
}
