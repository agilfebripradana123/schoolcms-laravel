<?php

namespace Tests\Feature\Teacher;

use App\Models\System\Role;
use App\Models\Staff\Teacher;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TeacherExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestTeachers();
        $this->cleanupTestUsers();
    }

    // ─── Helpers ─────────────────────────────────────────────────

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
            'name' => 'Test User Phase8 ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function cleanupTestTeachers(): void
    {
        Teacher::where('teacher_code', 'like', 'TEST-GR-PH6-%')->forceDelete();
        Teacher::where('nip', 'like', '9999%')->forceDelete();
        Teacher::where('teacher_code', 'like', 'TEST-IMP-PH7-%')->forceDelete();
        Teacher::where('nip', 'like', '8888%')->forceDelete();
        Teacher::where('teacher_code', 'like', 'TEST-EXP-PH8-%')->forceDelete();
        Teacher::where('nip', 'like', '7777%')->forceDelete();
    }

    private function cleanupTestUsers(): void
    {
        User::where('username', 'like', 'test_%')->forceDelete();
        User::where('username', 'like', 'siswa_%')->forceDelete();
    }

    private function getUniqueTeacherCode(): string
    {
        return 'TEST-EXP-PH8-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function getUniqueNip(): string
    {
        return '7777' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function createTestTeacher(array $overrides = []): Teacher
    {
        $defaults = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'full_name' => 'Test Teacher Phase8',
            'gender' => 'L',
            'is_active' => true,
        ];

        return Teacher::create(array_merge($defaults, $overrides));
    }

    private function getExpectedHeaders(): array
    {
        return [
            'Kode Guru',
            'NIP',
            'Nama Lengkap',
            'Jenis Kelamin',
            'Tempat Lahir',
            'Tanggal Lahir',
            'No. HP',
            'Email',
            'Agama',
            'Alamat',
            'Pendidikan Terakhir',
            'Jurusan',
            'Status Kepegawaian',
            'Tanggal Bergabung',
        ];
    }

    private function parseExcelResponse($response): array
    {
        $tempPath = sys_get_temp_dir() . '/export_test_' . mt_rand(100000, 999999) . '.xlsx';

        $baseResponse = $response->baseResponse ?? $response;

        if ($baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $file = $baseResponse->getFile();
            copy($file->getPathname(), $tempPath);
        } else {
            file_put_contents($tempPath, $response->getContent());
        }

        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        @unlink($tempPath);

        return $rows;
    }

    // ─── Auth Tests ──────────────────────────────────────────────

    public function test_unauthenticated_export_returns_401(): void
    {
        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(401);
    }

    public function test_admin_can_access_export(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(200);
    }

    public function test_administrator_can_access_export(): void
    {
        $this->authenticateAsAdministrator();

        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(200);
    }

    public function test_guru_can_access_export(): void
    {
        $this->authenticateAsGuru();

        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(200);
    }

    public function test_siswa_can_access_export(): void
    {
        $this->authenticateAsSiswa();

        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(200);
    }

    // ─── Response Format Tests ───────────────────────────────────

    public function test_export_returns_200(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');

        $response->assertStatus(200);
    }

    public function test_export_content_type_is_excel(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_has_content_disposition_attachment(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');

        $response->assertHeader('Content-Disposition');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
    }

    public function test_export_filename_has_xlsx_extension(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('teachers.xlsx', $disposition);
    }

    // ─── Heading Tests ───────────────────────────────────────────

    public function test_excel_has_correct_headings(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');
        $rows = $this->parseExcelResponse($response);

        $this->assertNotEmpty($rows);
        $this->assertEquals($this->getExpectedHeaders(), $rows[0]);
    }

    // ─── Data Row Tests ──────────────────────────────────────────

    public function test_export_row_count_matches_active_teachers(): void
    {
        $this->authenticate();

        $activeCount = Teacher::whereNull('deleted_at')->count();

        $response = $this->getJson('/api/teachers/export');
        $rows = $this->parseExcelResponse($response);

        $dataRows = count($rows) - 1;
        $this->assertEquals($activeCount, $dataRows);
    }

    public function test_soft_deleted_teacher_not_in_export(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacher->delete();

        $response = $this->getJson('/api/teachers/export');
        $rows = $this->parseExcelResponse($response);

        $found = false;
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i][0] === $teacher->teacher_code) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Soft-deleted teacher should not appear in export');

        $teacher->forceDelete();
    }

    public function test_exported_teacher_has_correct_data(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher([
            'full_name' => 'Export Test Teacher',
            'gender' => 'P',
            'birth_place' => 'Jakarta',
            'birth_date' => '1995-05-20',
            'phone' => '081234567890',
            'email' => 'export.test@example.com',
            'religion' => 'Islam',
            'address' => 'Jl. Test No. 1',
            'last_education' => 'S1',
            'major' => 'Informatika',
            'employment_status' => 'PNS',
            'join_date' => '2020-01-15',
        ]);

        $response = $this->getJson('/api/teachers/export');
        $rows = $this->parseExcelResponse($response);

        $found = null;
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i][0] === $teacher->teacher_code) {
                $found = $rows[$i];
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertEquals($teacher->teacher_code, $found[0]);
        $this->assertEquals($teacher->nip, $found[1]);
        $this->assertEquals('Export Test Teacher', $found[2]);
        $this->assertEquals('P', $found[3]);
        $this->assertEquals('Jakarta', $found[4]);
        $this->assertEquals('1995-05-20', $found[5]);
        $this->assertEquals('081234567890', $found[6]);
        $this->assertEquals('export.test@example.com', $found[7]);
        $this->assertEquals('Islam', $found[8]);
        $this->assertEquals('Jl. Test No. 1', $found[9]);
        $this->assertEquals('S1', $found[10]);
        $this->assertEquals('Informatika', $found[11]);
        $this->assertEquals('PNS', $found[12]);
        $this->assertEquals('2020-01-15', $found[13]);

        $teacher->forceDelete();
    }

    // ─── Search Tests ────────────────────────────────────────────

    public function test_export_search_full_name_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['full_name' => 'Budi Santoso Export']);

        $response = $this->getJson('/api/teachers/export?search=Budi Santoso');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $found = false;
        foreach ($dataRows as $row) {
            if ($row[0] === $teacher->teacher_code) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    public function test_export_search_teacher_code_works(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $teacher = $this->createTestTeacher(['teacher_code' => $code]);

        $response = $this->getJson('/api/teachers/export?search=' . $code);
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $codes = array_column($dataRows, 0);
        $this->assertContains($code, $codes);

        $teacher->forceDelete();
    }

    public function test_export_search_nip_works(): void
    {
        $this->authenticate();

        $nip = $this->getUniqueNip();
        $teacher = $this->createTestTeacher(['nip' => $nip]);

        $response = $this->getJson('/api/teachers/export?search=' . $nip);
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $nips = array_column($dataRows, 1);
        $this->assertContains($nip, $nips);

        $teacher->forceDelete();
    }

    public function test_export_search_phone_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['phone' => '089998887777']);

        $response = $this->getJson('/api/teachers/export?search=089998887777');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $found = false;
        foreach ($dataRows as $row) {
            if ($row[0] === $teacher->teacher_code) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    public function test_export_does_not_search_email(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher([
            'full_name' => 'Eko Email Test',
            'email' => 'eko.email@sekolah.sch.id',
        ]);

        $emailDomain = 'sekolah.sch.id';
        $response = $this->getJson('/api/teachers/export?search=' . $emailDomain);
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $foundByEmail = false;
        foreach ($dataRows as $row) {
            if (str_contains($row[7] ?? '', $emailDomain)) {
                $foundByEmail = true;
            }
        }
        $this->assertFalse($foundByEmail);

        $teacher->forceDelete();
    }

    // ─── Filter Tests ────────────────────────────────────────────

    public function test_export_filter_gender_l_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['gender' => 'L']);

        $response = $this->getJson('/api/teachers/export?gender=L');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        foreach ($dataRows as $row) {
            $this->assertEquals('L', $row[3]);
        }

        $teacher->forceDelete();
    }

    public function test_export_filter_gender_p_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['gender' => 'P']);

        $response = $this->getJson('/api/teachers/export?gender=P');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $found = false;
        foreach ($dataRows as $row) {
            if ($row[0] === $teacher->teacher_code) {
                $found = true;
            }
            $this->assertEquals('P', $row[3]);
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    public function test_export_filter_employment_status_pns_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['employment_status' => 'PNS']);

        $response = $this->getJson('/api/teachers/export?employment_status=PNS');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        foreach ($dataRows as $row) {
            $this->assertEquals('PNS', $row[12]);
        }

        $teacher->forceDelete();
    }

    public function test_export_filter_is_active_1_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['is_active' => true]);

        $response = $this->getJson('/api/teachers/export?is_active=1');
        $rows = $this->parseExcelResponse($response);

        $found = false;
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i][0] === $teacher->teacher_code) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    public function test_export_filter_is_active_0_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['is_active' => false]);

        $response = $this->getJson('/api/teachers/export?is_active=0');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $found = false;
        foreach ($dataRows as $row) {
            if ($row[0] === $teacher->teacher_code) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    public function test_export_combined_filters_work(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher([
            'gender' => 'L',
            'employment_status' => 'PNS',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/teachers/export?gender=L&employment_status=PNS&is_active=1');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $found = false;
        foreach ($dataRows as $row) {
            if ($row[0] === $teacher->teacher_code) {
                $found = true;
            }
            $this->assertEquals('L', $row[3]);
        }
        $this->assertTrue($found);

        $teacher->forceDelete();
    }

    // ─── Validation Tests ────────────────────────────────────────

    public function test_invalid_gender_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export?gender=X');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
    }

    public function test_invalid_is_active_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export?is_active=5');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
    }

    // ─── Database Safety Tests ───────────────────────────────────

    public function test_database_teacher_count_unchanged(): void
    {
        $this->authenticate();

        $before = Teacher::count();

        $this->getJson('/api/teachers/export');

        $after = Teacher::count();
        $this->assertEquals($before, $after);
    }

    public function test_database_users_count_unchanged(): void
    {
        $this->authenticate();

        $before = User::count();

        $this->getJson('/api/teachers/export');

        $after = User::count();
        $this->assertEquals($before, $after);
    }

    public function test_database_roles_count_unchanged(): void
    {
        $this->authenticate();

        $before = Role::count();

        $this->getJson('/api/teachers/export');

        $after = Role::count();
        $this->assertEquals($before, $after);
    }

    public function test_database_classes_count_unchanged(): void
    {
        $this->authenticate();

        $before = DB::connection()->table('classes')->count();

        $this->getJson('/api/teachers/export');

        $after = DB::connection()->table('classes')->count();
        $this->assertEquals($before, $after);
    }

    public function test_database_subjects_count_unchanged(): void
    {
        $this->authenticate();

        $before = DB::connection()->table('subjects')->count();

        $this->getJson('/api/teachers/export');

        $after = DB::connection()->table('subjects')->count();
        $this->assertEquals($before, $after);
    }

    public function test_database_structure_unchanged(): void
    {
        $this->authenticate();

        $before = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $this->getJson('/api/teachers/export');

        $after = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $stripAutoIncrement = fn ($sql) => preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $sql);
        $this->assertEquals(
            $stripAutoIncrement($before[0]->{'Create Table'}),
            $stripAutoIncrement($after[0]->{'Create Table'})
        );
    }

    // ─── Sorting Test ────────────────────────────────────────────

    public function test_export_sorted_by_id_asc(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/export');
        $rows = $this->parseExcelResponse($response);

        $dataRows = array_slice($rows, 1);
        $ids = [];
        foreach ($dataRows as $row) {
            $teacher = Teacher::where('teacher_code', $row[0])->first();
            if ($teacher) {
                $ids[] = $teacher->id;
            }
        }

        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertEquals($sortedIds, $ids);
    }
}
