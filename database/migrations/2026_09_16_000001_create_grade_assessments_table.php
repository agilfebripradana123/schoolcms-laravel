<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_assessments', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto-increment PK

            $table->unsignedInteger('student_id');
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('class_id');
            $table->unsignedInteger('academic_year_id');
            $table->unsignedInteger('semester_id');

            $table->string('assessment_category', 50);
            $table->unsignedInteger('assessment_sequence');
            $table->string('assessment_name', 100)->nullable();
            $table->decimal('score', 5, 2);
            $table->decimal('max_score', 5, 2)->default(100.00);
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('source_type', 50)->nullable();
            $table->unsignedInteger('source_id')->nullable();
            $table->date('assessed_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_id', 'subject_id', 'class_id', 'academic_year_id', 'semester_id', 'assessment_category', 'assessment_sequence'],
                'uq_grade_assessments_cat_seq'
            );

            $table->index('student_id');
            $table->index('subject_id');
            $table->index('class_id');
            $table->index('academic_year_id');
            $table->index('semester_id');
            $table->index('assessment_category');
            $table->index(['source_type', 'source_id']);

            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('cascade');
            $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->onDelete('restrict');
            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_assessments');
    }
};