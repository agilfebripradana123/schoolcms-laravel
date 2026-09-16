<?php

namespace Tests\Feature\Teacher;

use App\Models\System\Role;
use App\Models\Academic\SchoolClass;
use App\Models\Staff\Teacher;
use App\Models\System\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherApiTest extends TestCase
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

    private function getUniqueTeacherCode(): string
    {
        return 'TEST-GR-PH6-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function getUniqueNip(): string
    {
        return '9999' . str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function createTestTeacher(array $overrides = []): Teacher
    {
        $defaults = [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'full_name' => 'Test Teacher Phase6',
            'gender' => 'L',
            'is_active' => true,
        ];

        return Teacher::create(array_merge($defaults, $overrides));
    }

    private function createTestUser(int $roleId, string $prefix = 'test'): User
    {
        return User::create([
            'username' => $prefix . '_' . mt_rand(100000, 999999),
            'name' => 'Test User Phase6 ' . $prefix,
            'email' => $prefix . '.' . mt_rand(100000, 999999) . '@test.local',
            'password' => 'password',
            'is_active' => true,
            'role_id' => $roleId,
        ]);
    }

    private function cleanupTestTeacher(Teacher $teacher): void
    {
        $teacher->forceDelete();
    }

    private function cleanupTestTeachers(): void
    {
        Teacher::where('teacher_code', 'like', 'TEST-GR-PH6-%')->forceDelete();
        Teacher::where('nip', 'like', '9999%')->forceDelete();
    }

    private function cleanupTestUsers(): void
    {
        User::where('username', 'like', 'test_%')->forceDelete();
        User::where('username', 'like', 'siswa_%')->forceDelete();
    }

    private function createTestUserAndCleanup(int $roleId, string $prefix): User
    {
        $user = $this->createTestUser($roleId, $prefix);
        $this->beforeApplicationDestroyed(function () use ($user) {
            if ($user->exists) {
                $user->forceDelete();
            }
        });

        return $user;
    }

    // ─── GET Tests ───────────────────────────────────────────────

    public function test_get_teachers_returns_200(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers');

        $response->assertStatus(200);
    }

    public function test_get_teachers_returns_json(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers');

        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_index_response_has_success_message_data_meta(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers');

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
            'message' => 'Teachers retrieved successfully',
        ]);
    }

    public function test_pagination_metadata_values(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers');

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(10, $meta['per_page']);
        $this->assertIsInt($meta['total']);
        $this->assertGreaterThanOrEqual(1, $meta['last_page']);
    }

    public function test_pagination_per_page_works(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?per_page=5');

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertEquals(5, $meta['per_page']);
        $this->assertLessThanOrEqual(5, count($response->json('data')));
    }

    public function test_get_teacher_by_id_returns_200(): void
    {
        $this->authenticate();

        $teacher = Teacher::first();

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
    }

    public function test_get_teacher_by_id_returns_correct_data(): void
    {
        $this->authenticate();

        $teacher = Teacher::first();

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertJson([
            'success' => true,
            'message' => 'Teacher retrieved successfully',
            'data' => [
                'id' => $teacher->id,
                'nip' => $teacher->nip,
                'full_name' => $teacher->full_name,
            ],
        ]);
    }

    public function test_show_response_has_success_message_data(): void
    {
        $this->authenticate();

        $teacher = Teacher::first();

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
        ]);
    }

    public function test_get_teacher_invalid_id_returns_404(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/99999');

        $response->assertStatus(404);
    }

    public function test_404_response_format(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers/99999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Teacher not found',
            'data' => null,
        ]);
    }

    public function test_search_teacher_by_full_name(): void
    {
        $this->authenticate();

        $teacher = Teacher::where('full_name', 'like', '%Eko%')->first();

        if (!$teacher) {
            $this->markTestSkipped('No teacher with name containing Eko found');
        }

        $response = $this->getJson('/api/teachers?search=Eko');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'full_name' => $teacher->full_name,
        ]);
    }

    public function test_search_teacher_by_teacher_code(): void
    {
        $this->authenticate();

        $teacher = Teacher::where('teacher_code', 'like', '%GR-001%')->first();

        if (!$teacher) {
            $this->markTestSkipped('No teacher with code GR-001 found');
        }

        $response = $this->getJson('/api/teachers?search=GR-001');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'teacher_code' => $teacher->teacher_code,
        ]);
    }

    public function test_search_teacher_by_nip(): void
    {
        $this->authenticate();

        $teacher = Teacher::where('nip', 'like', '%19840122201041617%')->first();

        if (!$teacher) {
            $this->markTestSkipped('No teacher with NIP 19840122201041617 found');
        }

        $response = $this->getJson('/api/teachers?search=19840122201041617');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'nip' => $teacher->nip,
        ]);
    }

    public function test_empty_search_result_returns_empty_data(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?search=THIS-DATA-DOES-NOT-EXIST');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [],
        ]);
    }

    public function test_filter_gender_l(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?gender=L');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $teacher) {
            $this->assertEquals('L', $teacher['gender']);
        }
    }

    public function test_filter_gender_p(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?gender=P');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $teacher) {
            $this->assertEquals('P', $teacher['gender']);
        }
    }

    public function test_filter_employment_status_pns(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?employment_status=PNS');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $teacher) {
            $this->assertEquals('PNS', $teacher['employment_status']);
        }
    }

    public function test_filter_is_active(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?is_active=1');

        $response->assertStatus(200);

        $data = $response->json('data');
        foreach ($data as $teacher) {
            $this->assertTrue($teacher['is_active']);
        }
    }

    public function test_invalid_page_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?page=abc');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
        $response->assertJsonStructure([
            'errors' => ['page'],
        ]);
    }

    public function test_invalid_per_page_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?per_page=101');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
        $response->assertJsonStructure([
            'errors' => ['per_page'],
        ]);
    }

    public function test_invalid_gender_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?gender=X');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
        $response->assertJsonStructure([
            'errors' => ['gender'],
        ]);
    }

    public function test_invalid_is_active_returns_422(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/teachers?is_active=2');

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
        $response->assertJsonStructure([
            'errors' => ['is_active'],
        ]);
    }

    public function test_soft_deleted_teacher_not_returned(): void
    {
        $this->authenticate();

        $deletedTeacher = Teacher::onlyTrashed()->first();

        if (!$deletedTeacher) {
            $this->markTestSkipped('No soft-deleted teacher found');
        }

        $response = $this->getJson('/api/teachers');

        $response->assertStatus(200);

        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertNotContains($deletedTeacher->id, $ids);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/teachers');

        $response->assertStatus(401);
    }

    // ─── Route Tests ─────────────────────────────────────────────

    public function test_teacher_routes_have_correct_methods(): void
    {
        $routes = Route::getRoutes();
        $routeArray = $routes->getRoutes();

        $teacherGetRoutes = collect($routeArray)->filter(function ($route) {
            return str_contains($route->uri, 'api/teachers') && in_array('GET', $route->methods());
        });
        $this->assertGreaterThanOrEqual(2, $teacherGetRoutes->count());

        $teacherPostRoutes = collect($routeArray)->filter(function ($route) {
            return str_contains($route->uri, 'api/teachers') && in_array('POST', $route->methods());
        });
        $this->assertGreaterThanOrEqual(1, $teacherPostRoutes->count());

        $teacherPutRoutes = collect($routeArray)->filter(function ($route) {
            return str_contains($route->uri, 'api/teachers') && in_array('PUT', $route->methods());
        });
        $this->assertGreaterThanOrEqual(1, $teacherPutRoutes->count());

        $teacherPatchRoutes = collect($routeArray)->filter(function ($route) {
            return str_contains($route->uri, 'api/teachers') && in_array('PATCH', $route->methods());
        });
        $this->assertGreaterThanOrEqual(1, $teacherPatchRoutes->count());

        $teacherDeleteRoutes = collect($routeArray)->filter(function ($route) {
            return str_contains($route->uri, 'api/teachers') && in_array('DELETE', $route->methods());
        });
        $this->assertGreaterThanOrEqual(1, $teacherDeleteRoutes->count());
    }

    // ─── STORE Tests ─────────────────────────────────────────────

    public function test_authenticated_user_can_create_teacher(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'full_name' => 'New Teacher Phase6',
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_unauthenticated_user_cannot_create_teacher(): void
    {
        $response = $this->postJson('/api/teachers', [
            'teacher_code' => 'TEST-GR-PH6-UNAUTH',
            'nip' => '999999999999999UNA',
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_create_teacher_returns_201(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->cleanupTestTeacher($teacher);
    }

    public function test_create_teacher_response_format_is_correct(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'full_name' => 'Format Test Teacher',
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
        ]);
        $response->assertJson([
            'success' => true,
            'message' => 'Teacher created successfully',
        ]);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->cleanupTestTeacher($teacher);
    }

    public function test_created_teacher_exists_in_database(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'full_name' => 'DB Check Teacher',
            'gender' => 'P',
            'is_active' => false,
            'phone' => '08123456789',
            'email' => 'test.phase6@example.com',
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->assertEquals($nip, $teacher->nip);
        $this->assertEquals('DB Check Teacher', $teacher->full_name);
        $this->assertEquals('P', $teacher->gender);
        $this->assertFalse($teacher->is_active);
        $this->assertEquals('08123456789', $teacher->phone);
        $this->assertEquals('test.phase6@example.com', $teacher->email);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_create_requires_teacher_code(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers', [
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['teacher_code'],
        ]);
    }

    public function test_create_requires_nip(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['nip'],
        ]);
    }

    public function test_create_rejects_duplicate_teacher_code(): void
    {
        $this->authenticate();

        $existing = Teacher::whereNotNull('teacher_code')->first();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $existing->teacher_code,
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['teacher_code'],
        ]);
    }

    public function test_create_rejects_duplicate_nip(): void
    {
        $this->authenticate();

        $existing = Teacher::first();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $existing->nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['nip'],
        ]);
    }

    public function test_create_validates_gender(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'X',
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['gender'],
        ]);
    }

    public function test_create_validates_email(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['email'],
        ]);
    }

    public function test_create_validates_user_id(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
            'user_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['user_id'],
        ]);
    }

    public function test_create_accepts_nullable_optional_fields(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->full_name);
        $this->assertNull($teacher->phone);
        $this->assertNull($teacher->email);
        $this->assertNull($teacher->address);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_create_supports_is_active_false(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => false,
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->assertFalse($teacher->is_active);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_create_supports_user_id_null(): void
    {
        $this->authenticate();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $teacher = Teacher::where('teacher_code', $teacherCode)->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->user_id);

        $this->cleanupTestTeacher($teacher);
    }

    // ─── UPDATE Tests ────────────────────────────────────────────

    public function test_authenticated_user_can_update_teacher(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Updated Name',
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_unauthenticated_user_cannot_update_teacher(): void
    {
        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Unauthorized Update',
        ]);

        $response->assertStatus(401);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_returns_200(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Updated Teacher',
            'gender' => 'P',
            'is_active' => false,
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_response_format_is_correct(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Format Update Test',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
        ]);
        $response->assertJson([
            'success' => true,
            'message' => 'Teacher updated successfully',
        ]);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_can_change_full_name(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Brand New Name',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('Brand New Name', $teacher->full_name);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_can_change_phone(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'phone' => '08876543210',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('08876543210', $teacher->phone);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_can_change_employment_status(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'employment_status' => 'PPPK',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('PPPK', $teacher->employment_status);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_can_change_gender(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['gender' => 'L']);

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'gender' => 'P',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('P', $teacher->gender);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_can_change_is_active_from_true_to_false(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher(['is_active' => true]);

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertFalse($teacher->is_active);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_rejects_duplicate_teacher_code(): void
    {
        $this->authenticate();

        $existing = Teacher::whereNotNull('teacher_code')->first();
        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'teacher_code' => $existing->teacher_code,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['teacher_code'],
        ]);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_rejects_duplicate_nip(): void
    {
        $this->authenticate();

        $existing = Teacher::first();
        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'nip' => $existing->nip,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'errors' => ['nip'],
        ]);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_ignores_current_teacher_when_validating_unique_fields(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'teacher_code' => $teacher->teacher_code,
            'nip' => $teacher->nip,
            'full_name' => 'Same Unique Values Updated',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('Same Unique Values Updated', $teacher->full_name);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_update_nonexistent_teacher_returns_404(): void
    {
        $this->authenticate();

        $response = $this->putJson('/api/teachers/99999', [
            'full_name' => 'Ghost Teacher',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Teacher not found',
            'data' => null,
        ]);
    }

    public function test_update_soft_deleted_teacher_returns_404(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacher->delete();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Should Not Update',
        ]);

        $response->assertStatus(404);

        $teacher->forceDelete();
    }

    public function test_partial_update_works(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher([
            'full_name' => 'Partial Update Teacher',
            'phone' => '08111111111',
            'email' => 'partial@example.com',
        ]);

        $response = $this->patchJson("/api/teachers/{$teacher->id}", [
            'phone' => '08222222222',
        ]);

        $response->assertStatus(200);

        $teacher->refresh();
        $this->assertEquals('Partial Update Teacher', $teacher->full_name);
        $this->assertEquals('08222222222', $teacher->phone);
        $this->assertEquals('partial@example.com', $teacher->email);

        $this->cleanupTestTeacher($teacher);
    }

    // ─── DELETE Tests ────────────────────────────────────────────

    public function test_delete_teacher_returns_200(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_delete_teacher_response_format(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
        ]);
        $response->assertJson([
            'success' => true,
            'message' => 'Teacher deleted successfully',
            'data' => null,
        ]);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_delete_nonexistent_teacher_returns_404(): void
    {
        $this->authenticate();

        $response = $this->deleteJson('/api/teachers/99999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Teacher not found',
            'data' => null,
        ]);
    }

    public function test_delete_already_soft_deleted_teacher_returns_404(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacher->delete();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(404);

        $teacher->forceDelete();
    }

    public function test_unauthenticated_user_cannot_delete_teacher(): void
    {
        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(401);

        $teacher->forceDelete();
    }

    public function test_delete_uses_soft_delete(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $response = $this->deleteJson("/api/teachers/{$teacherId}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('teachers', ['id' => $teacherId]);

        Teacher::withTrashed()->where('id', $teacherId)->forceDelete();
    }

    public function test_deleted_teacher_has_deleted_at_timestamp(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $this->deleteJson("/api/teachers/{$teacherId}");

        $trashedTeacher = Teacher::withTrashed()->find($teacherId);
        $this->assertNotNull($trashedTeacher->deleted_at);

        $trashedTeacher->forceDelete();
    }

    public function test_deleted_teacher_not_returned_in_index(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $this->deleteJson("/api/teachers/{$teacherId}");

        $response = $this->getJson('/api/teachers');
        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertNotContains($teacherId, $ids);

        Teacher::withTrashed()->where('id', $teacherId)->forceDelete();
    }

    public function test_deleted_teacher_not_returned_in_show(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $this->deleteJson("/api/teachers/{$teacherId}");

        $response = $this->getJson("/api/teachers/{$teacherId}");
        $response->assertStatus(404);

        Teacher::withTrashed()->where('id', $teacherId)->forceDelete();
    }

    public function test_delete_clears_classes_teacher_id(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $db = $this->app['db']->connection();

        $existingClass = $db->table('classes')->whereNotNull('teacher_id')->first();
        if (!$existingClass) {
            $existingClass = $db->table('classes')->first();
        }

        if ($existingClass) {
            $db->table('classes')
                ->where('id', $existingClass->id)
                ->update(['teacher_id' => $teacherId]);

            $response = $this->deleteJson("/api/teachers/{$teacherId}");
            $response->assertStatus(200);

            $classAfter = $db->table('classes')->where('id', $existingClass->id)->first();
            $this->assertEquals($teacherId, $classAfter->teacher_id);

            $db->table('classes')
                ->where('id', $existingClass->id)
                ->update(['teacher_id' => null]);
        }

        Teacher::withTrashed()->where('id', $teacherId)->forceDelete();
    }

    public function test_delete_cascades_class_subjects(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $teacherId = $teacher->id;

        $this->deleteJson("/api/teachers/{$teacherId}");

        $csCount = $this->app['db']->connection()
            ->table('class_subjects')
            ->where('teacher_id', $teacherId)
            ->count();
        $this->assertEquals(0, $csCount);

        Teacher::withTrashed()->where('id', $teacherId)->forceDelete();
    }

    public function test_delete_does_not_delete_user(): void
    {
        $this->authenticate();

        $adminRole = Role::where('name', 'Administrator')->first();
        $testUser = $this->createTestUser($adminRole->id, 'user_to_keep');
        $userId = $testUser->id;

        $teacher = $this->createTestTeacher(['user_id' => $userId]);

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $dbUser = $this->app['db']->connection()
            ->table('users')
            ->where('id', $userId)
            ->first();
        $this->assertNotNull($dbUser);

        $teacher->forceDelete();
        $testUser->forceDelete();
    }

    public function test_delete_does_not_delete_subject(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $subjectCount = $this->app['db']->connection()
            ->table('subjects')
            ->count();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $newSubjectCount = $this->app['db']->connection()
            ->table('subjects')
            ->count();
        $this->assertEquals($subjectCount, $newSubjectCount);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_delete_does_not_delete_role(): void
    {
        $this->authenticate();

        $teacher = $this->createTestTeacher();
        $roleCount = $this->app['db']->connection()
            ->table('roles')
            ->count();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $newRoleCount = $this->app['db']->connection()
            ->table('roles')
            ->count();
        $this->assertEquals($roleCount, $newRoleCount);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_delete_teacher_count_decreases_by_one(): void
    {
        $this->authenticate();

        $countBefore = Teacher::count();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $countAfter = Teacher::count();
        $this->assertEquals($countBefore, $countAfter);

        $this->cleanupTestTeacher($teacher);
    }

    // ─── Role Authorization Tests ────────────────────────────────

    public function test_admin_role_can_delete(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_administrator_role_can_delete(): void
    {
        $this->authenticateAsAdministrator();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_guru_role_cannot_delete(): void
    {
        $this->authenticateAsGuru();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_siswa_role_cannot_delete(): void
    {
        $this->authenticateAsSiswa();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_guru_role_cannot_create(): void
    {
        $this->authenticateAsGuru();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_siswa_role_cannot_create(): void
    {
        $this->authenticateAsSiswa();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $this->getUniqueTeacherCode(),
            'nip' => $this->getUniqueNip(),
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_role_can_create(): void
    {
        $this->authenticateAsAdmin();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $this->cleanupTestTeacher(Teacher::where('teacher_code', $teacherCode)->first());
    }

    public function test_administrator_role_can_create(): void
    {
        $this->authenticateAsAdministrator();

        $teacherCode = $this->getUniqueTeacherCode();
        $nip = $this->getUniqueNip();

        $response = $this->postJson('/api/teachers', [
            'teacher_code' => $teacherCode,
            'nip' => $nip,
            'gender' => 'L',
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $this->cleanupTestTeacher(Teacher::where('teacher_code', $teacherCode)->first());
    }

    public function test_guru_role_cannot_update(): void
    {
        $this->authenticateAsGuru();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Guru Cannot Update',
        ]);

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_siswa_role_cannot_update(): void
    {
        $this->authenticateAsSiswa();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Siswa Cannot Update',
        ]);

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_guru_role_cannot_patch(): void
    {
        $this->authenticateAsGuru();

        $teacher = $this->createTestTeacher();

        $response = $this->patchJson("/api/teachers/{$teacher->id}", [
            'phone' => '08111111111',
        ]);

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_siswa_role_cannot_patch(): void
    {
        $this->authenticateAsSiswa();

        $teacher = $this->createTestTeacher();

        $response = $this->patchJson("/api/teachers/{$teacher->id}", [
            'phone' => '08222222222',
        ]);

        $response->assertStatus(403);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_admin_role_can_update(): void
    {
        $this->authenticateAsAdmin();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Admin Updated',
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_administrator_role_can_update(): void
    {
        $this->authenticateAsAdministrator();

        $teacher = $this->createTestTeacher();

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'full_name' => 'Administrator Updated',
        ]);

        $response->assertStatus(200);

        $this->cleanupTestTeacher($teacher);
    }

    // ─── GET Endpoints Accessible to All Roles ───────────────────

    public function test_guru_role_can_access_index(): void
    {
        $this->authenticateAsGuru();

        $response = $this->getJson('/api/teachers');

        $response->assertStatus(200);
    }

    public function test_guru_role_can_access_show(): void
    {
        $this->authenticateAsGuru();

        $teacher = Teacher::first();

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
    }

    public function test_siswa_role_can_access_index(): void
    {
        $this->authenticateAsSiswa();

        $response = $this->getJson('/api/teachers');

        $response->assertStatus(200);
    }

    public function test_siswa_role_can_access_show(): void
    {
        $this->authenticateAsSiswa();

        $teacher = Teacher::first();

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
    }

    // ─── Role Middleware Edge Cases ──────────────────────────────

    public function test_role_middleware_returns_403_json_format(): void
    {
        $this->authenticateAsGuru();

        $teacher = $this->createTestTeacher();

        $response = $this->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthorized',
            'data' => null,
        ]);

        $this->cleanupTestTeacher($teacher);
    }

    public function test_teacher_count_unchanged_after_delete(): void
    {
        $this->authenticate();

        $countBefore = $this->app['db']->connection()
            ->table('teachers')
            ->whereNull('deleted_at')
            ->count();

        $teacher = $this->createTestTeacher();

        $this->deleteJson("/api/teachers/{$teacher->id}");

        $countAfter = $this->app['db']->connection()
            ->table('teachers')
            ->whereNull('deleted_at')
            ->count();

        $this->assertEquals($countBefore, $countAfter);

        $this->cleanupTestTeacher($teacher);
    }

    // Tests end here
}
