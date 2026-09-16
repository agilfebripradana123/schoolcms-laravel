<?php

namespace Tests\Feature\Inventory;

use App\Models\Facilities\Inventory;
use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventorySecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

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

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User InvAudit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestInventory(array $overrides = []): Inventory
    {
        $defaults = [
            'code' => 'SEC-INV-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Test Item ' . mt_rand(100, 999),
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 50,
            'minimum_stock' => 10,
            'status' => 'active',
        ];

        return Inventory::create(array_merge($defaults, $overrides));
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM stock_movements WHERE inventory_id IN (SELECT id FROM inventories WHERE code LIKE "SEC-INV-%")');
        Inventory::where('code', 'like', 'SEC-INV-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/inventory/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/inventory', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/inventory/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/inventory/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/inventory/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-GURU',
            'name' => 'Guru',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => $item->code,
            'name' => 'Guru Updated',
            'category' => $item->category,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'minimum_stock' => $item->minimum_stock,
            'status' => $item->status,
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-SIS',
            'name' => 'Siswa',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => $item->code,
            'name' => 'Siswa Updated',
            'category' => $item->category,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'minimum_stock' => $item->minimum_stock,
            'status' => $item->status,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);

        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-ADM',
            'name' => 'Admin',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->getJson("/api/inventory/{$id}");
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/inventory/{$id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);

        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-ADM2',
            'name' => 'Admin2',
            'category' => 'cleaning',
            'unit' => 'box',
            'quantity' => 5,
            'minimum_stock' => 1,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->deleteJson("/api/inventory/{$id}");
        $response->assertStatus(200);
    }

    // ─── Duplicate & Integrity Tests ───────────────────────────

    public function test_cannot_create_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestInventory(['code' => 'SEC-INV-DUP']);
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-DUP',
            'name' => 'Duplicate',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['code' => 'SEC-INV-RUSE']);
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-RUSE',
            'name' => 'Reused',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── Soft-Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $dbItem = Inventory::withTrashed()->find($item->id);
        $this->assertNotNull($dbItem->deleted_at);
    }

    public function test_soft_deleted_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $response = $this->getJson('/api/inventory');
        foreach ($response->json('data') as $record) {
            $this->assertNotEquals($item->id, $record['id']);
        }
    }

    public function test_soft_deleted_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $this->getJson("/api/inventory/{$item->id}")->assertStatus(404);
    }

    public function test_soft_deleted_preserves_data(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['code' => 'SEC-INV-PRES', 'name' => 'Preserved']);
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $dbItem = Inventory::withTrashed()->find($item->id);
        $this->assertEquals('SEC-INV-PRES', $dbItem->code);
        $this->assertEquals('Preserved', $dbItem->name);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'id' => 99999,
            'code' => 'SEC-INV-MAI',
            'name' => 'MAI',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-MAC',
            'name' => 'MAC',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_deleted_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-MAD',
            'name' => 'MAD',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Inventory::find($response->json('data.id'))->deleted_at);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-IT',
            'name' => 'Invalid',
            'category' => 'invalid',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-IS',
            'name' => 'Invalid',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->putJson("/api/inventory/{$item->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('inventories', ['id' => $item->id]);
    }

    public function test_store_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'SEC-INV-NQ',
            'name' => 'Neg',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => -1,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/inventory/99999', [
            'code' => 'SEC-INV-IDOR',
            'name' => 'IDOR',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/inventory/99999');
        $response->assertStatus(404);
    }

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_category_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?category=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_room_id_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory?room_id=99999');
        $response->assertStatus(422);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestInventory();
        $response = $this->getJson('/api/inventory');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_no_password_fields_exposed(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestInventory();
        $this->createTestInventory();
        $response = $this->getJson('/api/inventory');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── forceDelete Protection Tests ──────────────────────────

    public function test_destroy_uses_soft_delete_not_force(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $itemId = $item->id;
        $this->deleteJson("/api/inventory/{$itemId}")->assertStatus(200);
        $this->assertDatabaseHas('inventories', ['id' => $itemId]);
        $dbItem = Inventory::withTrashed()->find($itemId);
        $this->assertNotNull($dbItem->deleted_at);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_unchanged_after_read_operations(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = Inventory::count();
        $this->getJson('/api/inventory');
        $this->createTestInventory();
        $this->getJson('/api/inventory');
        $afterCount = Inventory::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM inventories');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('code', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('category', $columnNames);
        $this->assertContains('unit', $columnNames);
        $this->assertContains('quantity', $columnNames);
        $this->assertContains('minimum_stock', $columnNames);
        $this->assertContains('location', $columnNames);
        $this->assertContains('room_id', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
        $this->assertCount(14, $columns);
    }
}
