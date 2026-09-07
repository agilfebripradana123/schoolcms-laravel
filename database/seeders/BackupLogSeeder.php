<?php

namespace Database\Seeders;

use App\Models\System\BackupLog;
use App\Models\System\User;
use Illuminate\Database\Seeder;
class BackupLogSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $testUser = $users->first() ?? null;

        $statuses = ['success', 'success', 'success', 'success', 'success', 'success', 'failed', 'failed', 'running', 'success'];
        $descriptions = [
            'Full backup completed successfully.',
            'Full backup completed successfully.',
            'Full backup completed successfully.',
            'Full backup completed successfully.',
            'Full backup completed successfully.',
            'Full backup completed successfully.',
            'mysqldump failed: permission denied',
            'mysqldump failed: disk full',
            'Backup in progress...',
            'Full backup completed successfully.',
        ];

        $baseDate = now()->subDays(9);

        for ($i = 0; $i < 10; $i++) {
            $date = $baseDate->copy()->addHours($i * 4 + 2);
            $log = BackupLog::create([
                'user_id' => $testUser?->id,
                'status' => $statuses[$i],
                'file_path' => "backups/backup_{$date->format('Y_m_d_His')}.sql",
                'file_size' => rand(500000, 5000000),
                'type' => 'full',
                'description' => $descriptions[$i],
                'started_at' => $date,
                'completed_at' => $date->copy()->addMinutes(rand(5, 30)),
                'created_at' => $date->copy()->addMinutes(rand(1, 5)),
            ]);
        }
    }
}