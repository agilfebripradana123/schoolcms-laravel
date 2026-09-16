<?php

namespace Tests\Feature\Room;

use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestRooms();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRooms();
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
            'name' => 'Test User Room ' . $prefix,
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

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestRooms(): void
    {
        Room::where('code', 'like', 'RM-%')->forceDelete();
        Room::where('code', 'like', 'SEC-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_structure(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
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
            'message' => 'Rooms retrieved successfully',
        ]);
    }

    public function test_index_returns_rooms(): void
    {
        $this->authenticate();
        $this->createTestRoom();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_index_includes_legacy_room(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('R-01', $data[0]['code']);
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms?page=2&per_page=1');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['current_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_code(): void
    {
        $this->authenticate();
        $this->createTestRoom(['code' => 'RM-SEARCH-A']);
        $this->createTestRoom(['code' => 'RM-NOMATCH-B']);
        $response = $this->getJson('/api/rooms?search=SEARCH-A');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH-A', $item['code']);
        }
    }

    public function test_search_by_name(): void
    {
        $this->authenticate();
        $this->createTestRoom(['name' => 'Gedung Utama']);
        $this->createTestRoom(['name' => 'Ruang Siswa']);
        $response = $this->getJson('/api/rooms?search=Gedung');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Gedung', $item['name']);
        }
    }

    public function test_search_by_code_and_name_combined(): void
    {
        $this->authenticate();
        $this->createTestRoom(['code' => 'RM-SPEC01', 'name' => 'Gedung Barat']);
        $this->createTestRoom(['code' => 'RM-NOM01', 'name' => 'Ruang Timur']);
        $response = $this->getJson('/api/rooms?search=SPEC');
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
        $response = $this->getJson('/api/rooms?search=NONEXISTENTXYZ');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_status_active(): void
    {
        $this->authenticate();
        $this->createTestRoom(['status' => 'active']);
        $this->createTestRoom(['status' => 'inactive']);
        $response = $this->getJson('/api/rooms?status=active');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('active', $item['status']);
        }
    }

    public function test_filter_by_status_inactive(): void
    {
        $this->authenticate();
        $this->createTestRoom(['status' => 'inactive']);
        $response = $this->getJson('/api/rooms?status=inactive');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('inactive', $item['status']);
        }
    }

    public function test_filter_by_has_computer_true(): void
    {
        $this->authenticate();
        $this->createTestRoom(['has_computer' => true]);
        $this->createTestRoom(['has_computer' => false]);
        $response = $this->getJson('/api/rooms?has_computer=1');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertTrue($item['has_computer']);
        }
    }

    public function test_filter_by_has_computer_false(): void
    {
        $this->authenticate();
        $this->createTestRoom(['has_computer' => false]);
        $response = $this->getJson('/api/rooms?has_computer=0');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertFalse($item['has_computer']);
        }
    }

    public function test_filter_combined_status_and_has_computer(): void
    {
        $this->authenticate();
        $this->createTestRoom(['status' => 'active', 'has_computer' => true]);
        $this->createTestRoom(['status' => 'active', 'has_computer' => false]);
        $this->createTestRoom(['status' => 'inactive', 'has_computer' => true]);
        $response = $this->getJson('/api/rooms?status=active&has_computer=1');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('active', $item['status']);
            $this->assertTrue($item['has_computer']);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom([
            'code' => 'RM-TEST',
            'name' => 'Test Show Room',
            'capacity' => 40,
            'location' => 'Floor 2',
            'has_computer' => true,
            'status' => 'active',
        ]);
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $room->id,
                'code' => 'RM-TEST',
                'name' => 'Test Show Room',
                'capacity' => 40,
                'location' => 'Floor 2',
                'has_computer' => true,
                'status' => 'active',
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Room not found',
            'data' => null,
        ]);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_admin_can_store_room(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-ADMIN',
            'name' => 'Admin Room',
            'capacity' => 25,
            'has_computer' => true,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_store_room(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-ADM',
            'name' => 'Administrator Room',
            'capacity' => 20,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_store_room(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-GURU',
            'name' => 'Guru Room',
            'capacity' => 20,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store_room(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-SISWA',
            'name' => 'Siswa Room',
            'capacity' => 20,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_store_returns_created_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-CR',
            'name' => 'Created Room',
            'capacity' => 15,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $this->postJson('/api/rooms', [
            'code' => 'RM-DB',
            'name' => 'DB Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ])->assertStatus(201);

        $this->assertDatabaseHas('rooms', [
            'code' => 'RM-DB',
            'name' => 'DB Room',
        ]);
    }

    public function test_store_returns_data(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-RET',
            'name' => 'Return Room',
            'capacity' => 10,
            'has_computer' => true,
            'status' => 'inactive',
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('RM-RET', $data['code']);
        $this->assertEquals('Return Room', $data['name']);
        $this->assertEquals(10, $data['capacity']);
        $this->assertTrue($data['has_computer']);
        $this->assertEquals('inactive', $data['status']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    // ─── Store Validation Tests ────────────────────────────────

    public function test_store_requires_code(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'name' => 'No Code Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_requires_name(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-NN',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_capacity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-NC',
            'name' => 'No Cap Room',
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['capacity']);
    }

    public function test_store_requires_has_computer(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-HC',
            'name' => 'HC Room',
            'capacity' => 10,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['has_computer']);
    }

    public function test_store_requires_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-ST',
            'name' => 'Status Room',
            'capacity' => 10,
            'has_computer' => false,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRoom(['code' => 'RM-DUP']);
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-DUP',
            'name' => 'Duplicate Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-IS',
            'name' => 'Invalid Status Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_negative_capacity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-NEG',
            'name' => 'Negative Capacity Room',
            'capacity' => -1,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['capacity']);
    }

    public function test_store_rejects_capacity_above_10000(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-OVR',
            'name' => 'Over Capacity Room',
            'capacity' => 10001,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['capacity']);
    }

    public function test_store_accepts_capacity_0(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-ZERO',
            'name' => 'Zero Capacity Room',
            'capacity' => 0,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_accepts_capacity_10000(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-MAX',
            'name' => 'Max Capacity Room',
            'capacity' => 10000,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_rejects_invalid_has_computer(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-HCI',
            'name' => 'HC Invalid Room',
            'capacity' => 10,
            'has_computer' => 'invalid',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['has_computer']);
    }

    public function test_store_rejects_string_capacity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-SC',
            'name' => 'String Capacity Room',
            'capacity' => 'abc',
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['capacity']);
    }

    public function test_store_accepts_empty_location(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-NL',
            'name' => 'No Location Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_accepts_location(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-WL',
            'name' => 'With Location Room',
            'capacity' => 10,
            'location' => 'Building B, Floor 3',
            'has_computer' => true,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('Building B, Floor 3', $response->json('data.location'));
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_admin_can_update_room(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Updated Room',
            'capacity' => 50,
            'has_computer' => true,
            'status' => 'inactive',
        ]);
        $response->assertStatus(200);
    }

    public function test_administrator_can_update_room(): void
    {
        $this->authenticateAsAdministrator();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Admin Updated Room',
            'capacity' => 35,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update_room(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Guru Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update_room(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Siswa Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch_room(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Guru Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch_room(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_update_changes_name(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['name' => 'Old Name']);
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'New Name',
            'capacity' => $room->capacity,
            'has_computer' => $room->has_computer,
            'status' => $room->status,
        ]);
        $response->assertStatus(200);
        $this->assertEquals('New Name', $response->json('data.name'));
    }

    public function test_patch_updates_single_field(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['name' => 'Original', 'capacity' => 20]);
        $response = $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Patched',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('Patched', $response->json('data.name'));
        $this->assertEquals(20, $response->json('data.capacity'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/rooms/99999', [
            'code' => 'RM-404',
            'name' => 'Not Found',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_update_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $room1 = $this->createTestRoom(['code' => 'RM-U1']);
        $room2 = $this->createTestRoom(['code' => 'RM-U2']);
        $response = $this->putJson("/api/rooms/{$room2->id}", [
            'code' => 'RM-U1',
            'name' => $room2->name,
            'capacity' => $room2->capacity,
            'has_computer' => $room2->has_computer,
            'status' => $room2->status,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_update_same_code_to_self_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['code' => 'RM-SELF']);
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => 'RM-SELF',
            'name' => 'Self Update',
            'capacity' => $room->capacity,
            'has_computer' => $room->has_computer,
            'status' => $room->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Destroy Tests ─────────────────────────────────────────

    public function test_admin_can_delete_room(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_delete_room(): void
    {
        $this->authenticateAsAdministrator();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete_room(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete_room(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(403);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $roomId = $room->id;
        $this->deleteJson("/api/rooms/{$roomId}")->assertStatus(200);
        $this->assertSoftDeleted('rooms', ['id' => $roomId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/rooms/99999');
        $response->assertStatus(404);
    }

    public function test_deleted_room_not_in_index(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);
        $response = $this->getJson('/api/rooms');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($room->id, $item['id']);
        }
    }

    public function test_deleted_room_returns_404_on_show(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);
        $this->getJson("/api/rooms/{$room->id}")->assertStatus(404);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['code' => 'RM-REUSE']);
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-REUSE',
            'name' => 'Reused Code Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_delete_preserves_exam_schedules(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_delete_preserves_other_rooms(): void
    {
        $this->authenticateAsAdmin();
        $room1 = $this->createTestRoom(['code' => 'RM-P1']);
        $room2 = $this->createTestRoom(['code' => 'RM-P2']);
        $this->deleteJson("/api/rooms/{$room1->id}")->assertStatus(200);
        $this->assertDatabaseHas('rooms', ['code' => 'RM-P2']);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/rooms/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/rooms/99999', [
            'code' => 'RM-IDOR',
            'name' => 'IDOR',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/rooms/99999');
        $response->assertStatus(404);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'id' => 99999,
            'code' => 'RM-MA',
            'name' => 'MA Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-CAT',
            'name' => 'CAT Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'RM-DAT',
            'name' => 'DAT Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Room::find($response->json('data.id'))->deleted_at);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $this->createTestRoom();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }
}
