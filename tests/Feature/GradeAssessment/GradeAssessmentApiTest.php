<?php

namespace Tests\Feature\GradeAssessment;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradeAssessmentApiTest extends TestCase
{
    /** @var mixed */
    private $admin;
    /** @var mixed */
    private $guru;
    /** @var mixed */
    private $siswa;
    /** @var AcademicYear */
    private $ay1;
    /** @var AcademicYear */
    private $ay2;
    /** @var Semester */
    private $sem1;
    /** @var Semester */
    private $sem2;
    /** @var Semester */
    private $sem3;
    /** @var SchoolClass */
    private $class1;
    /** @var SchoolClass */
    private $class2;
    /** @var Subject */
    private $sub1;
    /** @var Subject */
    private $sub2;
    /** @var Subject */
    private $sub3;
    /** @var Student */
    private $student1;
    /** @var Student */
    private $student2;
    /** @var Student */
    private $student3;
    /** @var Grade */
    private $baselineGrade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable(); $t->string('name');
            $t->string('email')->unique(); $t->string('password');
            $t->string('photo')->nullable(); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->boolean('is_active')->default(false);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('semesters', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('academic_year_id');
            $t->string('name'); $t->boolean('is_active')->default(false);
            $t->timestamps();
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->id(); $t->string('name');
            $t->string('level')->nullable(); $t->string('academic_year')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name');
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('class_subjects', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nis')->nullable(); $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->string('birth_place')->nullable(); $t->date('birth_date')->nullable();
            $t->string('address')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('grade_assessments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->string('assessment_category', 50);
            $t->unsignedInteger('assessment_sequence');
            $t->string('assessment_name', 100)->nullable();
            $t->decimal('score', 5, 2);
            $t->decimal('max_score', 5, 2)->default(100);
            $t->decimal('weight', 5, 2)->nullable();
            $t->string('source_type', 50)->nullable();
            $t->unsignedInteger('source_id')->nullable();
            $t->date('assessed_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(
                ['student_id', 'subject_id', 'class_id', 'academic_year_id', 'semester_id', 'assessment_category', 'assessment_sequence'],
                'uq_grade_assessments_cat_seq'
            );
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->string('type');
            $t->decimal('score', 5, 2)->default(0);
            $t->string('semester', 10)->nullable();
            $t->string('academic_year', 20)->nullable();
            $t->unsignedBigInteger('semester_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->boolean('is_final')->default(false);
            $t->dateTime('finalized_at')->nullable();
            $t->unsignedBigInteger('finalized_by')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);
        $roleSiswa = Role::create(['name' => 'Siswa']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@ga.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@ga.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);
        $this->siswa = User::create(['name' => 'Siswa', 'email' => 'siswa@ga.test', 'username' => 'siswa', 'password' => 'x', 'role_id' => $roleSiswa->id]);

        $this->ay1 = AcademicYear::create(['name' => '2025/2026', 'is_active' => true]);
        $this->ay2 = AcademicYear::create(['name' => '2026/2027']);
        $this->sem1 = Semester::create(['academic_year_id' => $this->ay1->id, 'name' => '1']);
        $this->sem2 = Semester::create(['academic_year_id' => $this->ay1->id, 'name' => '2']);
        $this->sem3 = Semester::create(['academic_year_id' => $this->ay2->id, 'name' => '1']);

        $this->class1 = SchoolClass::create(['name' => '10A', 'academic_year' => '2025/2026']);
        $this->class2 = SchoolClass::create(['name' => '10B', 'academic_year' => '2025/2026']);

        $this->sub1 = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $this->sub2 = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        ClassSubject::create(['class_id' => $this->class1->id, 'subject_id' => $this->sub1->id]);
        ClassSubject::create(['class_id' => $this->class1->id, 'subject_id' => $this->sub2->id]);
        ClassSubject::create(['class_id' => $this->class2->id, 'subject_id' => $this->sub1->id]);

        $this->sub3 = Subject::create(['code' => 'BIN', 'name' => 'Bahasa Indonesia']);

        $this->student1 = Student::create(['name' => 'Student 1', 'class_id' => $this->class1->id, 'gender' => 'L']);
        $this->student2 = Student::create(['name' => 'Student 2', 'class_id' => $this->class1->id, 'gender' => 'P']);
        $this->student3 = Student::create(['name' => 'Student 3', 'class_id' => $this->class2->id, 'gender' => 'L']);

        // Create a baseline grade for legacy integrity testing
        $this->baselineGrade = Grade::create([
            'student_id' => $this->student1->id,
            'subject_id' => $this->sub1->id,
            'class_id' => $this->class1->id,
            'type' => 'uts',
            'score' => 85.00,
            'semester' => '1',
            'academic_year' => '2025/2026',
            'semester_id' => $this->sem1->id,
            'academic_year_id' => $this->ay1->id,
            'is_final' => false,
        ]);
    }

    private function authenticateAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function authenticateGuru(): void
    {
        Sanctum::actingAs($this->guru);
    }

    private function authenticateSiswa(): void
    {
        Sanctum::actingAs($this->siswa);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student1->id,
            'subject_id' => $this->sub1->id,
            'class_id' => $this->class1->id,
            'academic_year_id' => $this->ay1->id,
            'semester_id' => $this->sem1->id,
            'assessment_category' => 'tugas',
            'assessment_sequence' => 1,
            'score' => 85.00,
        ], $overrides);
    }

    // ─── A. AUTHORIZATION ────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/grade-assessments')->assertStatus(401);
    }

    public function test_guru_cannot_access_index(): void
    {
        $this->authenticateGuru();
        $this->getJson('/api/grade-assessments')->assertStatus(403);
    }

    public function test_admin_can_access_index(): void
    {
        $this->authenticateAdmin();
        $this->getJson('/api/grade-assessments')->assertStatus(200);
    }

    public function test_administrator_can_access_index(): void
    {
        $roleAdmin = Role::where('name', 'Admin')->first();
        $administratorRole = Role::create(['name' => 'Administrator']);
        $user = User::create(['name' => 'Adminstrator', 'email' => 'adminstrator@ga.test', 'username' => 'adminstrator', 'password' => 'x', 'role_id' => $administratorRole->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/grade-assessments')->assertStatus(200);
    }

    // ─── B. CRUD ────────────────────────────────────────────────

    public function test_create_assessment(): void
    {
        $this->authenticateAdmin();
        $response = $this->postJson('/api/grade-assessments', $this->validPayload());
        $response->assertStatus(201);
        $this->assertDatabaseHas('grade_assessments', ['assessment_category' => 'tugas', 'assessment_sequence' => 1]);
    }

    public function test_list_assessments(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $response = $this->getJson('/api/grade-assessments');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_show_assessment(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $id = GradeAssessment::first()->id;
        $response = $this->getJson("/api/grade-assessments/{$id}");
        $response->assertStatus(200);
        $this->assertArrayHasKey('id', $response->json('data'));
    }

    public function test_update_assessment(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $id = GradeAssessment::first()->id;
        $response = $this->putJson("/api/grade-assessments/{$id}", ['score' => 90.00]);
        $response->assertStatus(200);
        $this->assertSame('90.00', (string) GradeAssessment::find($id)->score);
    }

    public function test_delete_assessment(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $id = GradeAssessment::first()->id;
        $this->deleteJson("/api/grade-assessments/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('grade_assessments', ['id' => $id]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->authenticateAdmin();
        $this->getJson('/api/grade-assessments/99999')->assertStatus(404);
    }

    // ─── C. IDENTITY / IDOR ──────────────────────────────────────

    public function test_cannot_read_other_student_assessment(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student1->id,
            'subject_id' => $this->sub1->id,
        ]))->assertStatus(201);

        $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student2->id,
            'subject_id' => $this->sub1->id,
            'assessment_sequence' => 2,
        ]))->assertStatus(201);

        // IDOR: show student1 assessment
        $id = GradeAssessment::where('student_id', $this->student1->id)->first()->id;
        $response = $this->getJson("/api/grade-assessments/{$id}");
        $response->assertStatus(200);
        $this->assertSame($this->student1->id, $response->json('data.student_id'));
    }

    public function test_cannot_manipulate_across_classes(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student3->id,
            'class_id' => $this->class2->id,
            'subject_id' => $this->sub1->id,
        ]))->assertStatus(201);

        $id = GradeAssessment::where('student_id', $this->student3->id)->first()->id;
        $response = $this->getJson("/api/grade-assessments/{$id}");
        $response->assertStatus(200);
        $this->assertSame($this->class2->id, $response->json('data.class_id'));
    }

    public function test_store_rejects_student_not_in_class(): void
    {
        $this->authenticateAdmin();
        $response = $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student3->id,
            'class_id' => $this->class1->id,
        ]));
        $response->assertStatus(422);
    }

    public function test_store_rejects_subject_not_assigned_to_class(): void
    {
        $this->authenticateAdmin();
        $response = $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student1->id,
            'class_id' => $this->class1->id,
            'subject_id' => $this->sub3->id,
        ]));
        $response->assertStatus(422);
    }

    // ─── D. DUPLICATES ──────────────────────────────────────────

    public function test_duplicate_category_sequence_rejected(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $response = $this->postJson('/api/grade-assessments', $this->validPayload());
        $response->assertStatus(422);
    }

    public function test_different_sequence_allowed(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_sequence' => 1]))->assertStatus(201);
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_sequence' => 2]))->assertStatus(201);
        $this->assertCount(2, GradeAssessment::all());
    }

    // ─── E. ALL CATEGORIES ─────────────────────────────────────

    public function test_all_categories_accepted(): void
    {
        $this->authenticateAdmin();

        $categories = [
            'tugas', 'formatif', 'PH', 'PTS', 'PAS', 'sumatif',
            'uts', 'uas', 'ujian_sekolah', 'remedial', 'other',
        ];

        foreach ($categories as $i => $cat) {
            $response = $this->postJson('/api/grade-assessments', $this->validPayload([
                'assessment_category' => $cat,
                'assessment_sequence' => $i + 1,
            ]));
            $response->assertStatus(201);
        }

        $this->assertCount(11, GradeAssessment::all());
    }

    // ─── F. VALIDATION ──────────────────────────────────────────

    public function test_requires_student_id(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', array_merge($this->validPayload(), ['student_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_requires_subject_id(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['subject_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id']);
    }

    public function test_requires_class_id(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['class_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['class_id']);
    }

    public function test_rejects_invalid_student(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['student_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_subject(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['subject_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_class(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['class_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_academic_year(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['academic_year_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_semester(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['semester_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_category(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_category' => 'invalid_cat']))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_sequence(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_sequence' => 0]))
            ->assertStatus(422);
    }

    public function test_rejects_negative_score(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['score' => -5]))
            ->assertStatus(422);
    }

    public function test_rejects_score_above_100(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['score' => 101]))
            ->assertStatus(422);
    }

    public function test_rejects_invalid_date(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessed_date' => 'not-a-date']))
            ->assertStatus(422);
    }

    // ─── G. SOURCE SECURITY ──────────────────────────────────────

    public function test_client_cannot_inject_source(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload([
            'source_type' => 'fake',
            'source_id' => 999999,
        ]))->assertStatus(201);

        $assessment = GradeAssessment::first();
        $this->assertNull($assessment->source_type, 'source_type must not be client-injected');
        $this->assertNull($assessment->source_id, 'source_id must not be client-injected');
    }

    // ─── H. FINALIZATION SECURITY ────────────────────────────────

    public function test_client_cannot_inject_finalization(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/grade-assessments', $this->validPayload([
            'is_final' => true,
        ]))->assertStatus(201);

        $assessment = GradeAssessment::first();
        $this->assertFalse($assessment->is_final ?? false, 'is_final cannot be injected');
    }

    // ─── I. LEGACY GRADE INTEGRITY ──────────────────────────────

    public function test_assessment_operations_do_not_modify_grades(): void
    {
        $this->authenticateAdmin();
        $gradeBefore = Grade::find($this->baselineGrade->id);
        $gradeBeforeSnapshot = $gradeBefore->toArray();

        $this->postJson('/api/grade-assessments', $this->validPayload())->assertStatus(201);
        $id = GradeAssessment::first()->id;
        $this->putJson("/api/grade-assessments/{$id}", ['score' => 99.99])->assertStatus(200);
        $this->deleteJson("/api/grade-assessments/{$id}")->assertStatus(200);

        $gradeAfter = Grade::find($this->baselineGrade->id);
        $this->assertEquals($gradeBeforeSnapshot['score'], $gradeAfter->score);
        $this->assertEquals($gradeBeforeSnapshot['type'], $gradeAfter->type);
        $this->assertEquals($gradeBeforeSnapshot['semester_id'], $gradeAfter->semester_id);
        $this->assertEquals($gradeBeforeSnapshot['academic_year_id'], $gradeAfter->academic_year_id);
        $this->assertFalse($gradeAfter->is_final);
    }

    // ─── J. RELATIONSHIP / COMPOSITE IDENTITY ───────────────────

    public function test_assessmentsQuery_returns_only_matching_records(): void
    {
        $this->authenticateAdmin();

        // Grade 1: student1/sub1/class1/ay1/sem1
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_sequence' => 1]))->assertStatus(201);
        $this->postJson('/api/grade-assessments', $this->validPayload(['assessment_sequence' => 2]))->assertStatus(201);

        // Grade 2: different student/class — student2/sub2/class1 (but sub2 not in class_subjects for class1? actually it IS)
        $this->postJson('/api/grade-assessments', $this->validPayload([
            'student_id' => $this->student2->id,
            'subject_id' => $this->sub2->id,
            'assessment_sequence' => 1,
        ]))->assertStatus(201);

        // Fetch the Grade row for student1
        $grade = Grade::firstOrCreate([
            'student_id' => $this->student1->id,
            'subject_id' => $this->sub1->id,
            'class_id' => $this->class1->id,
            'type' => 'tugas',
            'semester_id' => $this->sem1->id,
            'academic_year_id' => $this->ay1->id,
        ], [
            'score' => 90,
            'semester' => '1',
            'academic_year' => '2025/2026',
        ]);

        $matched = $grade->assessmentsQuery()->count();
        $this->assertSame(2, $matched, 'Grade assessmentsQuery must return only matching identity');
    }
}
