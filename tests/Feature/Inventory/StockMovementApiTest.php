<?php

namespace Tests\Feature\Inventory;

use App\Models\Facilities\Inventory;
use App\Models\System\Role;
use App\Models\Facilities\StockMovement;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
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
            'name' => 'Test User Stock ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestInventory(array $overrides = []): Inventory
    {
        $defaults = [
            'code' => 'STK-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Stock Test Item ' . mt_rand(100, 999),
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
        DB::connection()->statement('DELETE FROM stock_movements WHERE inventory_id IN (SELECT id FROM inventories WHERE code LIKE "STK-%")');
        Inventory::where('code', 'like', 'STK-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_stock_in_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 10,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_stock_out_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 10,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_adjustment_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 10,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_movements_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_movements(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_movements(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_stock_in(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 10,
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_stock_out(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 10,
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_adjustment(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 10,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_stock_in(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 10,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_stock_out(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 10,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_adjustment(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 10,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(403);
    }

    // ─── Stock In Tests ────────────────────────────────────────

    public function test_admin_can_stock_in(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 20,
            'notes' => 'Pembelian',
            'created_by' => 'Pak Budi',
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(70, $item->quantity);
    }

    public function test_administrator_can_stock_in(): void
    {
        $this->authenticateAsAdministrator();
        $item = $this->createTestInventory(['quantity' => 50]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 30,
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(80, $item->quantity);
    }

    public function test_stock_in_creates_movement(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 10,
            'notes' => 'Test',
            'created_by' => 'Admin',
        ])->assertStatus(201);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $item->id,
            'type' => 'stock_in',
            'quantity' => 10,
            'notes' => 'Test',
            'created_by' => 'Admin',
        ]);
    }

    public function test_stock_in_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/stock-in', [
            'quantity' => 10,
        ]);
        $response->assertStatus(404);
    }

    public function test_stock_in_requires_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_stock_in_rejects_zero_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 0,
        ]);
        $response->assertStatus(422);
    }

    public function test_stock_in_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => -1,
        ]);
        $response->assertStatus(422);
    }

    // ─── Stock Out Tests ───────────────────────────────────────

    public function test_admin_can_stock_out(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 20,
            'notes' => 'Pemakaian',
            'created_by' => 'Guru Kimia',
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(30, $item->quantity);
    }

    public function test_stock_out_creates_movement(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 15,
            'notes' => 'Pemakaian lab',
        ])->assertStatus(201);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 15,
            'notes' => 'Pemakaian lab',
        ]);
    }

    public function test_stock_out_insufficient_stock_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 10]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 11,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_stock_out_exact_stock_allowed(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 10]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 10,
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(0, $item->quantity);
    }

    public function test_stock_out_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/stock-out', [
            'quantity' => 10,
        ]);
        $response->assertStatus(404);
    }

    public function test_stock_out_rejects_zero_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 0,
        ]);
        $response->assertStatus(422);
    }

    // ─── Adjustment Tests ──────────────────────────────────────

    public function test_admin_can_adjustment_increase(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'adjustment_type' => 'increase',
            'notes' => 'Penambahan stok',
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(55, $item->quantity);
    }

    public function test_admin_can_adjustment_decrease(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'adjustment_type' => 'decrease',
            'notes' => 'Pengurangan stok',
        ]);
        $response->assertStatus(201);

        $item->refresh();
        $this->assertEquals(45, $item->quantity);
    }

    public function test_adjustment_decrease_insufficient_stock_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 10]);
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 11,
            'adjustment_type' => 'decrease',
            'notes' => 'Too much',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_adjustment_creates_movement(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'adjustment_type' => 'increase',
            'notes' => 'Test adjustment',
            'created_by' => 'Admin',
        ])->assertStatus(201);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $item->id,
            'type' => 'adjustment',
            'adjustment_type' => 'increase',
            'quantity' => 5,
            'notes' => 'Test adjustment',
            'created_by' => 'Admin',
        ]);
    }

    public function test_adjustment_requires_notes(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'adjustment_type' => 'increase',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }

    public function test_adjustment_requires_adjustment_type(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'notes' => 'Test',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['adjustment_type']);
    }

    public function test_adjustment_rejects_invalid_type(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 5,
            'adjustment_type' => 'invalid',
            'notes' => 'Test',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['adjustment_type']);
    }

    public function test_adjustment_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/adjustment', [
            'quantity' => 5,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(404);
    }

    // ─── Movement History Tests ────────────────────────────────

    public function test_movements_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(200);
    }

    public function test_movements_response_structure(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta',
        ]);
    }

    public function test_movements_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory/99999/movements');
        $response->assertStatus(404);
    }

    public function test_movements_pagination(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 5]);

        $response = $this->getJson("/api/inventory/{$item->id}/movements?per_page=1");
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['per_page']);
        $this->assertEquals(2, $meta['total']);
    }

    // ─── Soft Delete Integration Tests ─────────────────────────

    public function test_soft_deleted_inventory_stock_in_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $item->delete();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", [
            'quantity' => 10,
        ]);
        $response->assertStatus(404);
    }

    public function test_soft_deleted_inventory_stock_out_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $item->delete();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", [
            'quantity' => 10,
        ]);
        $response->assertStatus(404);
    }

    public function test_soft_deleted_inventory_adjustment_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $item->delete();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 10,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(404);
    }

    public function test_soft_deleted_inventory_movements_not_accessible(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $item->delete();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(404);
    }

    public function test_stock_movements_preserved_after_inventory_soft_delete(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $movementCount = StockMovement::where('inventory_id', $item->id)->count();
        $item->delete();
        $afterCount = StockMovement::where('inventory_id', $item->id)->count();
        $this->assertEquals($movementCount, $afterCount);
    }

    // ─── Quantity Synchronization Tests ────────────────────────

    public function test_quantity_synchronized_after_stock_in(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 100]);
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 50]);
        $item->refresh();
        $this->assertEquals(150, $item->quantity);
    }

    public function test_quantity_synchronized_after_stock_out(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 100]);
        $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 30]);
        $item->refresh();
        $this->assertEquals(70, $item->quantity);
    }

    public function test_quantity_synchronized_after_adjustment_increase(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 100]);
        $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 25,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $item->refresh();
        $this->assertEquals(125, $item->quantity);
    }

    public function test_quantity_synchronized_after_adjustment_decrease(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 100]);
        $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 25,
            'adjustment_type' => 'decrease',
            'notes' => 'Test',
        ]);
        $item->refresh();
        $this->assertEquals(75, $item->quantity);
    }
}
