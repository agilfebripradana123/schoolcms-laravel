<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates base tables that are prerequisites for other modules.
 * This migration defines: students, classes, subjects, rooms, teachers.
 * 
 * These tables are referenced by multiple modules but were previously
 * created outside the migration system. This migration ensures fresh
 * installs work correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
-- =====================================================================
-- Base tables: students, classes, subjects, rooms, teachers
-- These are referenced by academic, student, and other modules.
-- =====================================================================

-- 2) CLASSES
CREATE TABLE IF NOT EXISTS `classes` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `teacher_id` INT(10) UNSIGNED DEFAULT NULL,
  `level` VARCHAR(10) DEFAULT NULL,
  `academic_year` VARCHAR(20) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `classes_teacher_id_index` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) SUBJECTS
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subjects_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) ROOMS
CREATE TABLE IF NOT EXISTS `rooms` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `type` ENUM('classroom','lab','office','hall','other') NOT NULL DEFAULT 'classroom',
  `capacity` INT(10) UNSIGNED DEFAULT NULL,
  `status` ENUM('available','occupied','maintenance') NOT NULL DEFAULT 'available',
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rooms_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5) TEACHERS
CREATE TABLE IF NOT EXISTS `teachers` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `nip` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `gender` ENUM('L','P') NOT NULL,
  `birth_place` VARCHAR(100) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `position` VARCHAR(50) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teachers_nip_unique` (`nip`),
  KEY `teachers_user_id_foreign` (`user_id`),
  CONSTRAINT `teachers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add FK from classes to teachers (if not exists)
ALTER TABLE `classes` ADD CONSTRAINT `classes_teacher_id_foreign` 
  FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

-- 1) STUDENTS (core entity)
CREATE TABLE IF NOT EXISTS `students` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `class_id` INT(10) UNSIGNED DEFAULT NULL,
  `nisn` VARCHAR(20) NOT NULL,
  `nis` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `gender` ENUM('L','P') NOT NULL,
  `birth_place` VARCHAR(100) NOT NULL,
  `birth_date` DATE NOT NULL,
  `address` TEXT NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_nisn_unique` (`nisn`),
  UNIQUE KEY `students_nis_unique` (`nis`),
  KEY `students_user_id_foreign` (`user_id`),
  KEY `students_class_id_foreign` (`class_id`),
  CONSTRAINT `students_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL,
  CONSTRAINT `students_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SQL;

        $lines = array_filter(
            preg_split('/[\r\n]+/', $sql),
            fn ($l) => trim($l) !== '' && !preg_match('/^--/', trim($l))
        );

        $clean = implode("\n", $lines);

        $statements = array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]+/', $clean)),
            fn ($s) => $s !== ''
        );
        
        foreach ($statements as $statement) {
            try {
                DB::connection('mysql')->statement($statement);
            } catch (\Exception $e) {
                // Table or constraint might already exist, continue
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('teachers');
    }
};
