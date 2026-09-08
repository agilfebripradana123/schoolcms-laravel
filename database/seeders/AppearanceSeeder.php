<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppearanceSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'group' => 'appearance',
                'key' => 'theme',
                'type' => 'select',
                'value' => 'light',
                'description' => 'Tema aplikasi.',
                'is_encrypted' => false,
                'is_public' => false,
                'sort_order' => 0,
            ],
            [
                'group' => 'appearance',
                'key' => 'primary_color',
                'type' => 'color',
                'value' => '#7C3AED',
                'description' => 'Warna utama aplikasi.',
                'is_encrypted' => false,
                'is_public' => false,
                'sort_order' => 1,
            ],
            [
                'group' => 'appearance',
                'key' => 'sidebar_behavior',
                'type' => 'select',
                'value' => 'expanded',
                'description' => 'Perilaku sidebar pada desktop.',
                'is_encrypted' => false,
                'is_public' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                [
                    'group' => $setting['group'],
                    'key' => $setting['key'],
                ],
                array_merge($setting, [
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }
}