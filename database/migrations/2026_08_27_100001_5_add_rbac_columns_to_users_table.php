<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE RBAC FOUNDATION (Phase A) — add the RBAC/lifecycle columns the
 * `App\Models\System\User` model relies on but the stock users migration does
 * not create:
 *
 *   role_id, username, photo, is_active, deleted_at
 *
 * Schema mirrors the production database (schoolcms_db.sql):
 *   role_id    INT UNSIGNED NOT NULL, FK -> roles.id ON UPDATE CASCADE
 *   username   VARCHAR(100) NULL UNIQUE
 *   photo      VARCHAR(255) NULL
 *   is_active  TINYINT(1) NOT NULL DEFAULT 1
 *   deleted_at DATETIME NULL (SoftDeletes)
 *
 * Timestamp placed after the `roles` migration (100000) and after the
 * permission_role migration is irrelevant to this FK, but before the module
 * migrations that create users. Each column is guarded with hasColumn() so the
 * migration is a no-op on an existing database that already has them.
 *
 * No data is inserted or backfilled here — Phase A is schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('role_id')->after('id');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->foreign('role_id')
                    ->references('id')
                    ->on('roles')
                    ->cascadeOnUpdate();
            });
        }

        if (!Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 100)->nullable()->unique()->after('name');
            });
        }

        if (!Schema::hasColumn('users', 'photo')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('photo', 255)->nullable()->after('password');
            });
        }

        if (!Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('photo');
            });
        }

        if (!Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['role_id']);
            });
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role_id');
            });
        }

        foreach (['username', 'photo', 'is_active', 'deleted_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
