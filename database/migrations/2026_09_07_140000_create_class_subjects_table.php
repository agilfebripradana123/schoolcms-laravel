<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `class_subjects` table (class <-> subject catalog with an
 * optional default teacher) that was previously created outside the migration
 * system, so a fresh install can build it.
 *
 * A fresh install gets the full schema including the intended unique
 * constraint UNIQUE(class_id, subject_id). An existing installation that
 * already holds the table keeps its data and gains the unique constraint when
 * no duplicate (class_id, subject_id) pairs are present — the application
 * already enforces this uniqueness in ClassSubjectController and
 * StoreClassSubjectRequest.
 */
return new class extends Migration
{
    private bool $createdTable = false;

    public function up(): void
    {
        if (!Schema::connection('mysql')->hasTable('class_subjects')) {
            Schema::connection('mysql')->create('class_subjects', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('class_id');
                $table->unsignedInteger('subject_id');
                $table->unsignedInteger('teacher_id')->nullable();
                $table->timestamps();

                $table->unique(['class_id', 'subject_id'], 'uniq_class_subjects');

                $table->foreign('class_id')->references('id')->on('classes')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('subject_id')->references('id')->on('subjects')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('teacher_id')->references('id')->on('teachers')
                    ->cascadeOnDelete()
                    ->nullOnUpdate();
            });

            $this->createdTable = true;

            return;
        }

        $this->ensureUniqueIndex();
    }

    public function down(): void
    {
        if ($this->createdTable) {
            Schema::connection('mysql')->dropIfExists('class_subjects');

            return;
        }

        $this->dropUniqueIndexIfExists();
    }

    private function ensureUniqueIndex(): void
    {
        if ($this->indexExists('uniq_class_subjects')) {
            return;
        }

        $duplicates = (int) DB::connection('mysql')->selectOne(
            'SELECT COUNT(*) AS c FROM (SELECT 1 FROM class_subjects GROUP BY class_id, subject_id HAVING COUNT(*) > 1) d'
        )->c;

        if ($duplicates === 0) {
            Schema::connection('mysql')->table('class_subjects', function (Blueprint $table) {
                $table->unique(['class_id', 'subject_id'], 'uniq_class_subjects');
            });

            return;
        }

        report(sprintf(
            'Skipped UNIQUE(class_id, subject_id) on class_subjects: %d duplicate pairs present.',
            $duplicates
        ));
    }

    private function dropUniqueIndexIfExists(): void
    {
        if (!$this->indexExists('uniq_class_subjects')) {
            return;
        }

        Schema::connection('mysql')->table('class_subjects', function (Blueprint $table) {
            $table->dropUnique('uniq_class_subjects');
        });
    }

    private function indexExists(string $indexName): bool
    {
        return DB::connection('mysql')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysql')->getDatabaseName())
            ->where('table_name', 'class_subjects')
            ->where('index_name', $indexName)
            ->exists();
    }
};