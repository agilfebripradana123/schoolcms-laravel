<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for creating the `academic_years` table.
 *
 * This is the ONLY migration responsible for creating the table. It runs
 * BEFORE the academic modules (100004) that create foreign keys referencing
 * academic_years.
 *
 * The previous duplicate raw-SQL migration (100003 original) and the orphaned
 * soft-deletes migration (2026_08_26_000001) were consolidated here so that a
 * fresh install has a single, consistent definition with soft deletes.
 *
 * `start_date` / `end_date` are added by a separate additive migration
 * (2026_08_28_000000_add_dates_to_academic_years_table.php) so that already
 * migrated databases that lack those columns are upgraded cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 20)->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $seed = [
            ['2024/2025', false],
            ['2025/2026', true],
            ['2026/2027', false],
        ];

        foreach ($seed as [$name, $active]) {
            DB::table('academic_years')->insertOrIgnore([
                'name' => $name,
                'is_active' => $active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
