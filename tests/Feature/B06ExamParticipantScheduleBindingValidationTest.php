<?php

namespace Tests\Feature;

use App\Http\Requests\Api\Examination\StoreExamParticipantRequest;
use App\Http\Requests\Api\Examination\UpdateExamParticipantRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * B-A06 AC-04-S: participant schedule ↔ exam binding at write time.
 *
 * A supplied schedule must belong to the submitted exam
 * (Rule::exists('exam_schedules','id')->where('exam_id', input)). The scope
 * only applies when exam_id is present; without it the schedule keeps its
 * plain existence check (existing explicit-roster behavior preserved).
 */
class B06ExamParticipantScheduleBindingValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('exam_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->timestamps();
        });
        Schema::create('exam_participants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exam_id');
            $t->unsignedBigInteger('student_id');
            $t->string('exam_card_number');
            $t->timestamps();
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

        $this->examId = \App\Models\Examination\Exam::create(['title' => 'exam A'])->id;
        $this->otherExamId = \App\Models\Examination\Exam::create(['title' => 'exam B'])->id;
        $this->scheduleOfExam = \App\Models\Examination\ExamSchedule::create(['exam_id' => $this->examId])->id;
        $this->scheduleOfOtherExam = \App\Models\Examination\ExamSchedule::create(['exam_id' => $this->otherExamId])->id;
        $this->studentId = \App\Models\Students\Student::create(['name' => 'Siswa A'])->id;
    }

    private function storeValidator(array $overrides = []): \Illuminate\Validation\Validator
    {
        $payload = array_merge([
            'exam_id' => $this->examId,
            'student_id' => $this->studentId,
            'exam_card_number' => 'CARD-X1',
            'status' => 'registered',
        ], $overrides);

        $request = StoreExamParticipantRequest::create('/api/exam-participants', 'POST', $payload);

        return Validator::make($payload, $request->rules());
    }

    private function updateValidator(array $overrides = []): \Illuminate\Validation\Validator
    {
        $payload = array_merge([
            'exam_card_number' => 'CARD-X1-unique2',
        ], $overrides);

        $request = UpdateExamParticipantRequest::create('/api/exam-participants/1', 'PUT', $payload);

        return Validator::make($payload, $request->rules());
    }

    public function test_schedule_of_submitted_exam_is_accepted(): void
    {
        $this->assertTrue($this->storeValidator([
            'schedule_id' => $this->scheduleOfExam,
        ])->passes());
    }

    public function test_schedule_of_other_exam_is_rejected(): void
    {
        $validator = $this->storeValidator([
            'schedule_id' => $this->scheduleOfOtherExam,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('schedule_id', $validator->errors()->messages());
    }

    public function test_schedule_omitted_is_accepted(): void
    {
        $this->assertTrue($this->storeValidator()->passes());
    }

    public function test_update_without_exam_id_keeps_plain_schedule_check(): void
    {
        $this->assertTrue($this->updateValidator([
            'schedule_id' => $this->scheduleOfOtherExam,
        ])->passes());
    }

    public function test_update_with_exam_id_enforces_binding(): void
    {
        $this->assertTrue($this->updateValidator([
            'exam_id' => $this->examId,
            'schedule_id' => $this->scheduleOfExam,
        ])->passes());

        $this->assertTrue($this->updateValidator([
            'exam_id' => $this->examId,
            'schedule_id' => $this->scheduleOfOtherExam,
        ])->fails());
    }
}