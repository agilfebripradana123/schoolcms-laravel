<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 — normalize grade period storage.
 *
 * Adds `academic_year_id` and `semester_id` (UNSIGNED, NOT NULL, FK RESTRICT)
 * to `grades` and replaces the legacy string-based UNIQUE tuple with an
 * ID-based UNIQUE tuple:
 *
 *   UNIQUE(student_id, subject_id, class_id, type, semester_id, academic_year_id)
 *
 * while KEEPING the legacy `academic_year` / `semester` string columns as
 * server-synchronized aliases (dual-write contract). The string columns are
 * deliberately NOT dropped in Wave 1.
 *
 * The migration is shape-aware because the live dev DB has a schema diverged
 * from the guarded repository migration (the live table carries a real 6-column
 * string UNIQUE key; a fresh install built from the guarded migration gets only
 * a non-unique lookup index). It never assumes which of these is present.
 *
 * Backfill is verified before any transform and FAILS CLOSED when existing
 * rows cannot be mapped deterministically to a real (academic_year, semester)
 * pair or would collide under the ID-based unique key. Current data is empty,
 * so the backfill step is a verified no-op on this environment.
 *
 * Rollback removes only the added columns/keys and is lossless: the legacy
 * string columns remain the source of truth, and the string UNIQUE key is
 * reinstated when the transformation dropped one.
 */
return new class extends Migration
{
    private const FK_AY = 'fk_grades_academic_year_id';

    private const FK_SEM = 'fk_grades_semester_id';

    private const UNIQ_PERIOD = 'uniq_grades_period';

    public function up(): void
    {
        $conn = DB::connection('mysql');

        if (!Schema::connection('mysql')->hasTable('grades')) {
            return;
        }

        if ($this->columnExists($conn, 'grades', 'academic_year_id')
            && $this->columnExists($conn, 'grades', 'semester_id')) {
            $this->ensureIndexesAndKeys($conn);

            return;
        }

        $rowCount = (int) $conn->table('grades')->count();

        if ($rowCount > 0) {
            $this->assertBackfillable($conn);
        }

        Schema::connection('mysql')->table('grades', function (Blueprint $table) {
            $table->unsignedInteger('academic_year_id')->nullable()->after('academic_year');
            $table->unsignedInteger('semester_id')->nullable()->after('academic_year_id');
        });

        if ($rowCount > 0) {
            $conn->statement(<<<'SQL'
                UPDATE grades g
                INNER JOIN academic_years ay
                    ON ay.name = g.academic_year
                   AND ay.deleted_at IS NULL
                INNER JOIN semesters s
                    ON s.academic_year_id = ay.id
                   AND s.name = g.semester
                SET g.academic_year_id = ay.id,
                    g.semester_id = s.id
                SQL
            );
        }

        Schema::connection('mysql')->table('grades', function (Blueprint $table) {
            $table->unsignedInteger('academic_year_id')->nullable(false)->change();
            $table->unsignedInteger('semester_id')->nullable(false)->change();
        });

        $this->ensureIndexesAndKeys($conn);
    }

    public function down(): void
    {
        $conn = DB::connection('mysql');

        if (!Schema::connection('mysql')->hasTable('grades')) {
            return;
        }

        if (!$this->columnExists($conn, 'grades', 'academic_year_id')
            || !$this->columnExists($conn, 'grades', 'semester_id')) {
            return;
        }

        if ($this->foreignKeyExists($conn, 'grades', self::FK_AY)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->dropForeign(self::FK_AY);
            });
        }

        if ($this->foreignKeyExists($conn, 'grades', self::FK_SEM)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->dropForeign(self::FK_SEM);
            });
        }

        if ($this->indexExists($conn, 'grades', self::UNIQ_PERIOD)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->dropUnique(self::UNIQ_PERIOD);
            });
        }

        foreach (['grades_academic_year_id_index', 'grades_semester_id_index'] as $index) {
            if ($this->indexExists($conn, 'grades', $index)) {
                Schema::connection('mysql')->table('grades', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::connection('mysql')->table('grades', function (Blueprint $table) {
            $table->dropColumn(['academic_year_id', 'semester_id']);
        });

        $this->ensureStringTupleIndex($conn);
    }

    private function assertBackfillable($conn): void
    {
        $unmappable = (int) $conn->selectOne(<<<'SQL'
            SELECT COUNT(*) AS c
            FROM grades g
            LEFT JOIN academic_years ay
                ON ay.name = g.academic_year
               AND ay.deleted_at IS NULL
            LEFT JOIN semesters s
                ON s.academic_year_id = ay.id
               AND s.name = g.semester
            WHERE ay.id IS NULL OR s.id IS NULL
            SQL
        )->c;

        if ($unmappable > 0) {
            throw new RuntimeException(sprintf(
                'Aborting grades period normalization: %d grade row(s) cannot be '
                . 'mapped deterministically to a real (academic_year, semester) pair.',
                $unmappable
            ));
        }

        $duplicates = (int) $conn->selectOne(<<<'SQL'
            SELECT COUNT(*) AS c
            FROM (
                SELECT g.student_id, g.subject_id, g.class_id, g.type,
                       s.id AS semester_id, ay.id AS academic_year_id
                FROM grades g
                INNER JOIN academic_years ay
                    ON ay.name = g.academic_year
                   AND ay.deleted_at IS NULL
                INNER JOIN semesters s
                    ON s.academic_year_id = ay.id
                   AND s.name = g.semester
                GROUP BY g.student_id, g.subject_id, g.class_id, g.type, s.id, ay.id
                HAVING COUNT(*) > 1
            ) d
            SQL
        )->c;

        if ($duplicates > 0) {
            throw new RuntimeException(sprintf(
                'Aborting grades period normalization: %d conflicting ID-based '
                . 'unique tuple(s) would be created.',
                $duplicates
            ));
        }
    }

    private function ensureIndexesAndKeys($conn): void
    {
        // Keep FK child columns indexed independently of the legacy UNIQUE
        // (student_id is only covered by the string UNIQUE on the live schema).
        if (!$this->indexExists($conn, 'grades', 'grades_student_id_index')) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->index('student_id', 'grades_student_id_index');
            });
        }

        $this->dropStringTupleIndexes($conn);

        if (!$this->indexExists($conn, 'grades', 'grades_academic_year_id_index')) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->index('academic_year_id', 'grades_academic_year_id_index');
            });
        }

        if (!$this->indexExists($conn, 'grades', 'grades_semester_id_index')) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->index('semester_id', 'grades_semester_id_index');
            });
        }

        if (!$this->indexExists($conn, 'grades', self::UNIQ_PERIOD)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->unique(
                    ['student_id', 'subject_id', 'class_id', 'type', 'semester_id', 'academic_year_id'],
                    self::UNIQ_PERIOD
                );
            });
        }

        if (!$this->foreignKeyExists($conn, 'grades', self::FK_AY)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->foreign('academic_year_id', self::FK_AY)
                    ->references('id')->on('academic_years')
                    ->restrictOnDelete();
            });
        }

        if (!$this->foreignKeyExists($conn, 'grades', self::FK_SEM)) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) {
                $table->foreign('semester_id', self::FK_SEM)
                    ->references('id')->on('semesters')
                    ->restrictOnDelete();
            });
        }
    }

    private function dropStringTupleIndexes($conn): void
    {
        $indexes = $conn->select(
            'SELECT index_name AS idx_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ?
             GROUP BY index_name',
            [$conn->getDatabaseName(), 'grades']
        );

        foreach ($indexes as $index) {
            $cols = explode(',', $index->cols);

            if (!in_array('semester', $cols, true) || !in_array('academic_year', $cols, true)) {
                continue;
            }

            $this->dropIndex($conn, 'grades', $index->idx_name);
        }
    }

    private function ensureStringTupleIndex($conn): void
    {
        if ($this->indexExists($conn, 'grades', self::UNIQ_PERIOD)) {
            return;
        }

        if (!$this->columnExists($conn, 'grades', 'semester')
            || !$this->columnExists($conn, 'grades', 'academic_year')) {
            return;
        }

        $name = 'student_id_subject_id_class_id_type_semester_academic_year';

        if ($this->indexExists($conn, 'grades', $name)) {
            return;
        }

        $duplicates = (int) $conn->selectOne(<<<'SQL'
            SELECT COUNT(*) AS c
            FROM (
                SELECT 1
                FROM grades
                GROUP BY student_id, subject_id, class_id, type, semester, academic_year
                HAVING COUNT(*) > 1
            ) d
            SQL
        )->c;

        if ($duplicates === 0) {
            Schema::connection('mysql')->table('grades', function (Blueprint $table) use ($name) {
                $table->unique(
                    ['student_id', 'subject_id', 'class_id', 'type', 'semester', 'academic_year'],
                    $name
                );
            });
        }
    }

    private function dropIndex($conn, string $table, string $index): void
    {
        $conn->statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
    }

    private function indexExists($conn, string $table, string $index): bool
    {
        return $conn->table('information_schema.statistics')
            ->where('table_schema', $conn->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function columnExists($conn, string $table, string $column): bool
    {
        return $conn->table('information_schema.columns')
            ->where('table_schema', $conn->getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    private function foreignKeyExists($conn, string $table, string $constraint): bool
    {
        return $conn->table('information_schema.table_constraints')
            ->where('constraint_schema', $conn->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};