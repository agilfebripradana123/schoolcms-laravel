<?php

namespace Tests\Feature\Room;

use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomSecurityAuditTest extends TestCase
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
            'name' => 'Test User Room Audit ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestRoom(array $overrides = []): Room
    {
        $defaults = [
            'code' => 'SEC-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Test Room ' . mt_rand(100, 999),
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
        Room::where('code', 'like', 'SEC-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/rooms/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/rooms', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/rooms/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/rooms/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/rooms/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-GURU',
            'name' => 'Guru Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Guru Updated',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Guru Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-SIS',
            'name' => 'Siswa Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => $room->code,
            'name' => 'Siswa Updated',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $room = $this->createTestRoom();
        $response = $this->deleteJson("/api/rooms/{$room->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);

        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-ADM',
            'name' => 'Admin Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $roomId = $response->json('data.id');

        $response = $this->getJson("/api/rooms/{$roomId}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/rooms/{$roomId}", [
            'code' => 'SEC-ADM',
            'name' => 'Admin Updated',
            'capacity' => 20,
            'has_computer' => true,
            'status' => 'inactive',
        ]);
        $response->assertStatus(200);

        $response = $this->patchJson("/api/rooms/{$roomId}", [
            'name' => 'Admin Patched',
        ]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/rooms/{$roomId}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/rooms');
        $response->assertStatus(200);

        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-ADM2',
            'name' => 'Admin2 Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $roomId = $response->json('data.id');

        $response = $this->getJson("/api/rooms/{$roomId}");
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/rooms/{$roomId}");
        $response->assertStatus(200);
    }

    // ─── Duplicate & Integrity Tests ───────────────────────────

    public function test_cannot_create_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRoom(['code' => 'SEC-DUP']);
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-DUP',
            'name' => 'Duplicate',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['code' => 'SEC-RUSE']);
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);

        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-RUSE',
            'name' => 'Reused',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_update_same_code_to_self_allowed(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['code' => 'SEC-SELF']);
        $response = $this->putJson("/api/rooms/{$room->id}", [
            'code' => 'SEC-SELF',
            'name' => 'Self Update',
            'capacity' => $room->capacity,
            'has_computer' => $room->has_computer,
            'status' => $room->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Soft-Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);

        $dbRoom = Room::withTrashed()->find($room->id);
        $this->assertNotNull($dbRoom->deleted_at);
    }

    public function test_soft_deleted_room_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);

        $response = $this->getJson('/api/rooms');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($room->id, $item['id']);
        }
    }

    public function test_soft_deleted_room_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);

        $this->getJson("/api/rooms/{$room->id}")->assertStatus(404);
    }

    public function test_soft_deleted_room_preserves_data(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom(['code' => 'SEC-PRES', 'name' => 'Preserved']);
        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(200);

        $dbRoom = Room::withTrashed()->find($room->id);
        $this->assertEquals('SEC-PRES', $dbRoom->code);
        $this->assertEquals('Preserved', $dbRoom->name);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'id' => 99999,
            'code' => 'SEC-MAI',
            'name' => 'MAI Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-MAC',
            'name' => 'MAC Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_updated_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-MAU',
            'name' => 'MAU Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
            'updated_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.updated_at'));
    }

    public function test_store_ignores_deleted_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-MAD',
            'name' => 'MAD Room',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'active',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Room::find($response->json('data.id'))->deleted_at);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_negative_capacity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-NC',
            'name' => 'Neg Cap',
            'capacity' => -1,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_capacity_above_10000(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-OC',
            'name' => 'Over Cap',
            'capacity' => 10001,
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-IS',
            'name' => 'Invalid Status',
            'capacity' => 10,
            'has_computer' => false,
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_has_computer(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-HCI',
            'name' => 'HC Invalid',
            'capacity' => 10,
            'has_computer' => 'invalid',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->putJson("/api/rooms/{$room->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_store_rejects_string_capacity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/rooms', [
            'code' => 'SEC-SC',
            'name' => 'String Cap',
            'capacity' => 'abc',
            'has_computer' => false,
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/rooms/99999', [
            'code' => 'SEC-IDOR',
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

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_has_computer_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/rooms?has_computer=invalid');
        $response->assertStatus(422);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->getJson('/api/rooms');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_no_password_fields_exposed(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->getJson("/api/rooms/{$room->id}");
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $room1 = $this->createTestRoom();
        $room2 = $this->createTestRoom();
        $response = $this->getJson('/api/rooms');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── forceDelete Protection Tests ──────────────────────────

    public function test_destroy_uses_soft_delete_not_force(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $roomId = $room->id;
        $this->deleteJson("/api/rooms/{$roomId}")->assertStatus(200);
        $this->assertDatabaseHas('rooms', ['id' => $roomId]);
        $dbRoom = Room::withTrashed()->find($roomId);
        $this->assertNotNull($dbRoom->deleted_at);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_unchanged_after_read_operations(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = Room::count();
        $this->getJson('/api/rooms');
        $this->createTestRoom();
        $this->getJson('/api/rooms');
        $afterCount = Room::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM rooms');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('code', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('capacity', $columnNames);
        $this->assertContains('location', $columnNames);
        $this->assertContains('has_computer', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
        $this->assertCount(10, $columns);
    }
}
