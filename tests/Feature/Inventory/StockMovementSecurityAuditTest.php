<?php

namespace Tests\Feature\Inventory;

use App\Models\Facilities\Inventory;
use App\Models\System\Role;
use App\Models\Facilities\StockMovement;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockMovementSecurityAuditTest extends TestCase
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
            'name' => 'Test User SMAudit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestInventory(array $overrides = []): Inventory
    {
        $defaults = [
            'code' => 'SEC-STK-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Stock Item ' . mt_rand(100, 999),
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
        DB::connection()->statement('DELETE FROM stock_movements WHERE inventory_id IN (SELECT id FROM inventories WHERE code LIKE "SEC-STK-%")');
        Inventory::where('code', 'like', 'SEC-STK-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_stock_in_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_stock_out_returns_401(): void
    {
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 10]);
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
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_stock_out(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 10]);
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
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_stock_out(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 10]);
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

    // ─── Stock Integrity Tests ─────────────────────────────────

    public function test_stock_never_goes_negative(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 5]);
        $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 5]);
        $item->refresh();
        $this->assertEquals(0, $item->quantity);
    }

    public function test_stock_out_exceeding_stock_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 5]);
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 6]);
        $response->assertStatus(422);
        $item->refresh();
        $this->assertEquals(5, $item->quantity);
    }

    public function test_adjustment_decrease_exceeding_stock_rejected(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 5]);
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", [
            'quantity' => 6,
            'adjustment_type' => 'decrease',
            'notes' => 'Test',
        ]);
        $response->assertStatus(422);
        $item->refresh();
        $this->assertEquals(5, $item->quantity);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_stock_in_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/stock-in', ['quantity' => 10]);
        $response->assertStatus(404);
    }

    public function test_stock_out_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/stock-out', ['quantity' => 10]);
        $response->assertStatus(404);
    }

    public function test_adjustment_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory/99999/adjustment', [
            'quantity' => 10,
            'adjustment_type' => 'increase',
            'notes' => 'Test',
        ]);
        $response->assertStatus(404);
    }

    public function test_movements_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/inventory/99999/movements');
        $response->assertStatus(404);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_stock_in_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", []);
        $response->assertStatus(422);
    }

    public function test_stock_out_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", []);
        $response->assertStatus(422);
    }

    public function test_adjustment_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/adjustment", []);
        $response->assertStatus(422);
    }

    public function test_stock_in_rejects_zero_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 0]);
        $response->assertStatus(422);
    }

    public function test_stock_out_rejects_zero_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 0]);
        $response->assertStatus(422);
    }

    public function test_stock_in_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => -1]);
        $response->assertStatus(422);
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

    // ─── Soft Delete Integration Tests ─────────────────────────

    public function test_soft_deleted_inventory_stock_in_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $item->delete();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $response->assertStatus(404);
    }

    public function test_soft_deleted_inventory_stock_out_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $item->delete();
        $response = $this->postJson("/api/inventory/{$item->id}/stock-out", ['quantity' => 10]);
        $response->assertStatus(404);
    }

    public function test_soft_deleted_inventory_adjustment_returns_404(): void
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

    public function test_soft_deleted_inventory_movements_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $item->delete();
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        $response->assertStatus(404);
    }

    public function test_stock_movements_preserved_after_soft_delete(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $count = StockMovement::where('inventory_id', $item->id)->count();
        $item->delete();
        $afterCount = StockMovement::where('inventory_id', $item->id)->count();
        $this->assertEquals($count, $afterCount);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_movement_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $response = $this->getJson("/api/inventory/{$item->id}/movements");
        foreach ($response->json('data') as $movement) {
            $this->assertArrayNotHasKey('deleted_at', $movement);
        }
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_stock_movements_unchanged_after_read(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->postJson("/api/inventory/{$item->id}/stock-in", ['quantity' => 10]);
        $beforeCount = StockMovement::where('inventory_id', $item->id)->count();
        $this->getJson("/api/inventory/{$item->id}/movements");
        $afterCount = StockMovement::where('inventory_id', $item->id)->count();
        $this->assertEquals($beforeCount, $afterCount);
    }

    public function test_inventory_quantity_unchanged_after_read_movements(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['quantity' => 50]);
        $this->getJson("/api/inventory/{$item->id}/movements");
        $item->refresh();
        $this->assertEquals(50, $item->quantity);
    }
}
