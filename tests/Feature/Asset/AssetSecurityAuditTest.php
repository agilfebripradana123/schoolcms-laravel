<?php

namespace Tests\Feature\Asset;

use App\Models\Facilities\Asset;
use App\Models\System\Role;
use App\Models\Facilities\Room;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetSecurityAuditTest extends TestCase
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
            'name' => 'Test User Asset Audit ' . $prefix,
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
            'name' => 'Security Test Room ' . mt_rand(100, 999),
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
            'code' => 'SEC-A-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Security Test Asset ' . mt_rand(100, 999),
            'description' => 'Security test description',
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
        Asset::where('code', 'like', 'SEC-A-%')->forceDelete();
        Room::where('code', 'like', 'RM-%')->forceDelete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/assets');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/assets/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/assets', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/assets/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_patch_returns_401(): void
    {
        $response = $this->patchJson('/api/assets/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/assets/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-GURU',
            'name' => 'Guru Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Guru Updated',
            'category' => $asset->category,
            'quantity' => $asset->quantity,
            'condition' => $asset->condition,
            'status' => $asset->status,
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_patch(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Guru Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-SIS',
            'name' => 'Siswa Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => $asset->code,
            'name' => 'Siswa Updated',
            'category' => $asset->category,
            'quantity' => $asset->quantity,
            'condition' => $asset->condition,
            'status' => $asset->status,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_patch(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Siswa Patched',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $asset = $this->createTestAsset();
        $response = $this->deleteJson("/api/assets/{$asset->id}");
        $response->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);

        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-ADM',
            'name' => 'Admin Asset',
            'category' => 'electronics',
            'quantity' => 10,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $assetId = $response->json('data.id');

        $response = $this->getJson("/api/assets/{$assetId}");
        $response->assertStatus(200);

        $response = $this->putJson("/api/assets/{$assetId}", [
            'code' => 'SEC-A-ADM',
            'name' => 'Admin Updated',
            'category' => 'furniture',
            'quantity' => 20,
            'condition' => 'fair',
            'status' => 'inactive',
        ]);
        $response->assertStatus(200);

        $response = $this->patchJson("/api/assets/{$assetId}", [
            'name' => 'Admin Patched',
        ]);
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/assets/{$assetId}");
        $response->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $response = $this->getJson('/api/assets');
        $response->assertStatus(200);

        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-ADM2',
            'name' => 'Admin2 Asset',
            'category' => 'office',
            'quantity' => 5,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $assetId = $response->json('data.id');

        $response = $this->getJson("/api/assets/{$assetId}");
        $response->assertStatus(200);

        $response = $this->deleteJson("/api/assets/{$assetId}");
        $response->assertStatus(200);
    }

    // ─── Duplicate & Integrity Tests ───────────────────────────

    public function test_cannot_create_duplicate_code(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestAsset(['code' => 'SEC-A-DUP']);
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-DUP',
            'name' => 'Duplicate',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_soft_deleted_code_cannot_be_reused(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['code' => 'SEC-A-RUSE']);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);

        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-RUSE',
            'name' => 'Reused',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_update_same_code_to_self_allowed(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['code' => 'SEC-A-SELF']);
        $response = $this->putJson("/api/assets/{$asset->id}", [
            'code' => 'SEC-A-SELF',
            'name' => 'Self Update',
            'category' => $asset->category,
            'quantity' => $asset->quantity,
            'condition' => $asset->condition,
            'status' => $asset->status,
        ]);
        $response->assertStatus(200);
    }

    // ─── Soft-Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);

        $dbAsset = Asset::withTrashed()->find($asset->id);
        $this->assertNotNull($dbAsset->deleted_at);
    }

    public function test_soft_deleted_asset_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);

        $response = $this->getJson('/api/assets');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($asset->id, $item['id']);
        }
    }

    public function test_soft_deleted_asset_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);

        $this->getJson("/api/assets/{$asset->id}")->assertStatus(404);
    }

    public function test_soft_deleted_asset_preserves_data(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset(['code' => 'SEC-A-PRES', 'name' => 'Preserved']);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(200);

        $dbAsset = Asset::withTrashed()->find($asset->id);
        $this->assertEquals('SEC-A-PRES', $dbAsset->code);
        $this->assertEquals('Preserved', $dbAsset->name);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'id' => 99999,
            'code' => 'SEC-A-MAI',
            'name' => 'MAI Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_created_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-MAC',
            'name' => 'MAC Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.created_at'));
    }

    public function test_store_ignores_updated_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-MAU',
            'name' => 'MAU Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
            'updated_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals('2000-01-01T00:00:00.000000Z', $response->json('data.updated_at'));
    }

    public function test_store_ignores_deleted_at_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-MAD',
            'name' => 'MAD Asset',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
            'deleted_at' => '2000-01-01 00:00:00',
        ]);
        $response->assertStatus(201);
        $this->assertNull(Asset::find($response->json('data.id'))->deleted_at);
    }

    // ─── Input Validation Security Tests ───────────────────────

    public function test_store_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-IC',
            'name' => 'Invalid Category',
            'category' => 'invalid',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_condition(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-ICD',
            'name' => 'Invalid Condition',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'invalid',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-IS',
            'name' => 'Invalid Status',
            'category' => 'electronics',
            'quantity' => 1,
            'condition' => 'good',
            'status' => 'invalid',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', []);
        $response->assertStatus(422);
    }

    public function test_update_allows_empty_body(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->putJson("/api/assets/{$asset->id}", []);
        $response->assertStatus(200);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    public function test_store_rejects_quantity_above_maximum(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-QMAX',
            'name' => 'Over Quantity',
            'category' => 'electronics',
            'quantity' => 10001,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_negative_quantity(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-QNEG',
            'name' => 'Neg Quantity',
            'category' => 'electronics',
            'quantity' => -1,
            'condition' => 'good',
            'status' => 'active',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_room_id(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/assets', [
            'code' => 'SEC-A-IR',
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

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?per_page=101');
        $response->assertStatus(422);
    }

    public function test_string_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?per_page=abc');
        $response->assertStatus(422);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/assets/99999', [
            'code' => 'SEC-A-IDOR',
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

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_category_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?category=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_condition_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?condition=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_room_id_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/assets?room_id=99999');
        $response->assertStatus(422);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->getJson('/api/assets');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_show_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    public function test_no_password_fields_exposed(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $response = $this->getJson("/api/assets/{$asset->id}");
        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $asset1 = $this->createTestAsset();
        $asset2 = $this->createTestAsset();
        $response = $this->getJson('/api/assets');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── forceDelete Protection Tests ──────────────────────────

    public function test_destroy_uses_soft_delete_not_force(): void
    {
        $this->authenticateAsAdmin();
        $asset = $this->createTestAsset();
        $assetId = $asset->id;
        $this->deleteJson("/api/assets/{$assetId}")->assertStatus(200);
        $this->assertDatabaseHas('assets', ['id' => $assetId]);
        $dbAsset = Asset::withTrashed()->find($assetId);
        $this->assertNotNull($dbAsset->deleted_at);
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_unchanged_after_read_operations(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = Asset::count();
        $this->getJson('/api/assets');
        $this->createTestAsset();
        $this->getJson('/api/assets');
        $afterCount = Asset::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM assets');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('code', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('description', $columnNames);
        $this->assertContains('category', $columnNames);
        $this->assertContains('quantity', $columnNames);
        $this->assertContains('condition', $columnNames);
        $this->assertContains('location', $columnNames);
        $this->assertContains('room_id', $columnNames);
        $this->assertContains('purchase_date', $columnNames);
        $this->assertContains('purchase_price', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
        $this->assertCount(15, $columns);
    }
}
