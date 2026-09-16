<?php

namespace Tests\Feature\Inventory;

use App\Models\Facilities\Inventory;
use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryApiTest extends TestCase
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

    private function authenticate(): void
    {
        $user = User::first();
        Sanctum::actingAs($user);
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
            'name' => 'Test User Inv ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestRoom(array $overrides = []): Room
    {
        $defaults = [
            'code' => 'RM-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Test Room ' . mt_rand(100, 999),
            'capacity' => 30,
            'status' => 'active',
        ];

        return Room::create(array_merge($defaults, $overrides));
    }

    private function createTestInventory(array $overrides = []): Inventory
    {
        $defaults = [
            'code' => 'INV-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Test Item ' . mt_rand(100, 999),
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
        DB::connection()->statement('DELETE FROM stock_movements WHERE inventory_id IN (SELECT id FROM inventories WHERE code LIKE "INV-%")');
        Inventory::where('code', 'like', 'INV-%')->forceDelete();
        Room::where('code', 'like', 'RM-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory');
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_structure(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);
        $response->assertJson([
            'success' => true,
            'message' => 'Inventory retrieved successfully',
        ]);
    }

    public function test_index_returns_items(): void
    {
        $this->authenticate();
        $this->createTestInventory();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_code(): void
    {
        $this->authenticate();
        $this->createTestInventory(['code' => 'INV-SEARCH']);
        $this->createTestInventory(['code' => 'INV-NOMATCH']);
        $response = $this->getJson('/api/inventory?search=SEARCH');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH', $item['code']);
        }
    }

    public function test_search_by_name(): void
    {
        $this->authenticate();
        $this->createTestInventory(['name' => 'Kertas A4']);
        $this->createTestInventory(['name' => 'Pulpen']);
        $response = $this->getJson('/api/inventory?search=Kertas');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Kertas', $item['name']);
        }
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory?search=NONEXISTENTXYZ');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_category(): void
    {
        $this->authenticate();
        $this->createTestInventory(['category' => 'stationery']);
        $this->createTestInventory(['category' => 'cleaning']);
        $response = $this->getJson('/api/inventory?category=stationery');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('stationery', $item['category']);
        }
    }

    public function test_filter_by_status(): void
    {
        $this->authenticate();
        $this->createTestInventory(['status' => 'active']);
        $this->createTestInventory(['status' => 'inactive']);
        $response = $this->getJson('/api/inventory?status=active');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('active', $item['status']);
        }
    }

    public function test_filter_by_room_id(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $this->createTestInventory(['room_id' => $room->id]);
        $this->createTestInventory(['room_id' => null]);
        $response = $this->getJson("/api/inventory?room_id={$room->id}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($room->id, $item['room_id']);
        }
    }

    public function test_filter_by_low_stock(): void
    {
        $this->authenticate();
        $this->createTestInventory(['quantity' => 5, 'minimum_stock' => 10]);
        $this->createTestInventory(['quantity' => 50, 'minimum_stock' => 10]);
        $response = $this->getJson('/api/inventory?low_stock=1');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertLessThanOrEqual($item['minimum_stock'], $item['quantity']);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory([
            'code' => 'INV-SHOW',
            'name' => 'Kertas A4',
            'category' => 'stationery',
            'unit' => 'rim',
            'quantity' => 100,
            'minimum_stock' => 20,
            'status' => 'active',
        ]);
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $item->id,
                'code' => 'INV-SHOW',
                'name' => 'Kertas A4',
                'category' => 'stationery',
                'unit' => 'rim',
                'quantity' => 100,
                'minimum_stock' => 20,
                'status' => 'active',
                'is_low_stock' => false,
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Inventory item not found',
            'data' => null,
        ]);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_show_includes_is_low_stock(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory(['quantity' => 5, 'minimum_stock' => 10]);
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_low_stock'));
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_admin_can_store(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-ADMIN',
            'name' => 'Admin Item',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 50,
            'minimum_stock' => 10,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_store(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-ADM',
            'name' => 'Administrator Item',
            'category' => 'cleaning',
            'unit' => 'box',
            'quantity' => 20,
            'minimum_stock' => 5,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-GURU',
            'name' => 'Guru Item',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-SIS',
            'name' => 'Siswa Item',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $this->postJson('/api/inventory', [
            'code' => 'INV-DB',
            'name' => 'DB Item',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 30,
            'minimum_stock' => 5,
            'status' => 'active',
        ])->assertStatus(201);

        $this->assertDatabaseHas('inventories', [
            'code' => 'INV-DB',
            'name' => 'DB Item',
        ]);
    }

    public function test_store_returns_data(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-RET',
            'name' => 'Return Item',
            'description' => 'Deskripsi',
            'category' => 'lab_supplies',
            'unit' => 'bottle',
            'quantity' => 25,
            'minimum_stock' => 5,
            'location' => 'Lab IPA',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('INV-RET', $data['code']);
        $this->assertEquals('Return Item', $data['name']);
        $this->assertEquals('lab_supplies', $data['category']);
        $this->assertEquals('bottle', $data['unit']);
        $this->assertEquals(25, $data['quantity']);
        $this->assertEquals(5, $data['minimum_stock']);
        $this->assertArrayHasKey('created_at', $data);
    }

    // ─── Store Validation Tests ────────────────────────────────

    public function test_store_requires_code(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'name' => 'No Code',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_requires_name(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-NN',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-NC',
            'name' => 'No Cat',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_store_requires_unit(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-NU',
            'name' => 'No Unit',
            'category' => 'stationery',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['unit']);
    }

    public function test_store_requires_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-ST',
            'name' => 'No Status',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestInventory(['code' => 'INV-DUP']);
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-DUP',
            'name' => 'Duplicate',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-IC',
            'name' => 'Invalid Cat',
            'category' => 'invalid',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-IS',
            'name' => 'Invalid Status',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-NQ',
            'name' => 'Neg Qty',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => -1,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_rejects_invalid_room_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-IR',
            'name' => 'Invalid Room',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'room_id' => 99999,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['room_id']);
    }

    public function test_store_accepts_valid_room(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-VR',
            'name' => 'Valid Room',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'room_id' => $room->id,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($room->id, $response->json('data.room_id'));
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_admin_can_update(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => $item->code,
            'name' => 'Updated',
            'category' => 'cleaning',
            'unit' => 'box',
            'quantity' => 30,
            'minimum_stock' => 5,
            'status' => 'inactive',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => $item->code,
            'name' => 'Guru Update',
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
            'name' => 'Siswa Update',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_update_changes_name(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['name' => 'Old Name']);
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => $item->code,
            'name' => 'New Name',
            'category' => $item->category,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'minimum_stock' => $item->minimum_stock,
            'status' => $item->status,
        ]);
        $response->assertStatus(200);
        $this->assertEquals('New Name', $response->json('data.name'));
    }

    public function test_patch_updates_single_field(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['name' => 'Original', 'quantity' => 50]);
        $response = $this->patchJson("/api/inventory/{$item->id}", [
            'name' => 'Patched',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('Patched', $response->json('data.name'));
        $this->assertEquals(50, $response->json('data.quantity'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/inventory/99999', [
            'code' => 'INV-404',
            'name' => 'Not Found',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_update_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $item1 = $this->createTestInventory(['code' => 'INV-U1']);
        $item2 = $this->createTestInventory(['code' => 'INV-U2']);
        $response = $this->putJson("/api/inventory/{$item2->id}", [
            'code' => 'INV-U1',
            'name' => $item2->name,
            'category' => $item2->category,
            'unit' => $item2->unit,
            'quantity' => $item2->quantity,
            'minimum_stock' => $item2->minimum_stock,
            'status' => $item2->status,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_update_same_code_to_self_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['code' => 'INV-SELF']);
        $response = $this->putJson("/api/inventory/{$item->id}", [
            'code' => 'INV-SELF',
            'name' => 'Self Update',
            'category' => $item->category,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'minimum_stock' => $item->minimum_stock,
            'status' => $item->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Destroy Tests ─────────────────────────────────────────

    public function test_admin_can_delete(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_delete(): void
    {
        $this->authenticateAsAdministrator();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $item = $this->createTestInventory();
        $response = $this->deleteJson("/api/inventory/{$item->id}");
        $response->assertStatus(403);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $itemId = $item->id;
        $this->deleteJson("/api/inventory/{$itemId}")->assertStatus(200);
        $this->assertSoftDeleted('inventories', ['id' => $itemId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/inventory/99999');
        $response->assertStatus(404);
    }

    public function test_deleted_not_in_index(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $response = $this->getJson('/api/inventory');
        foreach ($response->json('data') as $record) {
            $this->assertNotEquals($item->id, $record['id']);
        }
    }

    public function test_deleted_returns_404_on_show(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory();
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $this->getJson("/api/inventory/{$item->id}")->assertStatus(404);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $item = $this->createTestInventory(['code' => 'INV-REUSE']);
        $this->deleteJson("/api/inventory/{$item->id}")->assertStatus(200);
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-REUSE',
            'name' => 'Reused',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/inventory/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/inventory/99999', [
            'code' => 'INV-IDOR',
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

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'id' => 99999,
            'code' => 'INV-MA',
            'name' => 'MA Test',
            'category' => 'stationery',
            'unit' => 'pcs',
            'quantity' => 10,
            'minimum_stock' => 2,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-CAT',
            'name' => 'CAT Test',
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

    public function test_store_ignores_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/inventory', [
            'code' => 'INV-DAT',
            'name' => 'DAT Test',
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

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $this->createTestInventory();
        $response = $this->getJson('/api/inventory');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $item = $this->createTestInventory();
        $response = $this->getJson("/api/inventory/{$item->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_delete_preserves_other_items(): void
    {
        $this->authenticateAsAdmin();
        $item1 = $this->createTestInventory(['code' => 'INV-P1']);
        $item2 = $this->createTestInventory(['code' => 'INV-P2']);
        $this->deleteJson("/api/inventory/{$item1->id}")->assertStatus(200);
        $this->assertDatabaseHas('inventories', ['code' => 'INV-P2']);
    }
}
