<?php

use App\Modules\Core\Errors\DomainError;
use App\Modules\Core\Errors\ProblemRenderer;
use App\Modules\Core\Http\Middleware\AssignRequestId;
use App\Modules\Core\Http\Middleware\SetLocaleFromRequest;
use App\Modules\Identity\Http\Middleware\EnsureAccountIsUsable;
use App\Modules\Identity\Http\Middleware\EnsureAdminClient;
use App\Modules\Users\Http\Middleware\EnsureAdminAccount;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([AssignRequestId::class, SetLocaleFromRequest::class]);

        // Sanctum: cookie sessions for first-party SPA origins, tokens otherwise.
        $middleware->statefulApi();
        $middleware->throttleApi();

        // Pure API: never redirect guests to a (non-existent) login page. Unauthenticated
        // requests always get a 401 Problem response, with or without `Accept: application/json`.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'account.usable' => EnsureAccountIsUsable::class,
            'admin.account' => EnsureAdminAccount::class,
            'admin.client' => EnsureAdminClient::class,
        ]);

        // Every /api/v1/admin/* route: authenticated, usable, admin account, admin client.
        // Each route then adds its own `can:<permission>` (no implicit full access).
        $middleware->group('admin', [
            'auth:sanctum',
            'account.usable',
            'admin.account',
            'admin.client',
        ]);

        // Run the account/admin gates before route-model binding, so non-admins
        // cannot probe which record IDs exist (403, never 404).
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureAccountIsUsable::class);
        $middleware->appendToPriorityList(EnsureAccountIsUsable::class, EnsureAdminAccount::class);
        $middleware->appendToPriorityList(EnsureAdminAccount::class, EnsureAdminClient::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Business-rule outcomes are expected and already audited; they are not errors to log.
        $exceptions->dontReport(DomainError::class);

        // RFC 9457 Problem Details for every API error (approved decision D2).
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? ProblemRenderer::render($e, $request)
            : null);
    })->create();
