<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the base registrants table.
 *
 * The registrants table previously existed only in the legacy database
 * dump and was mutated by subsequent alter migrations (e.g.
 * 2026_08_28_000001) that referenced columns like full_name, gender,
 * address and previous_school. This migration guarantees fresh installs
 * have a base table to build upon.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registrants')) {
            return;
        }

        Schema::create('registrants', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number', 50);
            $table->string('full_name', 150)->nullable();
            $table->enum('gender', ['L', 'P'])->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('previous_school', 150)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index('registration_number');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrants');
    }
};