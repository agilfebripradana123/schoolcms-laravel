<?php

namespace Tests\Feature\PPDB;

use App\Models\Academic\AcademicYear;
use App\Models\PPDB\Registrant;
use App\Models\Students\Guardian;
use App\Models\Students\Student;
use App\Models\Students\StudentParent;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PPDBIntegrationTest extends TestCase
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

    private function createTestRegistration(): Registrant
    {
        $nisn = '9'.str_pad((string) mt_rand(0, 999999999999999), 15, '0', STR_PAD_LEFT);

        return Registrant::create([
            'registration_number' => 'PPDB-INT-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'full_name' => 'Integrasi Test '.mt_rand(100, 999),
            'email' => 'integrasi.'.mt_rand(100000, 999999).'@test.local',
            'gender' => 'L',
            'nisn' => $nisn,
            'nik' => '32'.str_pad((string) mt_rand(0, 999999999999999), 14, '0', STR_PAD_LEFT),
            'religion' => 'islam',
            'birth_place' => 'Bandung',
            'birth_date' => '2010-05-01',
            'address' => 'Jl. Test No. 1',
            'previous_school' => 'SMP Test',
            'phone' => '0812000000'.mt_rand(10, 99),
            'father_name' => 'Bapak Integrasi',
            'father_nik' => '12'.str_pad((string) mt_rand(0, 999999999999999), 14, '0', STR_PAD_LEFT),
            'father_birth_year' => 1980,
            'father_education' => 's1',
            'father_occupation' => 'PNS',
            'father_income' => 5000000,
            'mother_name' => 'Ibu Integrasi',
            'mother_nik' => '13'.str_pad((string) mt_rand(0, 999999999999999), 14, '0', STR_PAD_LEFT),
            'mother_birth_year' => 1982,
            'mother_education' => 'sma',
            'mother_occupation' => 'IRT',
            'mother_income' => 0,
            'guardian_name' => 'Kakek Integrasi',
            'guardian_nik' => '14'.str_pad((string) mt_rand(0, 999999999999999), 14, '0', STR_PAD_LEFT),
            'guardian_birth_year' => 1955,
            'guardian_education' => 'sd',
            'guardian_occupation' => 'Pensiunan',
            'guardian_income' => 3000000,
            'guardian_phone' => '081200000011',
            'guardian_address' => 'Jl. Wali No. 1',
            'academic_year_id' => AcademicYear::orderBy('id')->value('id'),
            'registration_path' => 'reguler',
            'program_choice' => 'ipa',
            'registration_date' => '2026-05-01',
        ]);
    }

    private function advanceToReRegistrationVerified(Registrant $registrant): void
    {
        // Registrant dibuat "draft". Field status workflow tidak ada di $fillable,
        // maka naikkan ke "submitted" via forceFill (meniru state setelah submit).
        $registrant->forceFill([
            'status' => 'submitted',
            'verification_status' => 'pending',
            'selection_status' => 'pending',
            're_registration_status' => 'pending',
        ])->save();

        // verify
        $this->postJson('/api/registrations/'.$registrant->id.'/verify')->assertStatus(200);
        // select
        $this->postJson('/api/registrations/'.$registrant->id.'/select', [
            'selection_score' => 88,
        ])->assertStatus(200);
        // re-register
        $this->postJson('/api/registrations/'.$registrant->id.'/re-register')->assertStatus(200);
        // verify re-registration (triggers materialization)
        $this->postJson('/api/registrations/'.$registrant->id.'/verify-re-registration', [
            're_registration_notes' => 'ok',
        ])->assertStatus(200);
    }

    private function cleanupTestData(): void
    {
        $registrants = Registrant::withTrashed()
            ->where('registration_number', 'LIKE', 'PPDB-INT-%')
            ->pluck('id');

        foreach ($registrants as $rid) {
            DB::connection()->statement('DELETE FROM audit_logs WHERE model="Registrant" AND model_id=?', [$rid]);
        }

        // Hapus student turunan + data terkait (cascade), lalu user.
        $studentIds = DB::connection()
            ->table('registrants')
            ->where('registration_number', 'LIKE', 'PPDB-INT-%')
            ->pluck('student_id')
            ->filter();

        foreach ($studentIds as $sid) {
            DB::connection()->table('students')->where('id', $sid)->delete();
        }

        $usernames = DB::connection()
            ->table('registrants')
            ->where('registration_number', 'LIKE', 'PPDB-INT-%')
            ->pluck('nisn')
            ->filter();

        foreach ($usernames as $u) {
            DB::connection()->table('users')->where('username', $u)->delete();
        }

        DB::connection()->statement('DELETE FROM registrants WHERE registration_number LIKE "PPDB-INT-%"');
    }

    public function test_verify_re_registration_creates_student_user_parents_guardians(): void
    {
        $this->authenticateAsAdmin();

        $reg = $this->createTestRegistration();
        $regId = $reg->id;

        $this->advanceToReRegistrationVerified($reg->fresh());

        $reg->refresh();
        $this->assertNotNull($reg->student_id, 'registrants.student_id must be set after verification');

        $student = Student::find($reg->student_id);
        $this->assertNotNull($student, 'Student must be created');
        $this->assertEquals($reg->full_name, $student->name);
        $this->assertEquals($reg->nisn, $student->nisn);
        $this->assertNotNull($student->user_id, 'Student must link to a user');

        $user = User::find($student->user_id);
        $this->assertNotNull($user, 'User must be created');
        $this->assertEquals('Siswa', $user->role->name);
        $this->assertEquals($reg->nisn, $user->username);
        $this->assertEquals($reg->email, $user->email);

        $parent = StudentParent::where('student_id', $student->id)->first();
        $this->assertNotNull($parent, 'Parents row must be created');
        $this->assertEquals('Bapak Integrasi', $parent->father_name);
        $this->assertEquals('Ibu Integrasi', $parent->mother_name);

        $guardian = Guardian::where('student_id', $student->id)->first();
        $this->assertNotNull($guardian, 'Guardian must be created');
        $this->assertEquals('Kakek Integrasi', $guardian->name);
    }

    public function test_verify_re_registration_is_idempotent(): void
    {
        $this->authenticateAsAdmin();

        $reg = $this->createTestRegistration();

        $this->advanceToReRegistrationVerified($reg->fresh());

        // Panggil ulang verify-re-registration -> tidak boleh membuat duplikat.
        $this->postJson('/api/registrations/'.$reg->id.'/verify-re-registration', [
            're_registration_notes' => 'lagi',
        ])->assertStatus(422); // sudah diverifikasi -> tolak, anteseden guard status

        $studentId = $reg->fresh()->student_id;

        $this->assertEquals(1, Student::where('id', $studentId)->count());
        $this->assertEquals(1, StudentParent::where('student_id', $studentId)->count());
        $this->assertEquals(1, Guardian::where('student_id', $studentId)->count());
    }
}
