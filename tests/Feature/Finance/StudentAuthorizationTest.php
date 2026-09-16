<?php

namespace Tests\Feature\Finance;

use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 — Student identity & authorization foundation.
 *
 * Verifies the users ↔ students 1:1 linkage (students.user_id UNIQUE + FK
 * SET NULL) and the EnsureStudentProfile portal middleware contract:
 *
 *   guest                          → 401
 *   authenticated non-Siswa        → 403
 *   Siswa without linked Student   → 403 (explicit message)
 *   Siswa with linked Student      → 200 and the identity-scoped profile
 *
 * The authenticated student scope always comes from the authenticated user's
 * link — request-provided `student_id` can never substitute another student.
 *
 * Created rows use PH8-/PH8U- markers and are removed in setUp/tearDown.
 */
class StudentAuthorizationTest extends TestCase
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

    private function roleId(string $name): int
    {
        return (int) Role::where('name', $name)->value('id');
    }

    private function createUser(string $roleName, array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'PH8U-'.$roleName.'-'.mt_rand(100000, 999999),
            'name' => 'PH8-'.$roleName,
            'email' => 'ph8.'.$roleName.'.'.mt_rand(100000, 999999).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $this->roleId($roleName),
        ], $overrides));
    }

    private function createStudent(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'nisn' => 'PH8-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => 'PH8-'.str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'name' => 'PH8-Test Student',
            'gender' => 'L',
            'birth_place' => 'Test City',
            'birth_date' => '2008-01-01',
            'address' => 'Test Address',
        ], $overrides));
    }

    private function cleanupTestData(): void
    {
        $db = DB::connection();

        $db->table('users')->where('username', 'like', 'PH8U-%')->delete();
        Student::where('nisn', 'like', 'PH8-%')
            ->orWhere('nis', 'like', 'PH8-%')
            ->forceDelete();
    }

    // ─── Authentication ────────────────────────────────────────

    public function test_guest_student_profile_returns_401(): void
    {
        $this->getJson('/api/student/profile')->assertStatus(401);
    }

    public function test_authenticated_non_siswa_returns_403(): void
    {
        foreach (['Guru', 'Admin', 'Administrator'] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $response = $this->getJson('/api/student/profile');

            $response->assertStatus(403);
            $response->assertJsonPath('success', false);
        }
    }

    public function test_siswa_without_linked_student_returns_403(): void
    {
        Sanctum::actingAs($this->createUser('Siswa'));

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Student profile is not linked to this account.',
            'data' => null,
        ]);
    }

    public function test_siswa_with_linked_student_succeeds(): void
    {
        $user = $this->createUser('Siswa');
        $student = $this->createStudent(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $student->id);
        $response->assertJsonPath('data.user_id', $user->id);
    }

    // ─── Identity resolution ───────────────────────────────────

    public function test_request_student_id_cannot_substitute_identity(): void
    {
        $user = $this->createUser('Siswa');
        $student = $this->createStudent(['user_id' => $user->id]);
        $otherStudent = $this->createStudent();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/student/profile?student_id={$otherStudent->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $student->id);
        $this->assertNotSame($otherStudent->id, $response->json('data.id'));
    }

    public function test_identity_resolution_uses_user_student_link(): void
    {
        $user = $this->createUser('Siswa');
        $student = $this->createStudent(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->assertSame($student->id, $user->studentProfile()->value('id'));
        $this->assertSame($user->id, $student->user_id);
    }

    // ─── Database constraint ───────────────────────────────────

    public function test_multiple_students_may_have_null_user_id(): void
    {
        $one = $this->createStudent();
        $two = $this->createStudent();
        $three = $this->createStudent();

        $this->assertNull($one->user_id);
        $this->assertNull($two->user_id);
        $this->assertNull($three->user_id);
    }

    public function test_one_user_cannot_link_to_two_students(): void
    {
        $user = $this->createUser('Siswa');
        $this->createStudent(['user_id' => $user->id]);

        try {
            $this->createStudent(['user_id' => $user->id]);
            $this->fail('Expected a unique constraint violation for duplicate students.user_id.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'Duplicate entry',
                $e->getMessage(),
                'Expected the MySQL unique index to reject a second link.'
            );
        }
    }

    public function test_invalid_user_fk_is_rejected(): void
    {
        try {
            $this->createStudent(['user_id' => 999999999]);
            $this->fail('Expected a foreign key violation for an unknown user.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'a foreign key constraint fails',
                $e->getMessage(),
                'Expected students.user_id FK to reject an unknown user id.'
            );
        }
    }

    public function test_valid_user_student_linkage_succeeds(): void
    {
        $user = $this->createUser('Siswa');
        $student = $this->createStudent(['user_id' => $user->id]);

        $stored = DB::connection()->table('students')->where('id', $student->id)->value('user_id');
        $this->assertSame($user->id, (int) $stored);
    }

    // ─── Admin routes unaffected ───────────────────────────────

    public function test_admin_finance_routes_remain_accessible(): void
    {
        Sanctum::actingAs($this->createUser('Admin'));

        $this->getJson('/api/billings')->assertStatus(200);
        $this->getJson('/api/fee-types')->assertStatus(200);
    }
}
