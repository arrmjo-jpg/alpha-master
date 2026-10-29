<?php

// CI only: creates a throwaway system owner inside the running API container so the
// smoke test can exercise the real token flow on the hardened production image.
// Piped to `php` over stdin; never shipped in the image.

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = new App\Models\User;
$user->fill([
    'name' => 'CI Owner',
    'username' => 'ci.owner',
    'email' => 'ci.owner@example.com',
    'password' => 'Ci-password-12345',
]);
$user->forceFill(['account_type' => 'admin'])->save();
$user->assignRole('system-owner');

echo "smoke owner {$user->id}\n";
