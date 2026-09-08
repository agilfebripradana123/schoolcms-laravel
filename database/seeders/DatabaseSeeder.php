<?php

namespace Database\Seeders;

use App\Models\System\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            AppearanceSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'role_id' => 1,
                'username' => 'testuser',
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'photo' => null,
                'is_active' => true,
            ]
        );
    }
}