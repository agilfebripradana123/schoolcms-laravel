<?php

namespace Tests\Feature;

use App\Http\Requests\Api\Examination\StoreExamRequest;
use App\Http\Requests\Api\Examination\UpdateExamRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * B-A06 AC-01: exam semester ↔ academic year coherence at write time.
 *
 * A supplied semester must belong to the supplied academic year
 * (Rule::exists('semesters','id')->where('academic_year_id', input)).
 * The invariant only applies when academic_year_id is present; when the year
 * is omitted the semester keeps its plain existence check (existing behavior
 * is preserved, no new requirement invented).
 */
class B06ExamPeriodCoherenceValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id')->nullable();
            $t->string('action', 50);
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->text('description');
            $t->string('ip_address', 45);
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->yearId = \App\Models\Academic\AcademicYear::create(['name' => '2025/2026'])->id;
        $otherYearId = \App\Models\Academic\AcademicYear::create(['name' => '2024/2025'])->id;
        $this->semesterOfYear = \App\Models\Academic\Semester::create(['academic_year_id' => $this->yearId, 'name' => 'Ganjil'])->id;
        $this->foreignSemester = \App\Models\Academic\Semester::create(['academic_year_id' => $otherYearId, 'name' => 'Genap'])->id;
        $this->subjectId = \App\Models\Academic\Subject::create(['code' => 'MTK', 'name' => 'Matematika'])->id;
    }

    private function storeValidator(array $overrides = []): \Illuminate\Validation\Validator
    {
        $payload = array_merge([
            'subject_id' => $this->subjectId,
            'title' => 'Ujian',
            'duration_minutes' => 60,
        ], $overrides);

        $request = StoreExamRequest::create('/api/exams', 'POST', $payload);

        return Validator::make($payload, $request->rules());
    }

    private function updateValidator(array $overrides = []): \Illuminate\Validation\Validator
    {
        $payload = array_merge([
            'subject_id' => $this->subjectId,
        ], $overrides);

        $request = UpdateExamRequest::create('/api/exams/1', 'PUT', $payload);

        return Validator::make($payload, $request->rules());
    }

    public function test_matching_semester_for_academic_year_is_accepted(): void
    {
        $this->assertTrue($this->storeValidator([
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterOfYear,
        ])->passes());
    }

    public function test_semester_of_other_academic_year_is_rejected(): void
    {
        $validator = $this->storeValidator([
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->foreignSemester,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('semester_id', $validator->errors()->messages());
    }

    public function test_both_nullable_fields_omitted_is_accepted(): void
    {
        $this->assertTrue($this->storeValidator()->passes());
    }

    public function test_academic_year_without_semester_is_accepted(): void
    {
        $this->assertTrue($this->storeValidator([
            'academic_year_id' => $this->yearId,
        ])->passes());
    }

    public function test_semester_without_academic_year_keeps_plain_existence_check(): void
    {
        $this->assertTrue($this->storeValidator([
            'semester_id' => $this->semesterOfYear,
        ])->passes());
    }

    public function test_update_request_enforces_same_coherence(): void
    {
        $this->assertTrue($this->updateValidator([
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->semesterOfYear,
        ])->passes());

        $this->assertTrue($this->updateValidator([
            'academic_year_id' => $this->yearId,
            'semester_id' => $this->foreignSemester,
        ])->fails());
    }
}