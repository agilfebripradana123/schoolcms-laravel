<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Academic outcome policy defaults (Phase 2M-2H).
 *
 * Deterministic, non-public defaults for the academic_outcome.* group. These
 * default to a DNF (all score rules disabled) state: no school threshold value
 * is invented here. Semantics-lock booleans only (zero≠missing, missing≠fail).
 */
class AcademicOutcomePolicySeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'score_enabled', 'type' => 'boolean', 'value' => 'false', 'sort_order' => 0, 'description' => 'Aktifkan penilaian ambang skor akhir untuk hasil akademik.'],
            ['key' => 'min_final_score', 'type' => 'integer', 'value' => null, 'sort_order' => 1, 'description' => 'Ambang skor akhir minimum (kosong = nonaktif).'],
            ['key' => 'min_completeness_pct', 'type' => 'integer', 'value' => null, 'sort_order' => 2, 'description' => 'Persentase minimum subjek dengan skor akhir (kosong = nonaktif).'],
            ['key' => 'required_subjects', 'type' => 'string', 'value' => null, 'sort_order' => 3, 'description' => 'Daftar ID subjek wajib berformat JSON (kosong = nonaktif).'],
            ['key' => 'pilihan_included', 'type' => 'boolean', 'value' => 'false', 'sort_order' => 4, 'description' => 'Sertakan subjek pilihan dalam perhitungan skor/kelengkapan.'],
            ['key' => 'remedial_allowed', 'type' => 'boolean', 'value' => 'false', 'sort_order' => 5, 'description' => 'Izinkan bukti remedial sebagai pertimbangan (advisory).'],
            ['key' => 'zero_is_valid', 'type' => 'boolean', 'value' => 'true', 'sort_order' => 6, 'description' => 'Skor 0 adalah nilai valid (bukan nilai hilang).'],
            ['key' => 'missing_is_failure', 'type' => 'boolean', 'value' => 'false', 'sort_order' => 7, 'description' => 'Perlakukan skor akhir hilang sebagai kegagalan.'],
            ['key' => 'report_card_required', 'type' => 'boolean', 'value' => 'false', 'sort_order' => 8, 'description' => 'Rapor semester terminal diterbitkan sebagai kriteria (advisory; finalisasi tetap gated).'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                [
                    'group' => 'academic_outcome',
                    'key' => $setting['key'],
                ],
                [
                    'type' => $setting['type'],
                    'value' => $setting['value'] ?? null,
                    'description' => $setting['description'],
                    'is_encrypted' => false,
                    'is_public' => false,
                    'sort_order' => $setting['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
