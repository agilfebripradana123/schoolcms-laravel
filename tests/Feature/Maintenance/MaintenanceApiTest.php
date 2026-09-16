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

class MaintenanceApiTest extends TestCase
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
            'name' => 'Test User Maint ' . $prefix,
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

    private function createTestAsset(array $overrides = []): Asset
    {
        $defaults = [
            'code' => 'AST-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Test Asset ' . mt_rand(100, 999),
            'category' => 'electronics',
            'quantity' => 5,
            'condition' => 'good',
            'status' => 'active',
        ];

        return Asset::create(array_merge($defaults, $overrides));
    }

    private function createTestMaintenance(array $overrides = []): Maintenance
    {
        $defaults = [
            'code' => 'MNT-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'title' => 'Test Maintenance ' . mt_rand(100, 999),
            'description' => 'Test description',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ];

        return Maintenance::create(array_merge($defaults, $overrides));
    }

    // ─── Cleanup Helpers ───────────────────────────────────────

    private function cleanupTestMaintenance(): void
    {
        Maintenance::where('code', 'like', 'MNT-%')->forceDelete();
        Asset::where('code', 'like', 'AST-%')->forceDelete();
        Room::where('code', 'like', 'RM-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_access_show(): void
    {
        $this->authenticate();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
    }

    public function test_index_returns_json(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance');
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_structure(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance');
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
            'message' => 'Maintenance records retrieved successfully',
        ]);
    }

    public function test_index_returns_maintenance_records(): void
    {
        $this->authenticate();
        $this->createTestMaintenance();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_page_works(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance?page=2&per_page=1');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(2, $meta['current_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_code(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['code' => 'MNT-SEARCH-A']);
        $this->createTestMaintenance(['code' => 'MNT-NOMATCH-B']);
        $response = $this->getJson('/api/maintenance?search=SEARCH-A');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH-A', $item['code']);
        }
    }

    public function test_search_by_title(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['title' => 'Perbaikan AC']);
        $this->createTestMaintenance(['title' => 'Ganti Lampu']);
        $response = $this->getJson('/api/maintenance?search=Perbaikan');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Perbaikan', $item['title']);
        }
    }

    public function test_search_by_code_and_title_combined(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['code' => 'MNT-SPEC01', 'title' => 'Inspeksi Rutin']);
        $this->createTestMaintenance(['code' => 'MNT-NOM01', 'title' => 'Ganti Filter']);
        $response = $this->getJson('/api/maintenance?search=SPEC');
        $response->assertStatus(200);
        $found = false;
        foreach ($response->json('data') as $item) {
            if (str_contains($item['code'], 'SPEC') || str_contains($item['title'], 'SPEC')) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance?search=NONEXISTENTXYZ');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_status(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['status' => 'pending']);
        $this->createTestMaintenance(['status' => 'completed']);
        $response = $this->getJson('/api/maintenance?status=pending');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('pending', $item['status']);
        }
    }

    public function test_filter_by_priority(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['priority' => 'urgent']);
        $this->createTestMaintenance(['priority' => 'low']);
        $response = $this->getJson('/api/maintenance?priority=urgent');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('urgent', $item['priority']);
        }
    }

    public function test_filter_by_maintenance_type(): void
    {
        $this->authenticate();
        $this->createTestMaintenance(['maintenance_type' => 'emergency']);
        $this->createTestMaintenance(['maintenance_type' => 'preventive']);
        $response = $this->getJson('/api/maintenance?maintenance_type=emergency');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('emergency', $item['maintenance_type']);
        }
    }

    public function test_filter_by_asset_id(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $this->createTestMaintenance(['asset_id' => $asset->id]);
        $this->createTestMaintenance(['asset_id' => null]);
        $response = $this->getJson("/api/maintenance?asset_id={$asset->id}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($asset->id, $item['asset_id']);
        }
    }

    public function test_filter_by_room_id(): void
    {
        $this->authenticate();
        $room = $this->createTestRoom();
        $this->createTestMaintenance(['room_id' => $room->id]);
        $this->createTestMaintenance(['room_id' => null]);
        $response = $this->getJson("/api/maintenance?room_id={$room->id}");
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals($room->id, $item['room_id']);
        }
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticate();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    public function test_show_returns_correct_data(): void
    {
        $this->authenticate();
        $asset = $this->createTestAsset();
        $room = $this->createTestRoom();
        $record = $this->createTestMaintenance([
            'code' => 'MNT-SHOW',
            'title' => 'Show Test',
            'description' => 'Deskripsi test',
            'asset_id' => $asset->id,
            'room_id' => $room->id,
            'reported_by' => 'Pak Budi',
            'maintenance_type' => 'corrective',
            'priority' => 'high',
            'status' => 'pending',
            'scheduled_date' => '2026-09-01',
            'estimated_cost' => 500000.00,
        ]);
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $record->id,
                'code' => 'MNT-SHOW',
                'title' => 'Show Test',
                'description' => 'Deskripsi test',
                'asset_id' => $asset->id,
                'room_id' => $room->id,
                'reported_by' => 'Pak Budi',
                'maintenance_type' => 'corrective',
                'priority' => 'high',
                'status' => 'pending',
                'scheduled_date' => '2026-09-01',
                'estimated_cost' => '500000.00',
            ],
        ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance/99999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Maintenance record not found',
            'data' => null,
        ]);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticate();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_admin_can_store(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-ADMIN',
            'title' => 'Admin Maintenance',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_store(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-ADM',
            'title' => 'Administrator Maintenance',
            'maintenance_type' => 'preventive',
            'priority' => 'low',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-GURU',
            'title' => 'Guru Maintenance',
            'maintenance_type' => 'inspection',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SIS',
            'title' => 'Siswa Maintenance',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(403);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $this->postJson('/api/maintenance', [
            'code' => 'MNT-DB',
            'title' => 'DB Test',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ])->assertStatus(201);

        $this->assertDatabaseHas('maintenance', [
            'code' => 'MNT-DB',
            'title' => 'DB Test',
        ]);
    }

    public function test_store_returns_data(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $room = $this->createTestRoom();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-RET',
            'title' => 'Return Test',
            'description' => 'Deskripsi',
            'asset_id' => $asset->id,
            'room_id' => $room->id,
            'reported_by' => 'Pak Joko',
            'maintenance_type' => 'emergency',
            'priority' => 'urgent',
            'status' => 'pending',
            'scheduled_date' => '2026-09-15',
            'estimated_cost' => 1500000.00,
            'notes' => 'Catatan test',
        ]);
        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('MNT-RET', $data['code']);
        $this->assertEquals('Return Test', $data['title']);
        $this->assertEquals($asset->id, $data['asset_id']);
        $this->assertEquals($room->id, $data['room_id']);
        $this->assertEquals('Pak Joko', $data['reported_by']);
        $this->assertEquals('emergency', $data['maintenance_type']);
        $this->assertEquals('urgent', $data['priority']);
        $this->assertEquals('pending', $data['status']);
        $this->assertEquals('2026-09-15', $data['scheduled_date']);
        $this->assertEquals('1500000.00', $data['estimated_cost']);
        $this->assertEquals('Catatan test', $data['notes']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    // ─── Store Validation Tests ────────────────────────────────

    public function test_store_requires_code(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'title' => 'No Code',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_requires_title(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-NT',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_store_requires_maintenance_type(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-MT',
            'title' => 'No Type',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['maintenance_type']);
    }

    public function test_store_requires_priority(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-PR',
            'title' => 'No Priority',
            'maintenance_type' => 'corrective',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    public function test_store_requires_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-ST',
            'title' => 'No Status',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestMaintenance(['code' => 'MNT-DUP']);
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-DUP',
            'title' => 'Duplicate',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_store_rejects_invalid_maintenance_type(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-IT',
            'title' => 'Invalid Type',
            'maintenance_type' => 'invalid',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['maintenance_type']);
    }

    public function test_store_rejects_invalid_priority(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-IP',
            'title' => 'Invalid Priority',
            'maintenance_type' => 'corrective',
            'priority' => 'invalid',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-IS',
            'title' => 'Invalid Status',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_store_rejects_invalid_asset_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-IA',
            'title' => 'Invalid Asset',
            'asset_id' => 99999,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['asset_id']);
    }

    public function test_store_rejects_invalid_room_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-IR',
            'title' => 'Invalid Room',
            'room_id' => 99999,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['room_id']);
    }

    public function test_store_rejects_negative_cost(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-NC',
            'title' => 'Negative Cost',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'estimated_cost' => -1000,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['estimated_cost']);
    }

    public function test_store_accepts_valid_asset(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-VA',
            'title' => 'Valid Asset',
            'asset_id' => $asset->id,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($asset->id, $response->json('data.asset_id'));
    }

    public function test_store_accepts_valid_room(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-VR',
            'title' => 'Valid Room',
            'room_id' => $room->id,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($room->id, $response->json('data.room_id'));
    }

    // ─── Store Status Transition Tests ─────────────────────────

    public function test_store_allows_pending_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SP',
            'title' => 'Pending',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_allows_in_progress_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SIP',
            'title' => 'In Progress',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'in_progress',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_allows_completed_status_with_resolution(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SC',
            'title' => 'Completed',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'completed',
            'completed_date' => '2026-08-26',
            'resolution' => 'Masalah telah diperbaiki',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_rejects_completed_without_resolution_date(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SNR',
            'title' => 'No Resolution Date',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'completed',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['completed_date']);
    }

    public function test_store_rejects_completed_with_pending_completed_date(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SCPD',
            'title' => 'Completed with pending date',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'completed_date' => '2026-08-26',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['completed_date']);
    }

    public function test_store_rejects_completed_date_with_in_progress_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SIPCD',
            'title' => 'In Progress with completed date',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'in_progress',
            'completed_date' => '2026-08-26',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['completed_date']);
    }

    // ─── Store Date Consistency Tests ──────────────────────────

    public function test_store_rejects_started_before_scheduled(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-DS1',
            'title' => 'Date Before',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'scheduled_date' => '2026-09-01',
            'started_date' => '2026-08-01',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['started_date']);
    }

    public function test_store_rejects_completed_before_started(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-DS2',
            'title' => 'Completed Before',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'completed',
            'started_date' => '2026-09-01',
            'completed_date' => '2026-08-01',
            'resolution' => 'Selesai',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['completed_date']);
    }

    public function test_store_accepts_same_dates(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SD',
            'title' => 'Same Dates',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'completed',
            'started_date' => '2026-08-26',
            'completed_date' => '2026-08-26',
            'resolution' => 'Selesai',
        ]);
        $response->assertStatus(201);
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_admin_can_update(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Updated',
            'maintenance_type' => 'preventive',
            'priority' => 'high',
            'status' => 'in_progress',
        ]);
        $response->assertStatus(200);
    }

    public function test_administrator_can_update(): void
    {
        $this->authenticateAsAdministrator();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Admin Updated',
            'maintenance_type' => 'inspection',
            'priority' => 'low',
            'status' => 'in_progress',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Guru Update',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'in_progress',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'Siswa Update',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
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

    public function test_siswa_cannot_patch(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'title' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_update_changes_title(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['title' => 'Old Title']);
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => $record->code,
            'title' => 'New Title',
            'maintenance_type' => $record->maintenance_type,
            'priority' => $record->priority,
            'status' => $record->status,
        ]);
        $response->assertStatus(200);
        $this->assertEquals('New Title', $response->json('data.title'));
    }

    public function test_patch_updates_single_field(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['title' => 'Original', 'priority' => 'low']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'title' => 'Patched',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('Patched', $response->json('data.title'));
        $this->assertEquals('low', $response->json('data.priority'));
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/maintenance/99999', [
            'code' => 'MNT-404',
            'title' => 'Not Found',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(404);
    }

    public function test_update_rejects_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $record1 = $this->createTestMaintenance(['code' => 'MNT-U1']);
        $record2 = $this->createTestMaintenance(['code' => 'MNT-U2']);
        $response = $this->putJson("/api/maintenance/{$record2->id}", [
            'code' => 'MNT-U1',
            'title' => $record2->title,
            'maintenance_type' => $record2->maintenance_type,
            'priority' => $record2->priority,
            'status' => $record2->status,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_update_same_code_to_self_is_allowed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['code' => 'MNT-SELF']);
        $response = $this->putJson("/api/maintenance/{$record->id}", [
            'code' => 'MNT-SELF',
            'title' => 'Self Update',
            'maintenance_type' => $record->maintenance_type,
            'priority' => $record->priority,
            'status' => $record->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Update Status Transition Tests ────────────────────────

    public function test_update_pending_to_in_progress(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'pending']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'in_progress',
            'started_date' => '2026-08-26',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('in_progress', $response->json('data.status'));
    }

    public function test_update_pending_to_cancelled(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'pending']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'cancelled',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.status'));
    }

    public function test_update_in_progress_to_completed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'in_progress']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'completed',
            'completed_date' => '2026-08-26',
            'resolution' => 'Selesai diperbaiki',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('completed', $response->json('data.status'));
    }

    public function test_update_in_progress_to_cancelled(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'in_progress']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'cancelled',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.status'));
    }

    public function test_update_rejects_completed_to_pending(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance([
            'status' => 'completed',
            'completed_date' => '2026-08-25',
            'resolution' => 'Selesai',
        ]);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_completed_to_in_progress(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance([
            'status' => 'completed',
            'completed_date' => '2026-08-25',
            'resolution' => 'Selesai',
        ]);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'in_progress',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_completed_to_cancelled(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance([
            'status' => 'completed',
            'completed_date' => '2026-08-25',
            'resolution' => 'Selesai',
        ]);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'cancelled',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_cancelled_to_pending(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'cancelled']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_cancelled_to_in_progress(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'cancelled']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'in_progress',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_rejects_cancelled_to_completed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'cancelled']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'completed',
            'completed_date' => '2026-08-26',
            'resolution' => 'Selesai',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_update_same_status_to_self_allowed(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['status' => 'pending']);
        $response = $this->patchJson("/api/maintenance/{$record->id}", [
            'status' => 'pending',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('pending', $response->json('data.status'));
    }

    // ─── Destroy Tests ─────────────────────────────────────────

    public function test_admin_can_delete(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_delete(): void
    {
        $this->authenticateAsAdministrator();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $record = $this->createTestMaintenance();
        $response = $this->deleteJson("/api/maintenance/{$record->id}");
        $response->assertStatus(403);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $recordId = $record->id;
        $this->deleteJson("/api/maintenance/{$recordId}")->assertStatus(200);
        $this->assertSoftDeleted('maintenance', ['id' => $recordId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/maintenance/99999');
        $response->assertStatus(404);
    }

    public function test_deleted_not_in_index(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);
        $response = $this->getJson('/api/maintenance');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($record->id, $item['id']);
        }
    }

    public function test_deleted_returns_404_on_show(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance();
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);
        $this->getJson("/api/maintenance/{$record->id}")->assertStatus(404);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $record = $this->createTestMaintenance(['code' => 'MNT-REUSE']);
        $this->deleteJson("/api/maintenance/{$record->id}")->assertStatus(200);
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-REUSE',
            'title' => 'Reused',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
    }

    // ─── Relationship Tests ────────────────────────────────────

    public function test_asset_with_valid_asset(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-VA2',
            'title' => 'With Asset',
            'asset_id' => $asset->id,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($asset->id, $response->json('data.asset_id'));
    }

    public function test_asset_without_asset(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-NA',
            'title' => 'Without Asset',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertNull($response->json('data.asset_id'));
    }

    public function test_soft_deleted_asset_rejected(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $asset->delete();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SDA',
            'title' => 'Soft Deleted Asset',
            'asset_id' => $asset->id,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['asset_id']);
    }

    public function test_soft_deleted_room_rejected(): void
    {
        $this->authenticateAsAdmin();
        $room = $this->createTestRoom();
        $room->delete();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-SDR',
            'title' => 'Soft Deleted Room',
            'room_id' => $room->id,
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['room_id']);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_delete_preserves_other_records(): void
    {
        $this->authenticateAsAdmin();
        $record1 = $this->createTestMaintenance(['code' => 'MNT-P1']);
        $record2 = $this->createTestMaintenance(['code' => 'MNT-P2']);
        $this->deleteJson("/api/maintenance/{$record1->id}")->assertStatus(200);
        $this->assertDatabaseHas('maintenance', ['code' => 'MNT-P2']);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/maintenance/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/maintenance/99999', [
            'code' => 'MNT-IDOR',
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

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'id' => 99999,
            'code' => 'MNT-MA',
            'title' => 'MA Test',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-CAT',
            'title' => 'CAT Test',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/maintenance', [
            'code' => 'MNT-DAT',
            'title' => 'DAT Test',
            'maintenance_type' => 'corrective',
            'priority' => 'medium',
            'status' => 'pending',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Maintenance::find($response->json('data.id'))->deleted_at);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $this->createTestMaintenance();
        $response = $this->getJson('/api/maintenance');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_does_not_expose_deleted_at(): void
    {
        $this->authenticate();
        $record = $this->createTestMaintenance();
        $response = $this->getJson("/api/maintenance/{$record->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }
}
