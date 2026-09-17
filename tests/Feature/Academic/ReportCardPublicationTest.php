<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\AcademicYear;
use App\Models\Academic\ReportCard;
use App\Models\Academic\SchoolClass;
use App\Models\Academic\Semester;
use App\Models\Students\Student;
use App\Models\System\Role;
use App\Models\System\User;
use App\Services\Academic\GradeMutationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2M-1 — ReportCard publication finalization.
 *
 * Hermetic suite building its own minimal schema on the default
 * (sqlite :memory:) connection so the publication lifecycle, identity
 * immutability, and the GradeMutationGuard interaction can run end to end.
 */
class ReportCardPublicationTest extends TestCase
{
    private User $admin;

    private User $guru;

    private int $studentId;

    private int $classId;

    private int $academicYearId;

    private int $semesterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->seedFixture();
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id')->nullable();
            $t->string('username')->nullable();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('semesters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('academic_year_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->string('nisn')->nullable();
            $t->string('nis')->nullable();
            $t->string('name');
            $t->string('gender', 1)->nullable();
            $t->timestamps();
            $t->softDeletes();
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
            $t->timestamps();
        });
        Schema::create('report_cards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('semester_id');
            $t->text('teacher_notes')->nullable();
            $t->string('status')->default('draft');
            $t->dateTime('published_at')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $roleAdmin = Role::create(['name' => 'Admin']);
        $roleGuru = Role::create(['name' => 'Guru']);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@rc.test', 'username' => 'admin', 'password' => 'x', 'role_id' => $roleAdmin->id]);
        $this->guru = User::create(['name' => 'Guru', 'email' => 'guru@rc.test', 'username' => 'guru', 'password' => 'x', 'role_id' => $roleGuru->id]);

        $ay = AcademicYear::create(['name' => '2026/2027']);
        $ay2 = AcademicYear::create(['name' => '2027/2028']);
        $semester = Semester::create(['academic_year_id' => $ay->id, 'name' => '1']);
        Semester::create(['academic_year_id' => $ay2->id, 'name' => '1']);
        $class = SchoolClass::create(['name' => '7A']);
        SchoolClass::create(['name' => '7B']);

        $studentA = Student::create(['name' => 'Siswa A', 'nisn' => '111', 'nis' => '001', 'class_id' => $class->id]);
        Student::create(['name' => 'Siswa B', 'nisn' => '222', 'nis' => '002', 'class_id' => $class->id]);

        $this->studentId = $studentA->id;
        $this->classId = $class->id;
        $this->academicYearId = $ay->id;
        $this->semesterId = $semester->id;

        Sanctum::actingAs($this->admin);
    }

    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'teacher_notes' => 'Test notes',
            'status' => 'draft',
        ], $overrides);
    }

    private function createPublishedCard(string $notes = 'Published notes'): ReportCard
    {
        $card = ReportCard::create([
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'academic_year_id' => $this->academicYearId,
            'semester_id' => $this->semesterId,
            'teacher_notes' => $notes,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $card;
    }

    private function assertLocked422($response): void
    {
        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonStructure(['success', 'message', 'errors', 'data']);
    }

    public function test_draft_update_allowed(): void
    {
        $card = ReportCard::create($this->cardPayload());
        $id = $card->id;

        $this->putJson("/api/report-cards/{$id}", ['teacher_notes' => 'Updated draft notes', 'status' => 'draft'])
            ->assertStatus(200)
            ->assertJson(['data' => ['status' => 'draft']]);
        $this->assertSame('Updated draft notes', ReportCard::find($id)->teacher_notes);
    }

    public function test_draft_delete_allowed(): void
    {
        $card = ReportCard::create($this->cardPayload());
        $id = $card->id;

        $this->deleteJson("/api/report-cards/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('report_cards', ['id' => $id]);
    }

    public function test_draft_to_published_sets_published_at(): void
    {
        $card = ReportCard::create($this->cardPayload());
        $id = $card->id;

        $this->putJson("/api/report-cards/{$id}", ['status' => 'published', 'teacher_notes' => 'Publishing now'])
            ->assertStatus(200);

        $row = ReportCard::find($id);
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at, 'published_at must be server-generated');
        $this->assertGreaterThanOrEqual(now()->subMinute()->timestamp, $row->published_at->timestamp);
    }

    public function test_client_published_at_is_overridden_by_server(): void
    {
        $card = ReportCard::create($this->cardPayload());
        $id = $card->id;

        $this->putJson("/api/report-cards/{$id}", ['status' => 'published', 'published_at' => '2020-01-01 00:00:00'])
            ->assertStatus(200);

        $row = ReportCard::find($id);
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
        $this->assertGreaterThanOrEqual(now()->subMinute()->timestamp, $row->published_at->timestamp, 'client-supplied timestamp must never be trusted');
    }

    public function test_published_draft_transition_rejected(): void
    {
        $card = $this->createPublishedCard();
        $id = $card->id;

        $this->assertLocked422($this->putJson("/api/report-cards/{$id}", ['status' => 'draft']));
        $this->assertSame('published', ReportCard::find($id)->status);
    }

    public function test_published_delete_rejected(): void
    {
        $card = $this->createPublishedCard();
        $id = $card->id;

        $this->assertLocked422($this->deleteJson("/api/report-cards/{$id}"));
        $this->assertDatabaseHas('report_cards', ['id' => $id, 'status' => 'published']);
    }

    public function test_published_identity_immutable_per_field(): void
    {
        $card = $this->createPublishedCard();
        $id = $card->id;
        $before = ReportCard::find($id)->toArray();

        $otherStudent = Student::where('name', 'Siswa B')->firstOrFail();
        $otherClass = SchoolClass::where('name', '7B')->firstOrFail();
        $otherYear = AcademicYear::where('name', '2027/2028')->firstOrFail();
        $otherSemester = Semester::where('academic_year_id', $otherYear->id)->where('name', '1')->firstOrFail();

        $mutations = [
            'student_id' => $otherStudent->id,
            'class_id' => $otherClass->id,
            'academic_year_id' => $otherYear->id,
            'semester_id' => $otherSemester->id,
        ];

        foreach ($mutations as $field => $value) {
            $payload = $this->cardPayload(['student_id' => $otherStudent->id, 'status' => 'published']);

            if (in_array($field, ['semester_id', 'academic_year_id'], true)) {
                $payload['academic_year_id'] = $otherYear->id;
                $payload['semester_id'] = $otherSemester->id;
            }

            $payload[$field] = $value;

            $this->assertLocked422($this->putJson("/api/report-cards/{$id}", $payload));

            $after = ReportCard::find($id)->toArray();
            $this->assertSame($before[$field], $after[$field], "{$field} must stay immutable");
        }
    }

    public function test_published_notes_editable_keeps_status_and_published_at(): void
    {
        $card = $this->createPublishedCard('Original');
        $id = $card->id;
        $publishedAt = $card->published_at->toISOString();

        $this->putJson("/api/report-cards/{$id}", ['teacher_notes' => 'After publication'])
            ->assertStatus(200);

        $row = ReportCard::find($id);
        $this->assertSame('After publication', $row->teacher_notes);
        $this->assertSame('published', $row->status);
        $this->assertSame($publishedAt, $row->published_at->toISOString(), 'published_at must survive notes-only updates');
    }

    public function test_published_status_stays_published_after_notes_update(): void
    {
        $card = $this->createPublishedCard();
        $id = $card->id;

        $this->putJson("/api/report-cards/{$id}", ['status' => 'published', 'teacher_notes' => 'Notes'])
            ->assertStatus(200);

        $row = ReportCard::find($id);
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
    }

    public function test_publishing_activates_guard_for_missing_grade_slot(): void
    {
        $this->assertNull($this->guardSlot(), 'unlocked slot allows mutation');

        $this->createPublishedCard();

        $this->assertThrowsGuard();
    }

    public function test_draft_does_not_lock_slot(): void
    {
        ReportCard::create($this->cardPayload());

        $this->assertNull($this->guardSlot(), 'draft report card never locks grades');
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/report-cards')->assertStatus(401);
    }

    public function test_guru_cannot_mutate_report_cards(): void
    {
        Sanctum::actingAs($this->guru);
        $this->postJson('/api/report-cards', $this->cardPayload())->assertStatus(403);
        $this->putJson('/api/report-cards/1', ['teacher_notes' => 'x'])->assertStatus(403);
        $this->deleteJson('/api/report-cards/1')->assertStatus(403);
    }

    private function guardSlot(): ?\Throwable
    {
        try {
            app(GradeMutationGuard::class)->assertSlotMutable(
                $this->studentId,
                1,
                $this->classId,
                $this->academicYearId,
                $this->semesterId,
                'tugas',
            );
        } catch (HttpResponseException $e) {
            return $e;
        }

        return null;
    }

    private function assertThrowsGuard(): void
    {
        $this->assertNotNull($this->guardSlot(), 'published report card must lock the slot even without an existing Grade row');
    }
}
