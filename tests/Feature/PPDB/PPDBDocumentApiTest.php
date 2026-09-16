<?php

namespace Tests\Feature\PPDB;

use App\Models\PPDB\Registrant;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PPDBDocumentApiTest extends TestCase
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
            'registration_number' => 'DOC-TEST-'.str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'full_name' => 'Doc Test '.mt_rand(100, 999),
            'email' => 'doc.'.mt_rand(100000, 999999).'@test.local',
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
        DB::connection()->statement('DELETE FROM registrants WHERE registration_number LIKE "DOC-TEST-%"');

        $siswaIds = DB::connection()->table('users')->where('username', 'like', 'ppdbsiswa_%')->pluck('id');
        DB::connection()->table('students')->whereIn('user_id', $siswaIds)->delete();
        DB::connection()->table('users')->whereIn('id', $siswaIds)->delete();
    }

    // ─── Metadata Tests ────────────────────────────────────────

    public function test_admin_can_view_document_metadata(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}/documents");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'kk', 'birth_certificate', 'diploma', 'parent_ktp',
                'kip_kks', 'photo', 'other',
            ],
        ]);
    }

    public function test_document_metadata_shows_boolean(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}/documents");
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.kk'));
        $this->assertFalse($response->json('data.photo'));
    }

    public function test_nonexistent_registration_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $response = $this->getJson('/api/registrations/99999/documents');
        $response->assertStatus(404);
    }

    // ─── Upload Tests ──────────────────────────────────────────

    public function test_admin_can_upload_document(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(201);
    }

    public function test_administrator_can_upload(): void
    {
        $this->authenticateAsAdministrator();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(201);
    }

    public function test_guru_cannot_upload(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(403);
    }

    public function test_upload_requires_document_type(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document' => $file,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_type']);
    }

    public function test_upload_requires_document_file(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document']);
    }

    public function test_upload_rejects_invalid_document_type(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'invalid',
            'document' => $file,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_type']);
    }

    public function test_upload_rejects_php_file(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('shell.php', 100, 'application/x-php');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(422);
    }

    public function test_upload_rejects_html_file(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('page.html', 100, 'text/html');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(422);
    }

    public function test_upload_stores_file_privately(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ])->assertStatus(201);

        $reg->refresh();
        $this->assertNotNull($reg->document_kk);
        $this->assertStringStartsWith('ppdb/registrations/', $reg->document_kk);
        $this->assertStringNotContainsString('public', $reg->document_kk);
    }

    public function test_upload_generates_random_filename(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ])->assertStatus(201);

        $reg->refresh();
        $this->assertNotEquals('document.pdf', basename($reg->document_kk));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]+\.pdf$/', basename($reg->document_kk));
    }

    public function test_upload_replaces_old_file(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();

        // Upload first file
        $file1 = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file1,
        ])->assertStatus(201);

        $reg->refresh();
        $oldPath = $reg->document_kk;

        // Upload second file
        $file2 = UploadedFile::fake()->create('second.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file2,
        ])->assertStatus(201);

        $reg->refresh();
        $newPath = $reg->document_kk;

        $this->assertNotEquals($oldPath, $newPath);
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($newPath));
    }

    // ─── Download Tests ────────────────────────────────────────

    public function test_admin_can_download_document(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ])->assertStatus(201);

        $response = $this->getJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(200);
    }

    public function test_download_nonexistent_document_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(404);
    }

    public function test_download_invalid_type_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}/documents/invalid");
        $response->assertStatus(404);
    }

    // ─── Delete Tests ──────────────────────────────────────────

    public function test_admin_can_delete_document(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ])->assertStatus(201);

        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(200);

        $reg->refresh();
        $this->assertNull($reg->document_kk);
    }

    public function test_administrator_can_delete(): void
    {
        $this->authenticateAsAdministrator();
        $reg = $this->createTestRegistration();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ])->assertStatus(201);

        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(200);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(403);
    }

    public function test_delete_nonexistent_document_handled_safely(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(200);
    }

    public function test_delete_invalid_type_returns_404(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();
        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/invalid");
        $response->assertStatus(404);
    }

    // ─── Ownership Tests ───────────────────────────────────────

    public function test_siswa_can_view_own_document_metadata(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $reg = $this->createTestRegistration(['student_id' => $student->id]);
        $response = $this->getJson("/api/registrations/{$reg->id}/documents");
        $response->assertStatus(200);
    }

    public function test_siswa_cannot_view_other_document_metadata(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration();
        $response = $this->getJson("/api/registrations/{$reg->id}/documents");
        $response->assertStatus(404);
    }

    public function test_siswa_cannot_upload(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $reg = $this->createTestRegistration(['student_id' => $student->id]);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $response = $this->postJson("/api/registrations/{$reg->id}/documents", [
            'document_type' => 'kk',
            'document' => $file,
        ]);
        $response->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $user = $this->authenticateAsSiswa();
        $student = $this->createTestStudent($user);
        $reg = $this->createTestRegistration(['student_id' => $student->id]);
        $response = $this->deleteJson("/api/registrations/{$reg->id}/documents/kk");
        $response->assertStatus(403);
    }
}
