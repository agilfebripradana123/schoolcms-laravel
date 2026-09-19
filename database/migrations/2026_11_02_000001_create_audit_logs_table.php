<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reproduce the legacy audit_logs schema (origin: schoolcms_db.sql, CI4 era)
 * for fresh / migration-managed databases.
 *
 * The existing legacy production table is externally managed (DBA); when it
 * already exists this migration intentionally does nothing — it never alters,
 * renames, indexes, or recreates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('model_id')->nullable();
            $table->text('description');
            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};