<?php

use App\Modules\Users\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

// Admin "users" area. The `admin` group = authentication, usable account,
// admin account, admin client; each route then requires its own permission.
Route::prefix('admin/users')->middleware('admin')->controller(AdminUserController::class)->group(function () {
    Route::get('/', 'index')->middleware('can:users.view');
    Route::post('/', 'store')->middleware('can:users.create');
    Route::get('{user}', 'show')->middleware('can:users.view');
    Route::patch('{user}', 'update')->middleware('can:users.update');

    Route::post('{user}/activate', 'activate')->middleware('can:users.activate');
    Route::post('{user}/deactivate', 'deactivate')->middleware('can:users.deactivate');
    Route::post('{user}/ban', 'ban')->middleware('can:users.ban');
    Route::post('{user}/unban', 'unban')->middleware('can:users.unban');

    Route::put('{user}/account-type', 'changeAccountType')->middleware(['can:users.change-account-type', 'throttle:sensitive']);
    Route::delete('{user}/sessions', 'revokeSessions')->middleware('can:users.revoke-sessions');
    Route::put('{user}/roles', 'syncRoles')->middleware(['can:roles.assign', 'throttle:sensitive']);
});
