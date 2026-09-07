<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `attendances` table (student attendance).
 *
 * The table was previously created manually (outside the migration system),
 * so a fresh install had no way to build it. This migration mirrors the exact
 * schema used by:
 *   - App\Models\Students\Attendance
 *   - Api\Students\AttendanceController (admin CRUD)
 *   - Api\Teachers\TeacherAttendanceController (roster + updateOrCreate)
 *   - Api\Students\StudentAttendanceController (read-only)
 *
 * FK columns use `unsignedInteger` to match `students.id` / `classes.id`
 * defined as INT(10) UNSIGNED in the base tables migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendances')) {
            return;
        }

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('student_id');
            $table->unsignedInteger('class_id');
            $table->date('date');
            $table->enum('status', ['hadir', 'sakit', 'izin', 'alpa'])->default('hadir');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'date']);
            $table->index(['student_id', 'date']);

            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->foreign('class_id')
                ->references('id')
                ->on('classes')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};