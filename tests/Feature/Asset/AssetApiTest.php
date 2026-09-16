<?php

namespace Tests\Feature\Asset;

use App\Models\Facilities\Asset;
use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestAssets();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestAssets();
        parent::tearDown();
    }

    // ─── Auth Helpers ──────────────────────────────────────────

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

    // ─── Data Helpers ──────────────────────────────────────────

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User Asset ' . $prefix,
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
            'location' => 'Building A',
            'has_computer' => false,
            'status' => 'active',
        ];

        return Room::create(array_merge($defaults, $overrides));
    }

    private function createTestAsset(array $overrides = []): Asset
    {
        $defaults = [
            'code' => 'AST-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Test Asset ' . mt_rand(100, 999),
            'description' => 'Test description',
            'category' => 'electronics',
            'quantity' => 5,
            'condition' => 'good',
            'location' => 'Lab A',
            'room_id' => null,
            'purchase_date' => '2025-01-15',
            'purchase_price' => 1500000.00,
            'status' => 'active',
        ];

        return Asset::create(array_merge($defaults, $overrides));
    }

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestAssets(): void
    {
        Asset::where('code', 'like', 'AST-%')->forceDelete();
        Room::where('code', 'like', 'RM-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/assets');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets');
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_structure(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets');
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
            'message' => 'Assets retrieved successfully',
        ]);
    }

    public function test_index_returns_assets(): void
    {
        $this->authenticate();
        $this->createTestAsset();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets?page=2&per_page=1');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['current_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_code(): void
    {
        $this->authenticate();
        $this->createTestAsset(['code' => 'AST-SEARCH-A']);
        $this->createTestAsset(['code' => 'AST-NOMATCH-B']);
        $response = $this->getJson('/api/assets?search=SEARCH-A');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH-A', $item['code']);
        }
    }

    public function test_search_by_name(): void
    {
        $this->authenticate();
        $this->createTestAsset(['name' => 'Proyektor Epson']);
        $this->createTestAsset(['name' => 'Meja Guru']);
        $response = $this->getJson('/api/assets?search=Proyektor');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Proyektor', $item['name']);
        }
    }

    public function test_search_by_code_and_name_combined(): void
    {
        $this->authenticate();
        $this->createTestAsset(['code' => 'AST-SPEC01', 'name' => 'Laptop Asus']);
        $this->createTestAsset(['code' => 'AST-NOM01', 'name' => 'Kursi Murid']);
        $response = $this->getJson('/api/assets?search=SPEC');
        $response->assertStatus(200);
        $found = false;
        foreach ($response->json('data') as $item) {
            if (str_contains($item['code'], 'SPEC') || str_contains($item['name'], 'SPEC')) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets?search=NONEXISTENTXYZ');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_category(): void
    {
        $this->authenticate();
        $this->createTestAsset(['category' => 'electronics']);
        $this->createTestAsset(['category' => 'furniture']);
        $response = $this->getJson('/api/assets?category=electronics');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('electronics', $item['category']);
        }
    }

    public function test_filter_by_condition(): void
    {
        $this->authenticate();
        $this->createTestAsset(['condition' => 'good']);
        $this->createTestAsset(['condition' => 'damaged']);
        $response = $this->getJson('/api/assets?condition=good');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('good', $item['condition']);
        }
    }

    public function test_filter_by_status_active(): void
    {
        $this->authenticate();
        $this->createTestAsset(['status' => 'active']);
        $this->createTestAsset(['status' => 'inactive']);
        $response = $this->getJson('/api/assets?status=active');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('active', $item['status']);
        }
    }

    public function test_filter_by_status_inactive(): void
    {
        $this->authenticate();
        $this->createTestAsset(['status' => 'inactive']);
        $response = $this->getJson('/api/assets?status=inactive');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('inactive', $item['status']);
        }
    }

    public function test_filter_by_room_id(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $this->createTestAsset(['room_id' => $room->id]);
        $this->createTestAsset(['room_id' => null]);
        $response = $this->getJson("/api/assets?room_id={$room->id}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($room->id, $item['room_id']);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset([
            'code' => 'AST-SHOW',
            'name' => 'Laptop Show',
            'description' => 'Laptop untuk presentasi',
            'category' => 'electronics',
            'quantity' => 10,
            'condition' => 'good',
            'location' => 'Lab Komputer',
            'purchase_date' => '2025-06-15',
            'purchase_price' => 8500000.00,
            'status' => 'active',
        ]);
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $asset->id,
                'code' => 'AST-SHOW',
                'name' => 'Laptop Show',
                'description' => 'Laptop untuk presentasi',
                'category' => 'electronics',
                'quantity' => 10,
                'condition' => 'good',
                'location' => 'Lab Komputer',
                'purchase_date' => '2025-06-15',
                'purchase_price' => '8500000.00',
                'status' => 'active',
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Asset not found',
            'data' => null,
        ]);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_admin_can_store_asset(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-ADMIN',
            'name' => 'Admin Asset',
            'category' => 'electronics',
            'quantity' => 5,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_store_asset(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-ADM',
            'name' => 'Administrator Asset',
            'category' => 'furniture',
            'quantity' => 3,
            'condition' => 'fair',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_store_asset(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-GURU',
            'name' => 'Guru Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store_asset(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-SISWA',
            'name' => 'Siswa Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_store_returns_created_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-CR',
            'name' => 'Created Asset',
            'category' => 'office',
            'quantity' => 2,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $this->postJson('/api/assets', [
            'code' => 'AST-DB',
            'name' => 'DB Asset',
            'category' => 'sports',
            'quantity' => 10,
            'condition' => 'good',
            'status' => 'active',
        ])->assertStatus(201);

        $this->assertDatabaseHas('assets', [
            'code' => 'AST-DB',
            'name' => 'DB Asset',
        ]);
    }

    public function test_store_returns_data(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-RET',
            'name' => 'Return Asset',
            'description' => 'Deskripsi',
            'category' => 'lab_equipment',
            'quantity' => 7,
            'condition' => 'fair',
            'location' => 'Lab IPA',
            'purchase_date' => '2025-03-20',
            'purchase_price' => 2500000.00,
            'status' => 'inactive',
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('AST-RET', $data['code']);
        $this->assertEquals('Return Asset', $data['name']);
        $this->assertEquals('Deskripsi', $data['description']);
        $this->assertEquals('lab_equipment', $data['category']);
        $this->assertEquals(7, $data['quantity']);
        $this->assertEquals('fair', $data['condition']);
        $this->assertEquals('Lab IPA', $data['location']);
        $this->assertEquals('2025-03-20', $data['purchase_date']);
        $this->assertEquals('2500000.00', $data['purchase_price']);
        $this->assertEquals('inactive', $data['status']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    // ─── Store Validation Tests ────────────────────────────────

    public function test_store_requires_code(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'name' => 'No Code Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_requires_name(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NN',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NC',
            'name' => 'No Category',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_store_requires_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NQ',
            'name' => 'No Quantity',
            'category' => 'electronics',
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_requires_condition(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NCD',
            'name' => 'No Condition',
            'category' => 'electronics',
            'quantity' => 1,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['condition']);
    }

    public function test_store_requires_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NST',
            'name' => 'No Status',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAsset(['code' => 'AST-DUP']);
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-DUP',
            'name' => 'Duplicate Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-IC',
            'name' => 'Invalid Category',
            'category' => 'invalid_category',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_store_rejects_invalid_condition(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-ICD',
            'name' => 'Invalid Condition',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'invalid',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['condition']);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-IS',
            'name' => 'Invalid Status',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_quantity_below_minimum(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-QMIN',
            'name' => 'Min Quantity',
            'category' => 'electronics',
            'quantity' => 0,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_rejects_quantity_above_maximum(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-QMAX',
            'name' => 'Max Quantity',
            'category' => 'electronics',
            'quantity' => 10001,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-QNEG',
            'name' => 'Negative Quantity',
            'category' => 'electronics',
            'quantity' => -1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_rejects_string_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-QSTR',
            'name' => 'String Quantity',
            'category' => 'electronics',
            'quantity' => 'abc',
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
    }

    public function test_store_rejects_future_purchase_date(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-FPD',
            'name' => 'Future Date',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'purchase_date' => '2099-01-01',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['purchase_date']);
    }

    public function test_store_rejects_negative_purchase_price(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NPP',
            'name' => 'Negative Price',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'purchase_price' => -1000,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['purchase_price']);
    }

    public function test_store_rejects_invalid_room_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-IR',
            'name' => 'Invalid Room',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'room_id' => 99999,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['room_id']);
    }

    public function test_store_accepts_valid_room_id(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-VR',
            'name' => 'Valid Room Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'room_id' => $room->id,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($room->id, $response->json('data.room_id'));
    }

    public function test_store_accepts_null_room_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NR',
            'name' => 'No Room Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'room_id' => null,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNull($response->json('data.room_id'));
    }

    public function test_store_rejects_string_code_too_long(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => str_repeat('A', 21),
            'name' => 'Long Code',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_string_name_too_long(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-LN',
            'name' => str_repeat('A', 151),
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_admin_can_update_asset(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Updated Asset',
            'category' => 'furniture',
            'quantity' => 20,
            'condition' => 'fair',
            'status' => 'inactive',
        ]);
        $response->assertStatus(200);
    }

    public function test_administrator_can_update_asset(): void
    {
        $this->authenticateAsAdministrator();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Admin Updated Asset',
            'category' => 'sports',
            'quantity' => 15,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update_asset(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Guru Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update_asset(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Siswa Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch_asset(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Guru Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch_asset(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_update_changes_name(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['name' => 'Old Name']);
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'New Name',
            'category' => $asset->category,
            'quantity' => $asset->quantity,
            'condition' => $asset->condition,
            'status' => $asset->status,
        ]);
        $response->assertStatus(200);
        $this->assertEquals('New Name', $response->json('data.name'));
    }

    public function test_patch_updates_single_field(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['name' => 'Original', 'quantity' => 5]);
        $response = $this->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Patched',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('Patched', $response->json('data.name'));
        $this->assertEquals(5, $response->json('data.quantity'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/assets/99999', [
            'code' => 'AST-404',
            'name' => 'Not Found',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_update_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $asset1 = $this->createTestAsset(['code' => 'AST-U1']);
        $asset2 = $this->createTestAsset(['code' => 'AST-U2']);
        $response = $this->putJson("/api/assets/{$asset2->id}", [
            'code' => 'AST-U1',
            'name' => $asset2->name,
            'category' => $asset2->category,
            'quantity' => $asset2->quantity,
            'condition' => $asset2->condition,
            'status' => $asset2->status,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_update_same_code_to_self_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['code' => 'AST-SELF']);
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => 'AST-SELF',
            'name' => 'Self Update',
            'category' => $asset->category,
            'quantity' => $asset->quantity,
            'condition' => $asset->condition,
            'status' => $asset->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Destroy Tests ─────────────────────────────────────────

    public function test_admin_can_delete_asset(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_delete_asset(): void
    {
        $this->authenticateAsAdministrator();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete_asset(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete_asset(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(403);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $assetId = $asset->id;
        $this->deleteJson("/api/assets/{$assetId}")->assertStatus(200);
        $this->assertSoftDeleted('assets', ['id' => $assetId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/assets/99999');
        $response->assertStatus(404);
    }

    public function test_deleted_asset_not_in_index(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);
        $response = $this->getJson('/api/assets');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($asset->id, $item['id']);
        }
    }

    public function test_deleted_asset_returns_404_on_show(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(404);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['code' => 'AST-REUSE']);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-REUSE',
            'name' => 'Reused Code Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── Room Relationship Tests ───────────────────────────────

    public function test_asset_with_valid_room(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-VRM',
            'name' => 'Room Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'room_id' => $room->id,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($room->id, $response->json('data.room_id'));
    }

    public function test_asset_without_room(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-NRM',
            'name' => 'No Room Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNull($response->json('data.room_id'));
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_delete_preserves_other_assets(): void
    {
        $this->authenticateAsAdmin();
        $asset1 = $this->createTestAsset(['code' => 'AST-P1']);
        $asset2 = $this->createTestAsset(['code' => 'AST-P2']);
        $this->deleteJson("/api/assets/{$asset1->id}")->assertStatus(200);
        $this->assertDatabaseHas('assets', ['code' => 'AST-P2']);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/assets/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/assets/99999', [
            'code' => 'AST-IDOR',
            'name' => 'IDOR',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/assets/99999');
        $response->assertStatus(404);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'id' => 99999,
            'code' => 'AST-MA',
            'name' => 'MA Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-CAT',
            'name' => 'CAT Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'AST-DAT',
            'name' => 'DAT Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Asset::find($response->json('data.id'))->deleted_at);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $this->createTestAsset();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }
}
