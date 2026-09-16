<?php

namespace App\Services\Academic;

use App\Models\Academic\GradeAssessment;
use App\Models\Academic\ClassSubject;
use App\Models\Students\Student;
use Illuminate\Validation\ValidationException;

class GradeAssessmentService
{
    /**
     * Supported assessment categories (from config/grades.php).
     */
    protected function getSupportedCategories(): array
    {
        return config('grades.assessment_categories', [
            'tugas',
            'formatif',
            'PH',
            'PTS',
            'PAS',
            'sumatif',
            'uts',
            'uas',
            'ujian_sekolah',
            'remedial',
            'other',
        ]);
    }

    /**
     * Create a new assessment record.
     *
     * @param array $validated
     * @return GradeAssessment
     */
    public function create(array $validated): GradeAssessment
    {
        $studentId = $validated['student_id'];
        $subjectId = $validated['subject_id'];
        $classId = $validated['class_id'];
        $academicYearId = $validated['academic_year_id'];
        $semesterId = $validated['semester_id'];
        $category = $validated['assessment_category'];
        $sequence = $validated['assessment_sequence'];
        $score = $validated['score'];

        // Validate student belongs to class
        $student = Student::find($studentId);
        if (!$student || $student->class_id != $classId) {
            throw ValidationException::withMessages([
                'student_id' => ['Siswa tidak terdaftar di kelas yang ditentukan.'],
            ]);
        }

        // Validate subject belongs to class
        $classSubject = ClassSubject::where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->exists();
        if (!$classSubject) {
            throw ValidationException::withMessages([
                'subject_id' => ['Mata pelajaran tidak terdaftar pada kelas yang ditentukan.'],
            ]);
        }

        // Validate category is supported
        $supportedCategories = $this->getSupportedCategories();
        if (!in_array($category, $supportedCategories, true)) {
            throw ValidationException::withMessages([
                'assessment_category' => ['Kategori penilaian "' . $category . '" tidak didukung.'],
            ]);
        }

        // Check uniqueness: (student, subject, class, year, semester, category, sequence)
        $exists = GradeAssessment::where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester_id', $semesterId)
            ->where('assessment_category', $category)
            ->where('assessment_sequence', $sequence)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'assessment_category' => ['Asesmen kategori "' . $category . ' - Ke-' . $sequence . '" sudah ada untuk siswa, mata pelajaran, kelas, dan periode ini.'],
            ]);
        }

        $assessment = GradeAssessment::create([
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'class_id' => $classId,
            'academic_year_id' => $academicYearId,
            'semester_id' => $semesterId,
            'assessment_category' => $category,
            'assessment_sequence' => $sequence,
            'score' => $score,
            'max_score' => $validated['max_score'] ?? 100.00,
            'weight' => $validated['weight'] ?? null,
            'source_type' => $validated['source_type'] ?? null,
            'source_id' => $validated['source_id'] ?? null,
            'assessed_date' => $validated['assessed_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $assessment;
    }

    /**
     * Update an assessment record.
     *
     * @param GradeAssessment $assessment
     * @param array $validated
     * @return GradeAssessment
     */
    public function update(GradeAssessment $assessment, array $validated): GradeAssessment
    {
        // If category or sequence changed, re-validate uniqueness
        if (isset($validated['assessment_category']) || isset($validated['assessment_sequence'])) {
            $newCategory = $validated['assessment_category'] ?? $assessment->assessment_category;
            $newSequence = $validated['assessment_sequence'] ?? $assessment->assessment_sequence;

            $conflicts = GradeAssessment::where('student_id', $assessment->student_id)
                ->where('subject_id', $assessment->subject_id)
                ->where('class_id', $assessment->class_id)
                ->where('academic_year_id', $assessment->academic_year_id)
                ->where('semester_id', $assessment->semester_id)
                ->where('assessment_category', $newCategory)
                ->where('assessment_sequence', $newSequence)
                ->where('id', '!=', $assessment->id)
                ->exists();

            if ($conflicts) {
                throw ValidationException::withMessages([
                    'assessment_category' => ['Kategori penilaian "' . $newCategory . ' - Ke-' . $newSequence . '" sudah ada.'],
                ]);
            }
        }

        $data = [];

        if (isset($validated['score'])) {
            $data['score'] = $validated['score'];
        }
        if (isset($validated['max_score'])) {
            $data['max_score'] = $validated['max_score'];
        }
        if (isset($validated['weight'])) {
            $data['weight'] = $validated['weight'];
        }
        if (isset($validated['assessment_name'])) {
            $data['assessment_name'] = $validated['assessment_name'];
        }
        if (isset($validated['notes'])) {
            $data['notes'] = $validated['notes'];
        }
        if (isset($validated['assessed_date'])) {
            $data['assessed_date'] = $validated['assessed_date'];
        }

        $assessment->update($data);

        return $assessment;
    }

    /**
     * Delete an assessment record.
     *
     * @param GradeAssessment $assessment
     * @return bool
     */
    public function delete(GradeAssessment $assessment): bool
    {
        return $assessment->delete();
    }

    /**
     * Validate assessment identity and consistency.
     *
     * @param array $input
     * @return array['valid' => bool, 'errors' => array]
     */
    public function validateIdentity(array $input): array
    {
        $studentId = $input['student_id'] ?? null;
        $subjectId = $input['subject_id'] ?? null;
        $classId = $input['class_id'] ?? null;
        $academicYearId = $input['academic_year_id'] ?? null;
        $semesterId = $input['semester_id'] ?? null;
        $category = $input['assessment_category'] ?? null;
        $sequence = $input['assessment_sequence'] ?? null;

        $errors = [];

        // Validate student exists and has class
        if ($studentId) {
            $student = \App\Models\Students\Student::find($studentId);
            if (!$student) {
                $errors['student_id'] = ['Siswa tidak ditemukan.'];
            } elseif ($student->class_id !== $classId) {
                $errors['student_id'] = ['Siswa tidak terdaftar di kelas ID ' . $classId . '.'];
            }
        }

        // Validate subject belongs to class
        if ($subjectId && $classId) {
            $classSubject = ClassSubject::where('class_id', $classId)
                ->where('subject_id', $subjectId)
                ->exists();
            if (!$classSubject) {
                $errors['subject_id'] = ['Mata pelajaran tidak terdaftar pada kelas ID ' . $classId . '.'];
            }
        }

        // Validate category
        if ($category) {
            $supported = $this->getSupportedCategories();
            if (!in_array($category, $supported, true)) {
                $errors['assessment_category'] = ['Kategori penilaian "' . $category . '" tidak didukung.'];
            }
        }

        // Validate sequence
        if ($sequence !== null && ($sequence < 1 || !is_int($sequence))) {
            $errors['assessment_sequence'] = ['Nomor urut harus minimal 1.'];
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}