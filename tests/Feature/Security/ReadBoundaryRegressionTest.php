<?php

namespace Tests\Feature\Security;

use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGradeTestSchema;
use Tests\TestCase;

/**
 * Regression tests for the Stage B1 security boundary:
 *
 *  - Read-only global endpoints (index/show/export) must be reachable only by
 *    Admin/Administrator (Category A) or Admin/Administrator/Guru
 *    (Category D reference data). Siswa must never read cross-role data.
 *  - Client-controlled ids (student_id / teacher_id query params and direct
 *    object ids) must not bypass the boundary.
 *  - Admin and Guru functionality is preserved.
 */
class ReadBoundaryRegressionTest extends TestCase
{
    use BuildsGradeTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradeSchema();
        $this->buildNotificationSchema();
        $this->buildAuditLogSchema();
        $this->seedGradeBaseline();
    }

    private function buildAuditLogSchema(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('model')->nullable();
            $t->unsignedBigInteger('model_id')->nullable();
            $t->text('description')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    private function buildNotificationSchema(): void
    {
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('type')->nullable();
            $t->string('title');
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->boolean('is_read')->default(false);
            $t->datetime('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('curriculums', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->unsignedBigInteger('order')->nullable();
            $t->timestamps();
        });
    }

    // ─── Auth helpers ───────────────────────────────────────────

    private function actingAsRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::where('role_id', $role->id)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    // ─── Category A: admin-only resources ───────────────────────

    public function test_siswa_is_denied_all_admin_read_endpoints(): void
    {
        $this->actingAsRole('Siswa');

        $endpoints = [
            '/api/grades',
            '/api/report-cards',
            '/api/students',
            '/api/teachers',
            '/api/teachers/export',
            '/api/attendance',
            '/api/class-students',
            '/api/student-histories',
            '/api/parents',
            '/api/guardians',
            '/api/achievements',
            '/api/violations',
            '/api/scholarships',
            '/api/transfers',
            '/api/alumni',
            '/api/student-id-cards',
            '/api/staff',
            '/api/teacher-assignments',
            '/api/teacher-attendances',
            '/api/teacher-leaves',
            '/api/teacher-documents',
            '/api/schedules',
            '/api/assignments',
            '/api/users',
            '/api/roles',
            '/api/permissions',
            '/api/notifications',
            '/api/reports/academic/grades-summary',
        ];

        foreach ($endpoints as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(403, $response->status(), "Expected 403 for Siswa on GET {$uri}");
        }
    }

    public function test_guru_is_denied_admin_read_endpoints(): void
    {
        $this->actingAsRole('Guru');

        $endpoints = [
            '/api/grades',
            '/api/grades/1',
            '/api/report-cards',
            '/api/report-cards/1',
            '/api/students',
            '/api/teachers',
            '/api/teachers/export',
            '/api/attendance',
            '/api/class-students',
            '/api/student-histories',
            '/api/parents',
            '/api/guardians',
            '/api/users',
            '/api/notifications',
            '/api/notifications/1',
            '/api/reports/academic/grades-summary',
        ];

        foreach ($endpoints as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(403, $response->status(), "Expected 403 for Guru on GET {$uri}");
        }
    }

    public function test_direct_object_access_is_denied_for_siswa(): void
    {
        $this->actingAsRole('Siswa');

        foreach (['/api/grades/1', '/api/report-cards/1', '/api/students/1', '/api/attendance/1'] as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(403, $response->status(), "Expected 403 direct object access on {$uri}");
        }
    }

    public function test_query_param_tampering_cannot_bypass_boundary(): void
    {
        $this->actingAsRole('Siswa');

        $tampered = [
            '/api/grades?student_id=2',
            '/api/grades?teacher_id=2',
            '/api/report-cards?student_id=2',
            '/api/students?teacher_id=2',
            '/api/attendance?student_id=2&teacher_id=2&class_id=1',
            '/api/notifications?user_id=2',
        ];

        foreach ($tampered as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(403, $response->status(), "Expected 403 for tampered query on {$uri}");
        }
    }

    public function test_self_service_endpoints_stay_open(): void
    {
        $this->actingAsRole('Siswa');

        foreach (['/api/notifications/my', '/api/notifications/unread-count'] as $uri) {
            $response = $this->getJson($uri);
            $this->assertNotEquals(403, $response->status(), "Self-service endpoint must stay open: {$uri}");
        }
    }

    // ─── Category D: reference data (Admin + Guru) ───────────────

    public function test_reference_data_reachable_for_admin_and_guru_but_not_siswa(): void
    {
        $reference = [
            '/api/classes',
            '/api/subjects',
            '/api/class-subjects',
            '/api/academic-years',
            '/api/semesters',
            '/api/curriculums',
            '/api/periods',
        ];

        $this->actingAsRole('Admin');
        foreach ($reference as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(200, $response->status(), "Expected 200 for Admin on GET {$uri}");
        }

        $this->actingAsRole('Guru');
        foreach ($reference as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(200, $response->status(), "Expected 200 for Guru on GET {$uri}");
        }

        $this->actingAsRole('Siswa');
        foreach ($reference as $uri) {
            $response = $this->getJson($uri);
            $this->assertEquals(403, $response->status(), "Expected 403 for Siswa on GET {$uri}");
        }
    }

    // ─── Admin preservation ──────────────────────────────────────

    public function test_admin_can_still_read_index_and_show_endpoints(): void
    {
        $this->actingAsRole('Admin');

        foreach (['/api/grades', '/api/report-cards', '/api/students', '/api/semesters', '/api/reports/academic/grades-summary'] as $uri) {
            $response = $this->getJson($uri);
            $this->assertNotEquals(403, $response->status(), "Admin must keep read access on {$uri}");
            $this->assertNotEquals(401, $response->status(), "Admin must stay authenticated on {$uri}");
        }
    }

    public function test_unauthenticated_requests_still_rejected(): void
    {
        $response = $this->getJson('/api/grades');
        $this->assertNotEquals(200, $response->status());

        $response = $this->getJson('/api/semesters');
        $this->assertNotEquals(200, $response->status());
    }
}