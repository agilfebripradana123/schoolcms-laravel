<?php

namespace Tests\Feature\PPDB;

use App\Models\Academic\AcademicYear;
use App\Models\PPDB\Registrant;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
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
        $user = User::where('role_id', $siswaRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User PPDB ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function createTestRegistration(array $overrides = []): Registrant
    {
        $defaults = [
            'registration_number' => 'PPDB-TEST-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'full_name' => 'Test Siswa ' . mt_rand(100, 999),
            'email' => 'test.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
            'status' => 'draft',
            'verification_status' => 'pending',
            'selection_status' => 'pending',
            're_registration_status' => 'pending',
        ];

        return Registrant::create(array_merge($defaults, $overrides));
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM registrants WHERE registration_number LIKE "PPDB-TEST-%"');
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_index(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
    }

    // ─── Index Tests ───────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
    }

    public function test_index_response_structure(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta',
        ]);
    }

    public function test_index_returns_registrations(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ─── Show Tests ────────────────────────────────────────────

    public function test_show_returns_200(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations/99999');
        $response->assertStatus(404);
    }

    public function test_show_excludes_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('deleted_at', $response->json('data'));
    }

    // ─── Store Tests ───────────────────────────────────────────

    public function test_admin_can_store(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Siswa Baru',
            'email' => 'siswa.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(201);
    }

    public function test_store_creates_database_record(): void
    {
        $this->authenticateAsAdmin();
        $email = 'db.' . mt_rand(100000, 999999) . '@test.local';
        $this->postJson('/api/registrations', [
            'full_name' => 'DB Test',
            'email' => $email,
            'gender' => 'P',
        ])->assertStatus(201);

        $this->assertDatabaseHas('registrants', [
            'full_name' => 'DB Test',
            'email' => $email,
        ]);
    }

    public function test_store_generates_registration_number(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Auto Number',
            'email' => 'auto.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^PPDB-\d{4}-\d{6}$/', $response->json('data.registration_number'));
    }

    public function test_store_requires_full_name(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'email' => 'test.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['full_name']);
    }

    public function test_store_requires_email(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'No Email',
            'gender' => 'L',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_store_requires_gender(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'No Gender',
            'email' => 'nogender.' . mt_rand(100000, 999999) . '@test.local',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gender']);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $this->authenticateAsAdmin();
        $email = 'dup.' . mt_rand(100000, 999999) . '@test.local';
        $this->createTestRegistration(['email' => $email]);
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Duplicate',
            'email' => $email,
            'gender' => 'L',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_store_rejects_invalid_gender(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Invalid Gender',
            'email' => 'inv.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'X',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gender']);
    }

    public function test_store_rejects_invalid_religion(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Invalid Religion',
            'email' => 'rel.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
            'religion' => 'invalid',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['religion']);
    }

    public function test_store_sets_default_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Default Status',
            'email' => 'def.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('data.status'));
    }

    // ─── Update Tests ──────────────────────────────────────────

    public function test_admin_can_update(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Updated Name',
            'email' => $reg->email,
            'gender' => 'P',
        ]);
        $response->assertStatus(200);
    }

    public function test_guru_cannot_update(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration();
        $response = $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Guru Update',
            'email' => $reg->email,
            'gender' => 'L',
        ]);
        $response->assertStatus(403);
    }

    public function test_update_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/registrations/99999', [
            'full_name' => 'Not Found',
            'email' => 'nf.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(404);
    }

    // ─── Destroy Tests ─────────────────────────────────────────

    public function test_admin_can_delete(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $regId = $reg->id;
        $this->deleteJson("/api/registrations/{$regId}")->assertStatus(200);
        $this->assertSoftDeleted('registrants', ['id' => $regId]);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/registrations/99999');
        $response->assertStatus(404);
    }

    // ─── IDOR Tests ────────────────────────────────────────────

    public function test_idor_show_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations/99999');
        $response->assertStatus(404);
    }

    public function test_idor_update_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->putJson('/api/registrations/99999', [
            'full_name' => 'IDOR',
            'email' => 'idor.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(404);
    }

    public function test_idor_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->deleteJson('/api/registrations/99999');
        $response->assertStatus(404);
    }

    // ─── Mass Assignment Tests ─────────────────────────────────

    public function test_store_ignores_id_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'id' => 99999,
            'full_name' => 'MA Test',
            'email' => 'ma.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_status_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Status Test',
            'email' => 'status.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
            'status' => 'selected',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('data.status'));
    }

    // ─── Pagination Tests ──────────────────────────────────────

    public function test_pagination_default_per_page(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=5');
        $response->assertStatus(200);
        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
    }

    public function test_pagination_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_pagination_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Filter Tests ──────────────────────────────────────────

    public function test_filter_by_status(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['status' => 'draft']);
        $this->createTestRegistration(['status' => 'submitted']);
        $response = $this->getJson('/api/registrations?status=draft');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('draft', $item['status']);
        }
    }

    public function test_filter_by_verification_status(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['verification_status' => 'pending']);
        $this->createTestRegistration(['verification_status' => 'verified']);
        $response = $this->getJson('/api/registrations?verification_status=pending');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('pending', $item['verification_status']);
        }
    }

    public function test_filter_by_selection_status(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['selection_status' => 'pending']);
        $this->createTestRegistration(['selection_status' => 'selected']);
        $response = $this->getJson('/api/registrations?selection_status=selected');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('selected', $item['selection_status']);
        }
    }

    // ─── Search Tests ──────────────────────────────────────────

    public function test_search_by_registration_number(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['registration_number' => 'PPDB-TEST-SEARCH']);
        $response = $this->getJson('/api/registrations?search=SEARCH');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('SEARCH', $item['registration_number']);
        }
    }

    public function test_search_by_name(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['full_name' => 'Budi Santoso']);
        $response = $this->getJson('/api/registrations?search=Budi');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertStringContainsString('Budi', $item['full_name']);
        }
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_does_not_expose_nik(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('nik', $item);
        }
    }

    public function test_index_does_not_expose_father_nik(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['father_nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('father_nik', $item);
        }
    }

    public function test_index_does_not_expose_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    public function test_detail_shows_documents_as_boolean(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['document_kk' => null]);
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.document_kk'));
    }

    public function test_detail_shows_document_exists_true(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['document_kk' => 'some/path/kk.pdf']);
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.document_kk'));
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_delete_preserves_other_registrations(): void
    {
        $this->authenticateAsAdmin();
        $reg1 = $this->createTestRegistration(['registration_number' => 'PPDB-TEST-P1']);
        $reg2 = $this->createTestRegistration(['registration_number' => 'PPDB-TEST-P2']);
        $this->deleteJson("/api/registrations/{$reg1->id}")->assertStatus(200);
        $this->assertDatabaseHas('registrants', ['registration_number' => 'PPDB-TEST-P2']);
    }

    public function test_database_unchanged_after_read(): void
    {
        $this->authenticateAsAdmin();
        $beforeCount = Registrant::count();
        $this->getJson('/api/registrations');
        $this->createTestRegistration();
        $this->getJson('/api/registrations');
        $afterCount = Registrant::count();
        $this->assertEquals($beforeCount + 1, $afterCount);
    }
}
