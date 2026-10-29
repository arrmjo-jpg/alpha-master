<?php

namespace App\Modules\Core;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Permissions\PermissionRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
        $this->app->scoped(AuditLogger::class);
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadModuleTranslations('core');

        Password::defaults(fn () => Password::min(12)->letters()->numbers()->max(255));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Endpoints that re-confirm the actor's password: tight per-user limits so a
        // stolen session/token cannot be used to guess it.
        RateLimiter::for('sensitive', fn (Request $request) => [
            Limit::perMinute(5)->by('sensitive:'.($request->user()?->getAuthIdentifier() ?: $request->ip())),
            Limit::perHour(30)->by('sensitive-hour:'.($request->user()?->getAuthIdentifier() ?: $request->ip())),
        ]);

        // Per identifier+IP and per IP (ADR-0009). Keys never contain the password.
        RateLimiter::for('login', function (Request $request) {
            $identifier = Str::lower(trim((string) $request->input('identifier')));

            return [
                Limit::perMinute(5)->by('login:'.sha1($identifier.'|'.$request->ip())),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
