<?php

use App\Modules\AccessControl\AccessControlServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Users\UsersServiceProvider;
use App\Providers\AppServiceProvider;

// Modules are registered explicitly, in dependency order (ADR-0001):
// Core ← AccessControl ← Users ← Identity.
return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    AccessControlServiceProvider::class,
    UsersServiceProvider::class,
    IdentityServiceProvider::class,
];
