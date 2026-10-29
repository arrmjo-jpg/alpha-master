<?php

use App\Modules\AccessControl\Http\Controllers\AuditLogController;
use App\Modules\AccessControl\Http\Controllers\PermissionController;
use App\Modules\AccessControl\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Admin "access" area. The `admin` group = authentication, usable account,
// admin account, admin client; each route then requires its own permission.
Route::prefix('admin/access')->middleware('admin')->group(function () {
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('can:permissions.view');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('can:roles.view');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete');
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('can:roles.assign-permissions');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');
});
