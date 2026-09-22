<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh-install fix (Stage B1 / PB-2): emits the `rooms` table before the
 * migrations that declare foreign keys to it (2026_08_25 assets,
 * 2026_08_26 maintenance, 2026_08_27 inventories). The base-tables migration
 * (2026_08_27_100002_5) still guards rooms with `CREATE TABLE IF NOT EXISTS`,
 * so this is a pure additive no-op on existing installations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rooms')) {
            return;
        }

        Schema::create('rooms', function (Blueprint $table) {
            $table->unsignedInteger('id', true)->primary();
            $table->string('name', 50);
            $table->enum('type', ['classroom', 'lab', 'office', 'hall', 'other'])->default('classroom');
            $table->unsignedInteger('capacity')->nullable();
            $table->enum('status', ['available', 'occupied', 'maintenance'])->default('available');
            $table->timestamps();
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};