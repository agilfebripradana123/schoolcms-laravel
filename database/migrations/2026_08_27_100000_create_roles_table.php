<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE RBAC FOUNDATION (Phase A) — create the `roles` table.
 *
 * Timestamp is intentionally placed BEFORE
 * 2026_08_27_100001_create_permissions_tables.php, which adds
 * `permission_role.role_id` FOREIGN KEY -> `roles.id`. Without this ordering a
 * fresh `php artisan migrate` fails with "Failed to open the referenced table
 * 'roles'".
 *
 * Schema mirrors the production database (schoolcms_db.sql):
 *   id INT UNSIGNED, name VARCHAR(50) NOT NULL UNIQUE,
 *   description VARCHAR(255) NULL, created_at/updated_at.
 *
 * Guarded with hasTable() so it is a no-op on an existing database that
 * already has `roles` (live/dump). Data seeding is handled in Phase B
 * (RoleSeeder) and is intentionally NOT done here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            return;
        }

        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
