<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->after('name');
            $table->string('account_type', 16)->default('user')->after('password')->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('banned_at')->nullable();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ban_reason')->nullable();
            $table->string('preferred_locale', 8)->nullable();
        });

        // Backfill usernames for any pre-existing rows before enforcing NOT NULL + UNIQUE.
        DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['username' => 'user'.$user->id]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropConstrainedForeignId('banned_by');
            $table->dropIndex(['account_type']);
            $table->dropColumn(['username', 'account_type', 'deactivated_at', 'banned_at', 'ban_reason', 'preferred_locale']);
        });
    }
};
