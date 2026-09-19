<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['students', 'subjects', 'classes', 'academic_years', 'semesters'];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Check if PK already exists
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if (empty($indexes)) {
                DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
            }
        }
    }

    public function down(): void
    {
        // Intentionally left blank — removing PKs would break existing data integrity.
        // ponytail: no rollback for PK addition; downgrade path is manual ALTER TABLE DROP PRIMARY KEY per table if truly needed.
    }
};
