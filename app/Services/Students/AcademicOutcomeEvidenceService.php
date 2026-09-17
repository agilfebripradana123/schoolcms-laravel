<?php

namespace App\Services\Students;

use App\Models\Academic\ClassSubject;
use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ReportCard;
use App\Services\Academic\GradeAggregationService;

/**
 * Academic outcome evidence service (Phase 2M-2F).
 *
 * Read-only collection of currently authoritative academic evidence for one
 * outcome identity (student + class + academic year + semester). The caller
 * supplies the semester (the outcome domain resolves the terminal Semester 2);
 * this service never resolves semesters itself.
 *
 * The service reports evidence only — it never determines pass/fail, never
 * recommends an outcome, never applies thresholds, and never mutates state.
 *
 * Expected subjects are read from class_subjects and are therefore
 * CLASS-SCOPED expected-subject evidence, NOT a guaranteed semester
 * curriculum. Subjects without grades/assessments remain present with
 * final_score=null.
 */
class AcademicOutcomeEvidenceService
{
    public function __construct(private GradeAggregationService $aggregation) {}

    public function evidence(
        int $studentId,
        int $classId,
        int $academicYearId,
        int $semesterId,
    ): array {
        $expectedSubjects = ClassSubject::query()
            ->with('subject')
            ->where('class_id', $classId)
            ->get();

        $assessments = GradeAssessment::query()
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->get(['class_id', 'subject_id', 'assessment_category']);

        $finals = $this->aggregation->weightedFinalScoresForStudent(
            $studentId,
            $academicYearId,
            $semesterId,
        );

        $assessmentEvidence = [];

        foreach ($assessments as $assessment) {
            $key = $assessment->subject_id.'|'.$assessment->class_id;
            $assessmentEvidence[$key]['assessment_count']
                = ($assessmentEvidence[$key]['assessment_count'] ?? 0) + 1;
            $assessmentEvidence[$key]['categories'][$assessment->assessment_category] = true;
        }

        $subjects = [];
        $assessmentCount = 0;

        foreach ($expectedSubjects as $classSubject) {
            $final = $finals[$this->identityKey(
                $classSubject->subject_id,
                $classId,
                $academicYearId,
                $semesterId,
            )] ?? null;

            $evidence = $assessmentEvidence[$classSubject->subject_id.'|'.$classId] ?? [];

            $subject = [
                'subject_id' => $classSubject->subject_id,
                'subject_name' => $classSubject->subject?->name,
                'type' => $classSubject->subject?->type,
                'final_score' => $final !== null ? (float) $final : null,
                'has_final' => $final !== null,
                'assessment_count' => $evidence['assessment_count'] ?? 0,
                'categories_present' => array_keys($evidence['categories'] ?? []),
            ];

            $subjects[] = $subject;
            $assessmentCount += $subject['assessment_count'];
        }

        $finalScoreCount = count(array_filter($subjects, fn (array $s) => $s['has_final']));

        return [
            'identity' => [
                'student_id' => $studentId,
                'class_id' => $classId,
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
            ],
            'report_card' => [
                'published' => ReportCard::query()
                    ->where('student_id', $studentId)
                    ->where('class_id', $classId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('semester_id', $semesterId)
                    ->where('status', 'published')
                    ->exists(),
            ],
            'subjects' => $subjects,
            'summary' => [
                'expected_subject_count' => count($subjects),
                'final_score_count' => $finalScoreCount,
                'missing_final_count' => count($subjects) - $finalScoreCount,
                'assessment_count' => $assessmentCount,
            ],
        ];
    }

    private function identityKey(int $subjectId, int $classId, int $academicYearId, int $semesterId): string
    {
        return implode('|', [$subjectId, $classId, $academicYearId, $semesterId]);
    }
}
