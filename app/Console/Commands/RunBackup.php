<?php

namespace App\Console\Commands;

use App\Models\System\BackupLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RunBackup extends Command
{
    protected $signature = 'backup:run';
    protected $description = 'Run database backup';

    public function handle(): int
    {
        $log = BackupLog::create([
            'status' => 'running',
            'type' => 'full',
            'started_at' => now(),
        ]);

        try {
            $filename = 'backup_' . now()->format('Y_m_d_His') . '.sql';
            $path = storage_path("app/backups/{$filename}");

            if (!is_dir(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            $db = config('database.connections.mysql');
            $cmd = sprintf(
                'mysqldump --user=%s --password=%s --host=%s --port=%s %s > %s',
                escapeshellarg($db['username']),
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                escapeshellarg($db['port'] ?? '3306'),
                escapeshellarg($db['database']),
                escapeshellarg($path)
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception('mysqldump failed with exit code ' . $exitCode);
            }

            $log->update([
                'status' => 'success',
                'file_path' => "backups/{$filename}",
                'file_size' => filesize($path),
                'completed_at' => now(),
                'description' => 'Full backup completed successfully.',
            ]);

            $this->info("Backup completed: {$filename}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'description' => $e->getMessage(),
            ]);

            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}