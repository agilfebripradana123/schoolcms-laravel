<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PHASE RBAC FOUNDATION (Phase A) — schema reproducibility.
 *
 * Runs the connection-agnostic RBAC foundation migrations against the test
 * sqlite :memory: database and asserts the resulting schema:
 *   - roles table exists
 *   - users has role_id / username / photo / is_active / deleted_at
 *   - users.role_id has a foreign key to roles.id
 *
 * The permission_role / permission_user migrations are MySQL-only (raw SQL on
 * the mysql connection) and cannot run here; those are verified separately by
 * running the isolated chain on a disposable MySQL database.
 */
class RbacSchemaFoundationTest extends TestCase
{
    private const USER_RBAC_COLUMNS = [
        'role_id',
        'username',
        'photo',
        'is_active',
        'deleted_at',
    ];

    private function runMigration(string $file): void
    {
        $migration = require database_path('migrations/' . $file);
        $migration->up();
    }

    private function buildFoundationSchema(): void
    {
        $this->runMigration('0001_01_01_000000_create_users_table.php');
        $this->runMigration('2026_08_27_100000_create_roles_table.php');
        $this->runMigration('2026_08_27_100001_5_add_rbac_columns_to_users_table.php');
    }

    public function test_foundation_schema_creates_roles_and_user_rbac_columns(): void
    {
        $this->buildFoundationSchema();

        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasColumn('roles', 'name'));
        $this->assertTrue(Schema::hasColumn('roles', 'description'));

        foreach (self::USER_RBAC_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "users.{$column} was not created by the RBAC foundation migration.",
            );
        }
    }

    public function test_users_role_id_has_foreign_key_to_roles(): void
    {
        $this->buildFoundationSchema();

        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(users)'));
        $roleFk = $foreignKeys->firstWhere('from', 'role_id');

        $this->assertNotNull($roleFk, 'users.role_id foreign key is missing.');
        $this->assertSame('roles', $roleFk->table);
        $this->assertSame('id', $roleFk->to);
    }

    public function test_foundation_migrations_are_idempotent_when_objects_already_exist(): void
    {
        $this->buildFoundationSchema();

        // Re-running must be a no-op thanks to hasTable()/hasColumn() guards
        // (this mirrors running the migrations against an existing live DB).
        $this->runMigration('2026_08_27_100000_create_roles_table.php');
        $this->runMigration('2026_08_27_100001_5_add_rbac_columns_to_users_table.php');

        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasColumn('users', 'role_id'));
    }
}
