<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table(config('permission.table_names.permissions'), function (Blueprint $table) {
            $table->foreignId('permission_group_id')->nullable()->after('guard_name')
                ->constrained('permission_groups')->restrictOnDelete();
            $table->json('display_name')->nullable()->after('permission_group_id');
            $table->json('description')->nullable()->after('display_name');
            $table->boolean('is_sensitive')->default(false)->after('description');
            $table->boolean('is_deprecated')->default(false)->after('is_sensitive');
        });

        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->json('display_name')->nullable()->after('guard_name');
            $table->json('description')->nullable()->after('display_name');
            $table->boolean('is_protected')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn(['display_name', 'description', 'is_protected']);
        });

        Schema::table(config('permission.table_names.permissions'), function (Blueprint $table) {
            $table->dropConstrainedForeignId('permission_group_id');
            $table->dropColumn(['display_name', 'description', 'is_sensitive', 'is_deprecated']);
        });

        Schema::dropIfExists('permission_groups');
    }
};
