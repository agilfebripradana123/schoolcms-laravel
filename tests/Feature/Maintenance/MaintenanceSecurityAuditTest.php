<?php

namespace Tests\Feature\Maintenance;

use App\Models\Facilities\Asset;
use App\Models\Facilities\Maintenance;
use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceSecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestMaintenance();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestMaintenance();
        parent::tearDown();
    }

    // ─── Auth Helpers ──────────────────────────────────────────

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsAdministrator(): void
    {
        $adminRole = Role::where('name', 'Administrator')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsSiswa(): void
    {
        $siswaRole = Role::where('name', 'Siswa')->first();
        $user = $this->createTestUser($siswaRole->id, 'siswa');
        Sanctum::actingAs($user);
    }

    // ─── Data Helpers ──────────────────────────────────────────

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User Maint Audit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestAsset(array $overrides = []): Asset
    {
        $defaults = [
            'code' => 'AST-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Test Asset ' . mt_rand(100, 999),
            'category' => 'electronics',
            'quantity' => 5,
            'condition' => 'good',
            'status' => 'active',
        ];

        return Asset::create(array_merge($defaults, $overrides));
    }

    private function createTestRoom(array $overrides = []): Room
    {
        $defaults = [
            'code' => 'RM-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Test Room ' . mt_rand(100, 999),
            'capacity' => 30,
            'status' => 'active',
        ];

        return Room::create(array_merge($defaults, $overrides));
    }

    private function createTestMaintenance(array $overrides = []): Maintenance
    {
        $defaults = [
            'code' => 'SEC-MNT-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'title' => 'Security Test Maintenance ' . mt_rand(100, 999),
            'description' => 'Security test description',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ];

        return Maintenance::create(array_merge($defaults, $overrides));
    }

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestMaintenance(): void
    {
        Maintenance::where('code', 'like', 'SEC-MNT-%')->forceDelete();
        Asset::where('code', 'like', 'AST-%')->forceDelete();
        Room::where('code', 'like', 'RM-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/maintenance/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/maintenance', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/maintenance/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/maintenance/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/maintenance/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-GURU',
            'title' => 'Guru',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Guru Updated',
            'maintenance_type' => $record->maintenance_type,
            'priority' => $record->priority,
            'status' => 'in_progress',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'title' => 'Guru Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-SIS',
            'title' => 'Siswa',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Siswa Updated',
            'maintenance_type' => $record->maintenance_type,
            'priority' => $record->priority,
            'status' => 'in_progress',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'title' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);

        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-ADM',
            'title' => 'Admin',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->getJson("/api/maintenance/{$id}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/maintenance/{$id}", [
            'code' => 'SEC-MNT-ADM',
            'title' => 'Admin Updated',
            'maintenance_type' => 'preventive',
            'priority' => 'high',
            'status' => 'in_progress',
        ]);
        $response->assertStatus(200);

        $response = $this->patchJson("/api/maintenance/{$id}", [
            'title' => 'Admin Patched',
        ]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/maintenance/{$id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);

        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-ADM2',
            'title' => 'Admin2',
            'maintenance_type' => 'inspection',
            'priority' => 'low',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->getJson("/api/maintenance/{$id}");
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/maintenance/{$id}");
        $response->assertStatus(200);
    }

    // ─── Duplicate & Integrity Tests ───────────────────────────

    public function test_cannot_create_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestMaintenance(['code' => 'SEC-MNT-DUP']);
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-DUP',
            'title' => 'Duplicate',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['code' => 'SEC-MNT-RUSE']);
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);

        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-RUSE',
            'title' => 'Reused',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
    }

    public function test_update_same_code_to_self_allowed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['code' => 'SEC-MNT-SELF']);
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => 'SEC-MNT-SELF',
            'title' => 'Self Update',
            'maintenance_type' => $record->maintenance_type,
            'priority' => $record->priority,
            'status' => $record->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Soft-Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);

        $dbRecord = Maintenance::withTrashed()->find($record->id);
        $this->assertNotNull($dbRecord->deleted_at);
    }

    public function test_soft_deleted_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);

        $response = $this->getJson('/api/maintenance');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($record->id, $item['id']);
        }
    }

    public function test_soft_deleted_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);

        $this->getJson("/api/maintenance/{$record->id}")->assertStatus(404);
    }

    public function test_soft_deleted_preserves_data(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['code' => 'SEC-MNT-PRES', 'title' => 'Preserved']);
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);

        $dbRecord = Maintenance::withTrashed()->find($record->id);
        $this->assertEquals('SEC-MNT-PRES', $dbRecord->code);
        $this->assertEquals('Preserved', $dbRecord->title);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'id' => 99999,
            'code' => 'SEC-MNT-MAI',
            'title' => 'MAI',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-MAC',
            'title' => 'MAC',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_updated_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-MAU',
            'title' => 'MAU',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'updated_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.updated_at'));
    }

    public function test_store_ignores_deleted_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-MAD',
            'title' => 'MAD',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Maintenance::find($response->json('data.id'))->deleted_at);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_invalid_maintenance_type(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-IT',
            'title' => 'Invalid Type',
            'maintenance_type' => 'invalid',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_priority(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-IP',
            'title' => 'Invalid Priority',
            'maintenance_type' => 'corrective',
            'priority' => 'invalid',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-IS',
            'title' => 'Invalid Status',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('maintenance', ['id' => $record->id]);
    }

    public function test_store_rejects_negative_estimated_cost(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-NEC',
            'title' => 'Neg Cost',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'estimated_cost' => -1000,
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_negative_actual_cost(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'SEC-MNT-NAC',
            'title' => 'Neg Actual Cost',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'actual_cost' => -500,
        ]);
        $response->assertStatus(422);
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/maintenance/99999', [
            'code' => 'SEC-MNT-IDOR',
            'title' => 'IDOR',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/maintenance/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_priority_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?priority=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_maintenance_type_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?maintenance_type=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_asset_id_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?asset_id=99999');
        $response->assertStatus(422);
    }

    public function test_invalid_room_id_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/maintenance?room_id=99999');
        $response->assertStatus(422);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestMaintenance();
        $response = $this->getJson('/api/maintenance');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_no_password_fields_exposed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestMaintenance();
        $this->createTestMaintenance();
        $response = $this->getJson('/api/maintenance');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── forceDelete Protection Tests ──────────────────────────

    public function test_destroy_uses_soft_delete_not_force(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $recordId = $record->id;
        $this->deleteJson("/api/maintenance/{$recordId}")->assertStatus(200);
        $this->assertDatabaseHas('maintenance', ['id' => $recordId]);
        $dbRecord = Maintenance::withTrashed()->find($recordId);
        $this->assertNotNull($dbRecord->deleted_at);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_unchanged_after_read_operations(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = Maintenance::count();
        $this->getJson('/api/maintenance');
        $this->createTestMaintenance();
        $this->getJson('/api/maintenance');
        $afterCount = Maintenance::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM maintenance');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('code', $columnNames);
        $this->assertContains('title', $columnNames);
        $this->assertContains('description', $columnNames);
        $this->assertContains('asset_id', $columnNames);
        $this->assertContains('room_id', $columnNames);
        $this->assertContains('reported_by', $columnNames);
        $this->assertContains('maintenance_type', $columnNames);
        $this->assertContains('priority', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('scheduled_date', $columnNames);
        $this->assertContains('started_date', $columnNames);
        $this->assertContains('completed_date', $columnNames);
        $this->assertContains('estimated_cost', $columnNames);
        $this->assertContains('actual_cost', $columnNames);
        $this->assertContains('notes', $columnNames);
        $this->assertContains('resolution', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
        $this->assertCount(20, $columns);
    }
}
