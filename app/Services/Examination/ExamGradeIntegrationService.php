<?php

namespace App\Services\Examination;

use App\Models\Academic\ClassSubject;
use App\Models\Academic\Grade;
use App\Models\Academic\GradeAssessment;
use App\Models\Examination\ExamAttempt;
use App\Models\Examination\ExamResult;
use App\Models\System\AuditLog;
use App\Services\Academic\GradeMutationGuard;
use Illuminate\Support\Facades\DB;

/**
 * ExamResult -> Academic Grade integration (Phase 2I).
 *
 * Eligibility is strict and fully server-derived:
 *   - result belongs to a known attempt (legacy result rows without an attempt
 *     cannot be mapped and are left untouched)
 *   - result.status is `graded` (Phase 2H: no answered essay left ungraded)
 *   - attempt exam maps to a subject; student has a home class
 *   - exam academic context (academic_year_id + semester_id) is present —
 *     NEVER resolved from exam date, schedule session, or client input
 *   - exam.exam_type maps to an existing grade.type (uts/uas only; the repo has
 *     no assessment-type model for formatif/sumatif/ujian_sekolah/remedial)
 *   - the subject is legitimately assigned to the class (ClassSubject), same
 *     rule used by the manual Grade input flow
 *
 * Uniqueness follows the real Grade domain: ONE academic grade per
 * (student, subject, class, type, semester_id, academic_year_id), enforced by
 * `uniq_grades_period`. Sync is therefore idempotent — repeated sync and any
 * later eligible result for the same slot reconcile the SAME row (re-sync
 * policy = "grade updates automatically, never silently stale").
 *
 * `source_type = exam_result` + `source_id` give traceability and back the
 * `uniq_grades_source` uniqueness, so one result can never produce two rows.
 */
class ExamGradeIntegrationService
{
    private const SYNCABLE_EXAM_TYPES = ['uts', 'uas'];

    /**
     * @param  array{user_id?: int|null, ip_address?: string|null, user_agent?: string|null}  $auditContext
     * @return array{ok: bool, message: string, grade?: Grade}
     */
    public function sync(ExamResult $result, ?array $auditContext = null): array
    {
        if ($result->exam_attempt_id === null) {
            return $this->notEligible('Legacy result without an attempt cannot be mapped to an academic period.');
        }

        $attempt = ExamAttempt::find($result->exam_attempt_id);
        if (! $attempt) {
            return $this->notEligible('Attempt for this result no longer exists.');
        }

        if ($result->status !== 'graded') {
            return $this->notEligible('Result is not fully graded (pending manual essay grading).');
        }

        if (! app(ExamScoringService::class)->isFullyGraded($attempt)) {
            return $this->notEligible('Result has answered essays that are not yet graded.');
        }

        $exam = $attempt->exam;
        $participant = $result->participant;

        if (! $exam || ! $participant) {
            return $this->notEligible('Exam or participant for this result is missing.');
        }

        $type = $exam->exam_type;
        if (! in_array($type, self::SYNCABLE_EXAM_TYPES, true)) {
            return $this->notEligible(sprintf('Exam type "%s" has no supported academic grade representation.', $type ?: 'null'));
        }

        if ($exam->academic_year_id === null || $exam->semester_id === null) {
            return $this->notEligible('Exam has no academic year/semester context (legacy exam) and cannot be safely mapped.');
        }

        $subject = $exam->subject;
        $student = $participant->student;

        if (! $subject || ! $student) {
            return $this->notEligible('Subject or student could not be resolved.');
        }

        $classId = (int) $student->class_id;
        if ($classId < 1) {
            return $this->notEligible('Student has no assigned class.');
        }
        if ($exam->class_id !== null && (int) $exam->class_id !== $classId) {
            return $this->notEligible('Exam class does not match the student home class.');
        }

        $classSubject = ClassSubject::where('class_id', $classId)
            ->where('subject_id', $subject->id)
            ->exists();
        if (! $classSubject) {
            return $this->notEligible('Subject is not assigned to the student class.');
        }

        $score = (float) $result->percentage;

        // Phase 2J + 2L-7F: never overwrite or create an academic Grade in a
        // finalized (or published-report-card locked) slot, even when the
        // bucket Grade row does not exist yet.
        app(GradeMutationGuard::class)->assertSlotMutable(
            $student->id,
            $subject->id,
            $classId,
            $exam->academic_year_id,
            $exam->semester_id,
            $type,
        );

        $grade = DB::transaction(function () use ($result, $student, $subject, $classId, $type, $exam, $score, $auditContext) {
            $grade = Grade::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'class_id' => $classId,
                    'type' => $type,
                    'semester_id' => $exam->semester_id,
                    'academic_year_id' => $exam->academic_year_id,
                ],
                [
                    'score' => $score,
                    // string aliases kept in sync with legacy columns
                    'semester' => $exam->semester?->name ?? null,
                    'academic_year' => $exam->academicYear?->name ?? null,
                ]
            );

            // Phase 2K: persist the exam-derived assessment with server-side
            // source tracing. Idempotent via the assessment unique tuple
            // (student, subject, class, year, semester, category, sequence).
            GradeAssessment::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'class_id' => $classId,
                    'academic_year_id' => $exam->academic_year_id,
                    'semester_id' => $exam->semester_id,
                    'assessment_category' => $type,
                    'assessment_sequence' => 1,
                ],
                [
                    'score' => $score,
                    'max_score' => 100.00,
                    'source_type' => 'exam_result',
                    'source_id' => $result->id,
                    'assessed_date' => $result->updated_at?->toDateString(),
                    'notes' => 'Synced from ExamResult#'.$result->id,
                ]
            );

            // Audit inside the same transaction: success persists, rollback
            // removes it together with Grade + GradeAssessment mutation. Actor
            // comes from the caller context; internal/automatic re-syncs with
            // no actor keep user_id null rather than claiming a wrong user.
            AuditLog::create([
                'user_id' => $auditContext['user_id'] ?? null,
                'action' => 'exam_grade_synced',
                'model' => ExamResult::class,
                'model_id' => $result->id,
                'description' => json_encode([
                    'result_id' => $result->id,
                    'grade_id' => $grade->id,
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'class_id' => $classId,
                    'semester_id' => $exam->semester_id,
                    'academic_year_id' => $exam->academic_year_id,
                    'type' => $type,
                    'percentage' => (float) $score,
                ]),
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
            ]);

            return $grade;
        });

        return ['ok' => true, 'message' => 'Grade synchronized successfully.', 'grade' => $grade];
    }

    /**
     * True when an assessment already traces back to this result (used to
     * propagate regrades automatically so academic data can never go stale).
     */
    public function isSynced(ExamResult $result): bool
    {
        return GradeAssessment::where('source_type', 'exam_result')
            ->where('source_id', $result->id)
            ->exists();
    }

    private function notEligible(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
