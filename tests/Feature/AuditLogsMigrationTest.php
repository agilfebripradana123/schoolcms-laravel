<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B19 Stage D — validates the guarded audit_logs provisioning migration.
 *
 * Runs only the single migration against the configured database (no full
 * migrate), leaves no table behind, and never touches a real production DB.
 */
class AuditLogsMigrationTest extends TestCase
{
    private const MIGRATION_PATH = 'database/migrations/2026_11_02_000001_create_audit_logs_table.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        parent::tearDown();
    }

    private function runMigration(): void
    {
        $this->artisan('migrate', [
            '--path' => self::MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_fresh_migration_creates_audit_logs_with_production_constraints(): void
    {
        Schema::dropIfExists('audit_logs');
        $this->runMigration();

        $this->assertTrue(Schema::hasTable('audit_logs'));

        $columns = collect(DB::select('PRAGMA table_info(audit_logs)'))
            ->keyBy('name')
            ->map(fn ($c) => (object) ['type' => $c->type, 'notnull' => (bool) $c->notnull, 'pk' => (bool) $c->pk]);

        $names = $columns->keys()->all();
        foreach (['id', 'user_id', 'action', 'model', 'model_id', 'description', 'ip_address', 'user_agent', 'created_at'] as $required) {
            $this->assertContains($required, $names, "column $required must exist");
        }
        $this->assertNotContains('updated_at', $names, 'legacy schema has no updated_at');

        $this->assertTrue($columns['id']->pk, 'id must be primary key');
        $this->assertFalse($columns['user_id']->notnull, 'user_id nullable');
        $this->assertTrue($columns['action']->notnull, 'action NOT NULL');
        $this->assertFalse($columns['model']->notnull, 'model nullable');
        $this->assertFalse($columns['model_id']->notnull, 'model_id nullable');
        $this->assertTrue($columns['description']->notnull, 'description NOT NULL');
        $this->assertTrue($columns['ip_address']->notnull, 'ip_address NOT NULL');
        $this->assertFalse($columns['user_agent']->notnull, 'user_agent nullable');
        $this->assertFalse($columns['created_at']->notnull, 'created_at nullable');
    }

    public function test_migration_is_guarded_when_table_already_exists(): void
    {
        // Simulate the existing legacy table: only an id column.
        Schema::create('audit_logs', fn ($t) => $t->id());

        $this->runMigration();

        $names = collect(DB::select('PRAGMA table_info(audit_logs)'))->pluck('name')->all();
        $this->assertSame(['id'], $names, 'existing table must be left untouched (no columns added)');
    }
}