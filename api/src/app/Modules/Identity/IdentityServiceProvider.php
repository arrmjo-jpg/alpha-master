<?php

namespace App\Modules\Identity;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Identity\Actions\RevokeCredentials;
use App\Modules\Users\Events\CredentialsRevocationRequested;
use Illuminate\Support\Facades\Event;

class IdentityServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadApiRoutes();

        Event::listen(CredentialsRevocationRequested::class, RevokeCredentials::class);
    }
}
