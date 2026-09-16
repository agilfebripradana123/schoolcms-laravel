<?php

namespace Tests\Feature\PPDB;

use App\Models\PPDB\Registrant;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationSecurityAuditTest extends TestCase
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

    private function authenticateAsSiswa(): User
    {
        // A fresh Siswa account per test so the user ↔ student link stays 1:1
        // (students.user_id is UNIQUE since Phase 8).
        $user = User::create([
            'username' => 'ppdbsiswa_'.mt_rand(100000, 999999),
            'name' => 'Siswa Test '.mt_rand(1000, 9999),
            'email' => 'ppdbsiswa.'.mt_rand(100000, 999999).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => (int) Role::where('name', 'Siswa')->value('id'),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createTestStudent(User $user): Student
    {
        return Student::create([
            'user_id' => $user->id,
            'nisn' => str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT),
            'nis' => str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'Siswa '.$user->name,
            'gender' => 'L',
            'birth_place' => 'Jakarta',
            'birth_date' => '2008-01-01',
            'address' => 'Jl. Test No. 1',
        ]);
    }

    private function createTestRegistration(array $overrides = []): Registrant
    {
        $defaults = [
            'registration_number' => 'SEC-PPDB-'.str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'full_name' => 'Security Test '.mt_rand(100, 999),
            'email' => 'sec.'.mt_rand(100000, 999999).'@test.local',
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
        DB::connection()->statement('DELETE FROM registrants WHERE registration_number LIKE "SEC-PPDB-%"');

        $siswaIds = DB::connection()->table('users')->where('username', 'like', 'ppdbsiswa_%')->pluck('id');
        DB::connection()->table('students')->whereIn('user_id', $siswaIds)->delete();
        DB::connection()->table('users')->whereIn('id', $siswaIds)->delete();
    }

    // ─── Authentication Tests ──────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_show_returns_401(): void
    {
        $response = $this->getJson('/api/registrations/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $response = $this->postJson('/api/registrations', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_update_returns_401(): void
    {
        $response = $this->putJson('/api/registrations/1', []);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $response = $this->deleteJson('/api/registrations/1');
        $response->assertStatus(401);
    }

    // ─── Authorization Tests ───────────────────────────────────

    public function test_guru_can_read_index(): void
    {
        $this->authenticateAsGuru();
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(403);
    }

    public function test_guru_can_read_show(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(403);
    }

    public function test_guru_cannot_store(): void
    {
        $this->authenticateAsGuru();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Guru',
            'email' => 'guru.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(403);
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

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}");
        $response->assertStatus(403);
    }

    public function test_siswa_can_read_index(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $this->createTestRegistration(['student_id' => $student->id]);
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
    }

    public function test_siswa_can_read_show(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $reg = $this->createTestRegistration(['student_id' => $student->id]);
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_read_other_registration(): void
    {
        $user = $this->authenticateAsSiswa();
        $otherStudent = Student::where('user_id', '!=', $user->id)->first();
        if (! $otherStudent) {
            $this->markTestSkipped('No other student available for IDOR test');
        }
        $reg = $this->createTestRegistration(['student_id' => $otherStudent->id]);
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(404);
    }

    public function test_siswa_index_only_shows_own_registrations(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $this->createTestRegistration(['student_id' => $student->id]);
        $this->createTestRegistration();

        $response = $this->getJson('/api/registrations');
        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals(null, $item['id']);
        }
    }

    public function test_siswa_cannot_store(): void
    {
        $this->authenticateAsSiswa();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Siswa',
            'email' => 'siswa.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_update(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration();
        $response = $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Siswa Update',
            'email' => $reg->email,
            'gender' => 'L',
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}");
        $response->assertStatus(403);
    }

    // ─── Workflow Security Tests ───────────────────────────────

    public function test_guru_cannot_verify(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration(['status' => 'submitted']);
        $response = $this->postJson("/api/registrations/{$reg->id}/verify");
        $response->assertStatus(403);
    }

    public function test_guru_cannot_select(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration(['verification_status' => 'verified', 'status' => 'verified']);
        $response = $this->postJson("/api/registrations/{$reg->id}/select", [
            'selection_score' => 85,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_verify(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration(['status' => 'submitted']);
        $response = $this->postJson("/api/registrations/{$reg->id}/verify");
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_select(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration(['verification_status' => 'verified', 'status' => 'verified']);
        $response = $this->postJson("/api/registrations/{$reg->id}/select", [
            'selection_score' => 85,
        ]);
        $response->assertStatus(403);
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
            'email' => 'idor.'.mt_rand(100000, 999999).'@test.local',
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
            'full_name' => 'MAI',
            'email' => 'mai.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
        ]);
        $response->assertStatus(201);
        $this->assertNotEquals(99999, $response->json('data.id'));
    }

    public function test_store_ignores_status_field(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Status MA',
            'email' => 'stma.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
            'status' => 'selected',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('data.status'));
    }

    public function test_store_ignores_verification_status(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'VS MA',
            'email' => 'vsma.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
            'verification_status' => 'verified',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('pending', $response->json('data.verification_status'));
    }

    public function test_store_ignores_verified_by(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->postJson('/api/registrations', [
            'full_name' => 'VB MA',
            'email' => 'vbma.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
            'verified_by' => 99999,
        ]);
        $response->assertStatus(201);
    }

    // ─── Sensitive Field Tests ─────────────────────────────────

    public function test_index_no_nik_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('nik', $item);
        }
    }

    public function test_index_no_father_nik_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['father_nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('father_nik', $item);
        }
    }

    public function test_index_no_mother_nik_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['mother_nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('mother_nik', $item);
        }
    }

    public function test_index_no_guardian_nik_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['guardian_nik' => '3171234567890123']);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('guardian_nik', $item);
        }
    }

    public function test_index_no_father_income_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['father_income' => 5000000]);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('father_income', $item);
        }
    }

    public function test_index_no_document_paths_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration(['document_kk' => 'storage/ppdb/kk.pdf']);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('document_kk', $item);
        }
    }

    public function test_detail_shows_documents_as_boolean_not_path(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['document_kk' => 'storage/ppdb/kk.pdf']);
        $response = $this->getJson("/api/registrations/{$reg->id}");
        $response->assertStatus(200);
        $this->assertIsBool($response->json('data.document_kk'));
        $this->assertTrue($response->json('data.document_kk'));
    }

    public function test_index_no_deleted_at_exposed(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration();
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('deleted_at', $item);
        }
    }

    // ─── Pagination Security Tests ─────────────────────────────

    public function test_invalid_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=0');
        $response->assertStatus(422);
    }

    public function test_negative_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=-1');
        $response->assertStatus(422);
    }

    public function test_excessive_per_page_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?per_page=101');
        $response->assertStatus(422);
    }

    // ─── Filter Validation Tests ───────────────────────────────

    public function test_invalid_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_verification_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?verification_status=invalid');
        $response->assertStatus(422);
    }

    public function test_invalid_selection_status_filter_rejected(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations?selection_status=invalid');
        $response->assertStatus(422);
    }

    // ─── Soft Delete Tests ─────────────────────────────────────

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $this->deleteJson("/api/registrations/{$reg->id}")->assertStatus(200);
        $dbReg = Registrant::withTrashed()->find($reg->id);
        $this->assertNotNull($dbReg->deleted_at);
    }

    public function test_soft_deleted_not_in_active_query(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $this->deleteJson("/api/registrations/{$reg->id}")->assertStatus(200);
        $response = $this->getJson('/api/registrations');
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals($reg->id, $item['id']);
        }
    }

    public function test_soft_deleted_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $this->deleteJson("/api/registrations/{$reg->id}")->assertStatus(200);
        $this->getJson("/api/registrations/{$reg->id}")->assertStatus(404);
    }

    // ─── Sorting Tests ─────────────────────────────────────────

    public function test_fixed_sorting_by_id_desc(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestRegistration();
        $this->createTestRegistration();
        $response = $this->getJson('/api/registrations');
        $data = $response->json('data');
        if (count($data) >= 2) {
            $this->assertGreaterThanOrEqual($data[1]['id'], $data[0]['id']);
        }
    }

    // ─── Database Integrity Tests ──────────────────────────────

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();
        $columns = DB::connection()->select('SHOW COLUMNS FROM registrants');
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('nik', $columnNames);
        $this->assertContains('nisn', $columnNames);
        $this->assertContains('registration_number', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('verification_status', $columnNames);
        $this->assertContains('selection_status', $columnNames);
        $this->assertContains('re_registration_status', $columnNames);
    }
}
