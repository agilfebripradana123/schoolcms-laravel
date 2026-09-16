<?php

namespace Tests\Feature\Teacher;

use App\Models\System\Role;
use App\Models\Staff\Teacher;
use App\Models\System\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TeacherImportTest extends TestCase
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
            'name' => 'Test User Phase7 ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function cleanupTestTeachers(): void
    {
        Teacher::where('teacher_code', 'like', 'TEST-IMP-PH7-%')->forceDelete();
        Teacher::where('nip', 'like', '8888%')->forceDelete();
    }

    private function cleanupTestUsers(): void
    {
        User::where('username', 'like', 'siswa_%')->forceDelete();
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

    private function getUniqueTeacherCode(): string
    {
        return 'TEST-IMP-PH7-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function getUniqueNip(): string
    {
        return '8888' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
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

    private function createExcelFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $coord = $this->columnLetter($colIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($coord, $value);
            }
        }

        $tempPath = sys_get_temp_dir() . '/import_test_' . mt_rand(100000, 999999) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function createXlsFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $coord = $this->columnLetter($colIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($coord, $value);
            }
        }

        $tempPath = sys_get_temp_dir() . '/import_test_' . mt_rand(100000, 999999) . '.xls';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'test_import.xls', 'application/vnd.ms-excel', null, true);
    }

    private function createValidRow(array $overrides = []): array
    {
        $defaults = [
            $this->getUniqueTeacherCode(),
            $this->getUniqueNip(),
            'Test Teacher Phase7',
            'L',
            'Medan',
            '1990-01-15',
            '08123456789',
            'test.phase7@example.com',
            'Islam',
            'Jl. Test No. 1',
            'S1',
            'Informatika',
            'PNS',
            '2020-01-01',
        ];

        return array_values(array_replace($defaults, $overrides));
    }

    private function createHeaderAndRow(array $rowOverrides = []): array
    {
        return [
            $this->getExpectedHeaders(),
            $this->createValidRow($rowOverrides),
        ];
    }

    // ─── Auth Tests ──────────────────────────────────────────────

    public function test_unauthenticated_import_returns_401(): void
    {
        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_guru_cannot_import(): void
    {
        $this->authenticateAsGuru();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_cannot_import(): void
    {
        $this->authenticateAsSiswa();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_import(): void
    {
        $this->authenticateAsAdmin();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeachers();
    }

    public function test_administrator_can_import(): void
    {
        $this->authenticateAsAdministrator();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeachers();
    }

    // ─── File Validation Tests ───────────────────────────────────

    public function test_missing_file_returns_422(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers/import', []);

        $response->assertStatus(422);
    }

    public function test_invalid_extension_returns_422(): void
    {
        $this->authenticate();

        $tempPath = tempnam(sys_get_temp_dir(), 'import_test_');
        file_put_contents($tempPath, 'not an excel file');

        $file = new UploadedFile($tempPath, 'test.csv', 'text/csv', null, true);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_teacher_code_returns_error(): void
    {
        $this->authenticate();

        $row = $this->createValidRow();
        $row[0] = str_repeat('A', 21);

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_invalid_nip_returns_error(): void
    {
        $this->authenticate();

        $row = $this->createValidRow();
        $row[1] = '';

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_duplicate_teacher_code_in_database_rejected(): void
    {
        $this->authenticate();

        $existing = Teacher::whereNotNull('teacher_code')->first();

        $row = $this->createValidRow();
        $row[0] = $existing->teacher_code;

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    public function test_duplicate_nip_in_database_rejected(): void
    {
        $this->authenticate();

        $existing = Teacher::first();

        $row = $this->createValidRow();
        $row[1] = $existing->nip;

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    public function test_duplicate_teacher_code_in_same_file_rejected(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip1 = $this->getUniqueNip();
        $nip2 = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip1]),
            $this->createValidRow([0 => $code, 1 => $nip2]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['failed']);

        $this->cleanupTestTeachers();
    }

    public function test_duplicate_nip_in_same_file_rejected(): void
    {
        $this->authenticate();

        $code1 = $this->getUniqueTeacherCode();
        $code2 = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code1, 1 => $nip]),
            $this->createValidRow([0 => $code2, 1 => $nip]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, $data['failed']);

        $this->cleanupTestTeachers();
    }

    public function test_invalid_gender_rejected(): void
    {
        $this->authenticate();

        $row = $this->createValidRow();
        $row[3] = 'X';

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    public function test_invalid_email_rejected(): void
    {
        $this->authenticate();

        $row = $this->createValidRow();
        $row[7] = 'not-an-email';

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    public function test_invalid_date_rejected(): void
    {
        $this->authenticate();

        $row = $this->createValidRow();
        $row[5] = 'not-a-date';

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $row,
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);

        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    // ─── Date Conversion Tests ───────────────────────────────────

    public function test_yyyy_mm_dd_is_converted_correctly(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip, 5 => '1990-01-15', 13 => '2020-06-01']),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertEquals('1990-01-15', $teacher->birth_date->format('Y-m-d'));
        $this->assertEquals('2020-06-01', $teacher->join_date->format('Y-m-d'));

        $this->cleanupTestTeachers();
    }

    public function test_dd_mm_yyyy_is_converted_correctly(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip, 5 => '15/01/1990', 13 => '01/06/2020']),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertEquals('1990-01-15', $teacher->birth_date->format('Y-m-d'));
        $this->assertEquals('2020-06-01', $teacher->join_date->format('Y-m-d'));

        $this->cleanupTestTeachers();
    }

    public function test_excel_date_serial_is_converted_correctly(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = $this->getExpectedHeaders();
        foreach ($headers as $colIndex => $value) {
            $coord = $this->columnLetter($colIndex + 1) . '1';
            $sheet->setCellValue($coord, $value);
        }

        $data = $this->createValidRow([0 => $code, 1 => $nip]);
        foreach ($data as $colIndex => $value) {
            $coord = $this->columnLetter($colIndex + 1) . '2';
            $sheet->setCellValue($coord, $value);
        }

        $sheet->setCellValue('F2', 32888);
        $sheet->setCellValue('N2', 43983);

        $tempPath = sys_get_temp_dir() . '/import_test_' . mt_rand(100000, 999999) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $file = new UploadedFile($tempPath, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertEquals('1990-01-15', $teacher->birth_date->format('Y-m-d'));
        $this->assertEquals('2020-06-01', $teacher->join_date->format('Y-m-d'));

        $this->cleanupTestTeachers();
    }

    // ─── XLS Support Tests ───────────────────────────────────────

    public function test_valid_xls_imports_successfully(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createXlsFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);

        $this->cleanupTestTeachers();
    }

    // ─── Success Tests ───────────────────────────────────────────

    public function test_valid_xlsx_imports_successfully(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Teachers imported successfully',
        ]);

        $data = $response->json('data');
        $this->assertEquals(1, $data['total_rows']);
        $this->assertEquals(1, $data['imported']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEmpty($data['errors']);

        $this->cleanupTestTeachers();
    }

    public function test_response_follows_standard_format(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'total_rows',
                'imported',
                'failed',
                'errors',
            ],
        ]);

        $this->cleanupTestTeachers();
    }

    public function test_user_id_remains_null(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->user_id);

        $this->cleanupTestTeachers();
    }

    public function test_is_active_defaults_true(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertTrue($teacher->is_active);

        $this->cleanupTestTeachers();
    }

    public function test_email_duplicate_is_allowed(): void
    {
        $this->authenticate();

        $existing = Teacher::whereNotNull('email')->first();
        if (!$existing) {
            $this->markTestSkipped('No teacher with email found');
        }

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip, 7 => $existing->email]),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeachers();
    }

    // ─── Database Safety Tests ───────────────────────────────────

    public function test_existing_teachers_are_not_modified(): void
    {
        $this->authenticate();

        $existing = Teacher::first();
        $originalFullName = $existing->full_name;
        $originalNip = $existing->nip;

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip]),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $existing->refresh();
        $this->assertEquals($originalFullName, $existing->full_name);
        $this->assertEquals($originalNip, $existing->nip);

        $this->cleanupTestTeachers();
    }

    public function test_users_count_unchanged(): void
    {
        $this->authenticate();

        $usersBefore = $this->app['db']->connection()
            ->table('users')
            ->whereNull('deleted_at')
            ->count();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $usersAfter = $this->app['db']->connection()
            ->table('users')
            ->whereNull('deleted_at')
            ->count();

        $this->assertEquals($usersBefore, $usersAfter);

        $this->cleanupTestTeachers();
    }

    public function test_roles_count_unchanged(): void
    {
        $this->authenticate();

        $rolesBefore = $this->app['db']->connection()
            ->table('roles')
            ->count();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $rolesAfter = $this->app['db']->connection()
            ->table('roles')
            ->count();

        $this->assertEquals($rolesBefore, $rolesAfter);

        $this->cleanupTestTeachers();
    }

    public function test_teacher_count_increases_only_by_imported_rows(): void
    {
        $this->authenticate();

        $countBefore = Teacher::count();

        $code1 = $this->getUniqueTeacherCode();
        $nip1 = $this->getUniqueNip();
        $code2 = $this->getUniqueTeacherCode();
        $nip2 = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code1, 1 => $nip1]),
            $this->createValidRow([0 => $code2, 1 => $nip2]),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $countAfter = Teacher::count();
        $this->assertEquals($countBefore + 2, $countAfter);

        $this->cleanupTestTeachers();
    }

    public function test_database_structure_unchanged(): void
    {
        $this->authenticate();

        $before = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow(),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $after = $this->app['db']->connection()
            ->select('SHOW CREATE TABLE teachers');

        $stripAutoIncrement = fn ($sql) => preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $sql);
        $this->assertEquals(
            $stripAutoIncrement($before[0]->{'Create Table'}),
            $stripAutoIncrement($after[0]->{'Create Table'})
        );

        $this->cleanupTestTeachers();
    }

    // ─── Header Validation Tests ─────────────────────────────────

    public function test_invalid_header_returns_422(): void
    {
        $this->authenticate();

        $file = $this->createExcelFile([
            ['Wrong', 'Headers', 'Here'],
            $this->createValidRow(),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid Excel header',
        ]);
    }

    // ─── Partial Success Tests ───────────────────────────────────

    public function test_partial_import_with_errors(): void
    {
        $this->authenticate();

        $validCode = $this->getUniqueTeacherCode();
        $validNip = $this->getUniqueNip();

        $existing = Teacher::first();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $validCode, 1 => $validNip]),
            $this->createValidRow([0 => $existing->teacher_code, 1 => '8888999900000001']),
        ]);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Teacher import completed with errors',
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['total_rows']);
        $this->assertEquals(1, $data['imported']);
        $this->assertEquals(1, $data['failed']);
        $this->assertNotEmpty($data['errors']);

        $teacher = Teacher::where('teacher_code', $validCode)->first();
        $this->assertNotNull($teacher);

        $this->cleanupTestTeachers();
    }

    public function test_imported_teacher_has_correct_data(): void
    {
        $this->authenticate();

        $code = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $file = $this->createExcelFile([
            $this->getExpectedHeaders(),
            $this->createValidRow([0 => $code, 1 => $nip, 2 => 'Budi Santoso', 3 => 'P', 4 => 'Jakarta', 7 => 'budi@example.com', 8 => 'Kristen', 10 => 'S2', 11 => 'Matematika', 12 => 'PPPK']),
        ]);

        $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $teacher = Teacher::where('teacher_code', $code)->first();
        $this->assertNotNull($teacher);
        $this->assertEquals($nip, $teacher->nip);
        $this->assertEquals('Budi Santoso', $teacher->full_name);
        $this->assertEquals('P', $teacher->gender);
        $this->assertEquals('Jakarta', $teacher->birth_place);
        $this->assertEquals('budi@example.com', $teacher->email);
        $this->assertEquals('Kristen', $teacher->religion);
        $this->assertEquals('S2', $teacher->last_education);
        $this->assertEquals('Matematika', $teacher->major);
        $this->assertEquals('PPPK', $teacher->employment_status);
        $this->assertTrue($teacher->is_active);
        $this->assertNull($teacher->user_id);

        $this->cleanupTestTeachers();
    }

    public function test_empty_file_returns_error(): void
    {
        $this->authenticate();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Wrong Header');

        $tempPath = sys_get_temp_dir() . '/import_test_' . mt_rand(100000, 999999) . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $file = new UploadedFile($tempPath, 'empty.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/teachers/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid Excel header',
        ]);
    }

    // Tests end here
}
