<?php

namespace Tests\Feature\Teacher;

use App\Models\System\Role;
use App\Models\Staff\Teacher;
use App\Models\System\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TeacherSecurityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestTeachers();
        $this->cleanupTestUsers();
    }

    // ─── Helpers ─────────────────────────────────────────────────

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
            'name' => 'Test User Phase9 ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function cleanupTestTeachers(): void
    {
        Teacher::where('teacher_code', 'like', 'T9-%')->forceDelete();
        Teacher::where('nip', 'like', '6666%')->forceDelete();
    }

    private function cleanupTestUsers(): void
    {
        User::where('username', 'like', 'test_%')->forceDelete();
        User::where('username', 'like', 'siswa_%')->forceDelete();
    }

    private function getUniqueTeacherCode(): string
    {
        return 'T9-' . str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);
    }

    private function getUniqueNip(): string
    {
        return '6666' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function createTestTeacher(array $overrides = []): Teacher
    {
        $defaults = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'full_name' => 'Test Teacher Phase9',
            'gender' => 'L',
            'is_active' => true,
        ];

        return Teacher::create(array_merge($defaults, $overrides));
    }

    private function getFirstTeacherId(): int
    {
        return Teacher::whereNull('deleted_at')->first()->id;
    }

    private function columnLetter(int $column): string
    {
        $letter = '';
        while ($column > 0) {
            $column--;
            $letter = chr(65 + ($column % 26)) . $letter;
            $column = intdiv($column, 26);
        }

        return $letter;
    }

    private function getExpectedHeaders(): array
    {
        return [
            'Kode Guru', 'NIP', 'Nama Lengkap', 'Jenis Kelamin',
            'Tempat Lahir', 'Tanggal Lahir', 'No. HP', 'Email',
            'Agama', 'Alamat', 'Pendidikan Terakhir', 'Jurusan',
            'Status Kepegawaian', 'Tanggal Bergabung',
        ];
    }

    private function parseExcelResponse($response): array
    {
        $tempPath = sys_get_temp_dir() . '/audit_test_' . mt_rand(100000, 999999) . '.xlsx';
        $baseResponse = $response->baseResponse ?? $response;

        if ($baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $file = $baseResponse->getFile();
            copy($file->getPathname(), $tempPath);
        } else {
            file_put_contents($tempPath, $response->getContent());
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempPath);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        @unlink($tempPath);

        return $rows;
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 2 — AUTHENTICATION AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_get_index_returns_401(): void
    {
        $this->getJson('/api/teachers')->assertStatus(401);
    }

    public function test_unauthenticated_get_show_returns_401(): void
    {
        $this->getJson('/api/teachers/1')->assertStatus(401);
    }

    public function test_unauthenticated_get_export_returns_401(): void
    {
        $this->getJson('/api/teachers/export')->assertStatus(401);
    }

    public function test_unauthenticated_post_store_returns_401(): void
    {
        $this->postJson('/api/teachers', [])->assertStatus(401);
    }

    public function test_unauthenticated_put_update_returns_401(): void
    {
        $this->putJson('/api/teachers/1', [])->assertStatus(401);
    }

    public function test_unauthenticated_patch_update_returns_401(): void
    {
        $this->patchJson('/api/teachers/1', [])->assertStatus(401);
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $this->deleteJson('/api/teachers/1')->assertStatus(401);
    }

    public function test_unauthenticated_post_import_returns_401(): void
    {
        $this->postJson('/api/teachers/import', [])->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 3 — ROLE AUTHORIZATION AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_guru_can_get_index(): void
    {
        $this->authenticateAsGuru();
        $this->getJson('/api/teachers')->assertStatus(200);
    }

    public function test_guru_can_get_show(): void
    {
        $this->authenticateAsGuru();
        $id = $this->getFirstTeacherId();
        $this->getJson("/api/teachers/{$id}")->assertStatus(200);
    }

    public function test_guru_can_get_export(): void
    {
        $this->authenticateAsGuru();
        $this->getJson('/api/teachers/export')->assertStatus(200);
    }

    public function test_guru_cannot_post_store(): void
    {
        $this->authenticateAsGuru();
        $this->postJson('/api/teachers', [
            'teacher_code' => 'X', 'nip' => 'X', 'gender' => 'L', 'is_active' => true,
        ])->assertStatus(403);
    }

    public function test_guru_cannot_put_update(): void
    {
        $this->authenticateAsGuru();
        $id = $this->getFirstTeacherId();
        $this->putJson("/api/teachers/{$id}", ['full_name' => 'Hacked'])->assertStatus(403);
    }

    public function test_guru_cannot_patch_update(): void
    {
        $this->authenticateAsGuru();
        $id = $this->getFirstTeacherId();
        $this->patchJson("/api/teachers/{$id}", ['full_name' => 'Hacked'])->assertStatus(403);
    }

    public function test_guru_cannot_delete(): void
    {
        $this->authenticateAsGuru();
        $id = $this->getFirstTeacherId();
        $this->deleteJson("/api/teachers/{$id}")->assertStatus(403);
    }

    public function test_guru_cannot_import(): void
    {
        $this->authenticateAsGuru();
        $this->postJson('/api/teachers/import', [])->assertStatus(403);
    }

    public function test_siswa_can_get_index(): void
    {
        $this->authenticateAsSiswa();
        $this->getJson('/api/teachers')->assertStatus(200);
    }

    public function test_siswa_can_get_show(): void
    {
        $this->authenticateAsSiswa();
        $id = $this->getFirstTeacherId();
        $this->getJson("/api/teachers/{$id}")->assertStatus(200);
    }

    public function test_siswa_can_get_export(): void
    {
        $this->authenticateAsSiswa();
        $this->getJson('/api/teachers/export')->assertStatus(200);
    }

    public function test_siswa_cannot_post_store(): void
    {
        $this->authenticateAsSiswa();
        $this->postJson('/api/teachers', [
            'teacher_code' => 'X', 'nip' => 'X', 'gender' => 'L', 'is_active' => true,
        ])->assertStatus(403);
    }

    public function test_siswa_cannot_put_update(): void
    {
        $this->authenticateAsSiswa();
        $id = $this->getFirstTeacherId();
        $this->putJson("/api/teachers/{$id}", ['full_name' => 'Hacked'])->assertStatus(403);
    }

    public function test_siswa_cannot_patch_update(): void
    {
        $this->authenticateAsSiswa();
        $id = $this->getFirstTeacherId();
        $this->patchJson("/api/teachers/{$id}", ['full_name' => 'Hacked'])->assertStatus(403);
    }

    public function test_siswa_cannot_delete(): void
    {
        $this->authenticateAsSiswa();
        $id = $this->getFirstTeacherId();
        $this->deleteJson("/api/teachers/{$id}")->assertStatus(403);
    }

    public function test_siswa_cannot_import(): void
    {
        $this->authenticateAsSiswa();
        $this->postJson('/api/teachers/import', [])->assertStatus(403);
    }

    public function test_admin_can_all_operations(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers')->assertStatus(200);
        $id = $this->getFirstTeacherId();
        $this->getJson("/api/teachers/{$id}")->assertStatus(200);
        $this->getJson('/api/teachers/export')->assertStatus(200);
    }

    public function test_administrator_can_all_operations(): void
    {
        $this->authenticateAsAdministrator();
        $this->getJson('/api/teachers')->assertStatus(200);
        $id = $this->getFirstTeacherId();
        $this->getJson("/api/teachers/{$id}")->assertStatus(200);
        $this->getJson('/api/teachers/export')->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 4 — MASS ASSIGNMENT AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_mass_assignment_cannot_set_id_via_create(): void
    {
        $this->authenticateAsAdmin();

        $payload = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
            'id' => 1,
        ];

        $response = $this->postJson('/api/teachers', $payload);
        $response->assertStatus(201);

        $created = Teacher::where('teacher_code', $payload['teacher_code'])->first();
        $this->assertNotEquals(1, $created->id);

        $created->forceDelete();
    }

    public function test_mass_assignment_cannot_set_deleted_at_via_create(): void
    {
        $this->authenticateAsAdmin();

        $payload = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
            'deleted_at' => '2020-01-01 00:00:00',
        ];

        $response = $this->postJson('/api/teachers', $payload);
        $response->assertStatus(201);

        $created = Teacher::where('teacher_code', $payload['teacher_code'])->first();
        $this->assertNull($created->deleted_at);

        $created->forceDelete();
    }

    public function test_mass_assignment_cannot_set_timestamps_via_create(): void
    {
        $this->authenticateAsAdmin();

        $payload = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ];

        $response = $this->postJson('/api/teachers', $payload);
        $response->assertStatus(201);

        $created = Teacher::where('teacher_code', $payload['teacher_code'])->first();
        $this->assertNotEquals('2000-01-01', $created->created_at->format('Y-m-d'));

        $created->forceDelete();
    }

    public function test_mass_assignment_cannot_override_id_via_update(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();
        $originalId = $teacher->id;

        $this->putJson("/api/teachers/{$teacher->id}", [
            'id' => 99999,
            'full_name' => 'Should Not Change ID',
        ])->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals($originalId, $teacher->id);

        $teacher->forceDelete();
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 5 — VALIDATION AUDIT (PATCH partial)
    // ═══════════════════════════════════════════════════════════════

    public function test_patch_partial_update_only_changes_specified_field(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher([
            'full_name' => 'Original Name',
            'phone' => '081111111111',
            'employment_status' => 'PNS',
        ]);

        $this->patchJson("/api/teachers/{$teacher->id}", [
            'phone' => '082222222222',
        ])->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('082222222222', $teacher->phone);
        $this->assertEquals('Original Name', $teacher->full_name);
        $this->assertEquals('PNS', $teacher->employment_status);

        $teacher->forceDelete();
    }

    public function test_store_rejects_duplicate_teacher_code(): void
    {
        $this->authenticateAsAdmin();

        $existing = Teacher::whereNull('deleted_at')->first();
        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $existing->teacher_code,
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_duplicate_nip(): void
    {
        $this->authenticateAsAdmin();

        $existing = Teacher::whereNull('deleted_at')->first();
        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $existing->nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 6 — SEARCH AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_search_sekolah_does_not_return_all_teachers(): void
    {
        $this->authenticateAsAdmin();

        $totalActive = Teacher::whereNull('deleted_at')->count();

        $response = $this->getJson('/api/teachers?search=sekolah');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertLessThan($totalActive, count($data), 'search=sekolah should NOT return all teachers (email domain false positive)');
    }

    public function test_search_returns_empty_for_nonexistent(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?search=NONEXISTENT-TEACHER-XYZZY');
        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
    }

    public function test_search_by_teacher_code(): void
    {
        $this->authenticateAsAdmin();

        $teacher = Teacher::whereNull('deleted_at')->first();
        $response = $this->getJson("/api/teachers?search={$teacher->teacher_code}");
        $response->assertStatus(200);

        $codes = array_column($response->json('data'), 'teacher_code');
        $this->assertContains($teacher->teacher_code, $codes);
    }

    public function test_search_by_nip(): void
    {
        $this->authenticateAsAdmin();

        $teacher = Teacher::whereNull('deleted_at')->first();
        $response = $this->getJson("/api/teachers?search={$teacher->nip}");
        $response->assertStatus(200);

        $nips = array_column($response->json('data'), 'nip');
        $this->assertContains($teacher->nip, $nips);
    }

    public function test_search_by_phone(): void
    {
        $this->authenticateAsAdmin();

        $teacher = Teacher::whereNull('deleted_at')->whereNotNull('phone')->first();
        if (!$teacher) {
            $this->markTestSkipped('No teacher with phone found');
        }

        $response = $this->getJson("/api/teachers?search={$teacher->phone}");
        $response->assertStatus(200);

        $phones = array_column($response->json('data'), 'phone');
        $this->assertContains($teacher->phone, $phones);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 7 — FILTER AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_filter_gender_l(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?gender=L');
        $response->assertStatus(200);

        foreach ($response->json('data') as $teacher) {
            $this->assertEquals('L', $teacher['gender']);
        }
    }

    public function test_filter_gender_p(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?gender=P');
        $response->assertStatus(200);

        foreach ($response->json('data') as $teacher) {
            $this->assertEquals('P', $teacher['gender']);
        }
    }

    public function test_filter_employment_status_pns(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?employment_status=PNS');
        $response->assertStatus(200);

        foreach ($response->json('data') as $teacher) {
            $this->assertEquals('PNS', $teacher['employment_status']);
        }
    }

    public function test_filter_employment_status_honorer(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?employment_status=Honorer');
        $response->assertStatus(200);
    }

    public function test_filter_employment_status_pppk(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?employment_status=PPPK');
        $response->assertStatus(200);
    }

    public function test_filter_is_active_1(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?is_active=1');
        $response->assertStatus(200);

        foreach ($response->json('data') as $teacher) {
            $this->assertTrue($teacher['is_active']);
        }
    }

    public function test_filter_is_active_0(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?is_active=0');
        $response->assertStatus(200);

        foreach ($response->json('data') as $teacher) {
            $this->assertFalse($teacher['is_active']);
        }
    }

    public function test_invalid_gender_returns_422(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers?gender=X')->assertStatus(422);
    }

    public function test_invalid_is_active_returns_422(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers?is_active=5')->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 8 — PAGINATION AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_per_page_zero_returns_422(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers?per_page=0')->assertStatus(422);
    }

    public function test_per_page_101_returns_422(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers?per_page=101')->assertStatus(422);
    }

    public function test_per_page_abc_returns_422(): void
    {
        $this->authenticateAsAdmin();
        $this->getJson('/api/teachers?per_page=abc')->assertStatus(422);
    }

    public function test_default_per_page_is_10(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers');
        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
    }

    public function test_valid_per_page_50(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers?per_page=50');
        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 50);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 9 — 404 AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_get_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers/999999999');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Teacher not found',
            'data' => null,
        ]);
    }

    public function test_put_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->putJson('/api/teachers/999999999', [
            'full_name' => 'Should Fail',
        ])->assertStatus(404);
    }

    public function test_patch_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->patchJson('/api/teachers/999999999', [
            'full_name' => 'Should Fail',
        ])->assertStatus(404);
    }

    public function test_delete_nonexistent_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->deleteJson('/api/teachers/999999999')->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 10 — SOFT DELETE SAFETY
    // ═══════════════════════════════════════════════════════════════

    public function test_soft_delete_sets_deleted_at(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();
        $id = $teacher->id;

        $this->deleteJson("/api/teachers/{$id}")->assertStatus(200);

        $dbTeacher = Teacher::withTrashed()->find($id);
        $this->assertNotNull($dbTeacher->deleted_at);

        $raw = DB::connection()->table('teachers')->where('id', $id)->first();
        $this->assertNotNull($raw);
        $this->assertNotNull($raw->deleted_at);

        $dbTeacher->forceDelete();
    }

    public function test_soft_deleted_teacher_physical_data_exists(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();
        $id = $teacher->id;

        $this->deleteJson("/api/teachers/{$id}")->assertStatus(200);

        $raw = DB::connection()->table('teachers')->where('id', $id)->first();
        $this->assertNotNull($raw);
        $this->assertNotNull($raw->deleted_at);
        $this->assertEquals($teacher->nip, $raw->nip);

        Teacher::withTrashed()->where('id', $id)->forceDelete();
    }

    public function test_double_delete_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();
        $id = $teacher->id;

        $this->deleteJson("/api/teachers/{$id}")->assertStatus(200);
        $this->deleteJson("/api/teachers/{$id}")->assertStatus(404);

        Teacher::withTrashed()->where('id', $id)->forceDelete();
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 11 — IMPORT SECURITY AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_import_does_not_modify_users_table(): void
    {
        $this->authenticateAsAdmin();

        $usersBefore = DB::connection()->table('users')->count();

        $file = $this->createImportFile([
            $this->getExpectedHeaders(),
            [
                $this->getUniqueTeacherCode(), $this->getUniqueNip(), 'Audit Test', 'L',
                '', '', '', '', '', '', '', '', 'PNS', '',
            ],
        ]);

        $this->postJson('/api/teachers/import', ['file' => $file])->assertStatus(200);

        $usersAfter = DB::connection()->table('users')->count();
        $this->assertEquals($usersBefore, $usersAfter);

        $this->cleanupTestTeachers();
    }

    public function test_import_does_not_modify_roles_table(): void
    {
        $this->authenticateAsAdmin();

        $rolesBefore = DB::connection()->table('roles')->count();

        $file = $this->createImportFile([
            $this->getExpectedHeaders(),
            [
                $this->getUniqueTeacherCode(), $this->getUniqueNip(), 'Audit Test', 'L',
                '', '', '', '', '', '', '', '', 'PNS', '',
            ],
        ]);

        $this->postJson('/api/teachers/import', ['file' => $file])->assertStatus(200);

        $rolesAfter = DB::connection()->table('roles')->count();
        $this->assertEquals($rolesBefore, $rolesAfter);

        $this->cleanupTestTeachers();
    }

    public function test_import_does_not_modify_classes_table(): void
    {
        $this->authenticateAsAdmin();

        $classesBefore = DB::connection()->table('classes')->count();

        $file = $this->createImportFile([
            $this->getExpectedHeaders(),
            [
                $this->getUniqueTeacherCode(), $this->getUniqueNip(), 'Audit Test', 'L',
                '', '', '', '', '', '', '', '', 'PNS', '',
            ],
        ]);

        $this->postJson('/api/teachers/import', ['file' => $file])->assertStatus(200);

        $classesAfter = DB::connection()->table('classes')->count();
        $this->assertEquals($classesBefore, $classesAfter);

        $this->cleanupTestTeachers();
    }

    public function test_import_does_not_modify_subjects_table(): void
    {
        $this->authenticateAsAdmin();

        $subjectsBefore = DB::connection()->table('subjects')->count();

        $file = $this->createImportFile([
            $this->getExpectedHeaders(),
            [
                $this->getUniqueTeacherCode(), $this->getUniqueNip(), 'Audit Test', 'L',
                '', '', '', '', '', '', '', '', 'PNS', '',
            ],
        ]);

        $this->postJson('/api/teachers/import', ['file' => $file])->assertStatus(200);

        $subjectsAfter = DB::connection()->table('subjects')->count();
        $this->assertEquals($subjectsBefore, $subjectsAfter);

        $this->cleanupTestTeachers();
    }

    private function createImportFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $coord = $this->columnLetter($colIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($coord, $value ?? '');
            }
        }

        $tempPath = sys_get_temp_dir() . '/audit_import_' . mt_rand(100000, 999999) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'audit.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 12 — EXPORT SECURITY AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_export_does_not_expose_password_field(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers/export');
        $response->assertStatus(200);

        $rows = $this->parseExcelResponse($response);
        $headers = $rows[0];

        $this->assertNotContains('password', array_map('strtolower', $headers));
        $this->assertNotContains('remember_token', array_map('strtolower', $headers));
        $this->assertNotContains('deleted_at', array_map('strtolower', $headers));
        $this->assertNotContains('id', $headers);
        $this->assertNotContains('user_id', $headers);
    }

    public function test_export_only_uses_select_queries(): void
    {
        $this->authenticateAsAdmin();

        DB::connection()->enableQueryLog();

        $this->getJson('/api/teachers/export');

        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('insert into', $sql, 'Export should not INSERT');
            $this->assertStringNotContainsString('update ', $sql, 'Export should not UPDATE');
            $this->assertStringNotContainsString('delete from', $sql, 'Export should not DELETE');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 13 — QUERY / N+1 AUDIT
    // ═══════════════════════════════════════════════════════════════

    public function test_index_does_not_have_n_plus_1(): void
    {
        $this->authenticateAsAdmin();

        DB::connection()->enableQueryLog();

        $this->getJson('/api/teachers?per_page=10');

        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $queryCount = count($queries);
        $this->assertLessThanOrEqual(4, $queryCount, "Index should not have N+1 queries (got {$queryCount})");
    }

    // ═══════════════════════════════════════════════════════════════
    // TASK 14 — DATABASE SAFETY AUDIT (pre/post counts)
    // ═══════════════════════════════════════════════════════════════

    public function test_database_counts_unchanged_after_all_read_operations(): void
    {
        $this->authenticateAsAdmin();

        $teachersBefore = Teacher::count();
        $usersBefore = User::count();
        $rolesBefore = Role::count();
        $classesBefore = DB::connection()->table('classes')->count();
        $subjectsBefore = DB::connection()->table('subjects')->count();

        $this->getJson('/api/teachers');
        $this->getJson('/api/teachers/1');
        $this->getJson('/api/teachers/export');
        $this->getJson('/api/teachers?search=Eko');
        $this->getJson('/api/teachers?gender=L');
        $this->getJson('/api/teachers?employment_status=PNS');
        $this->getJson('/api/teachers?is_active=1');
        $this->getJson('/api/teachers?is_active=0');
        $this->getJson('/api/teachers?per_page=5');

        $this->assertEquals($teachersBefore, Teacher::count());
        $this->assertEquals($usersBefore, User::count());
        $this->assertEquals($rolesBefore, Role::count());
        $this->assertEquals($classesBefore, DB::connection()->table('classes')->count());
        $this->assertEquals($subjectsBefore, DB::connection()->table('subjects')->count());
    }

    public function test_database_schema_unchanged(): void
    {
        $this->authenticateAsAdmin();

        $before = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $this->getJson('/api/teachers');
        $this->getJson('/api/teachers/export');

        $after = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $strip = fn ($sql) => preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $sql);
        $this->assertEquals(
            $strip($before[0]->{'Create Table'}),
            $strip($after[0]->{'Create Table'})
        );
    }
}
