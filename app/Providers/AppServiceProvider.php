<?php

namespace App\Providers;

use App\Models\System\AuditLog;
use App\Models\System\BackupLog;
use App\Models\System\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    private const AUDIT_SKIP = [
        AuditLog::class,
        BackupLog::class,
        Setting::class,
        PersonalAccessToken::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen('eloquent.created: *', function (string $eventName, array $data) {
            $this->auditLog('create', $data[0] ?? null);
        });

        Event::listen('eloquent.updated: *', function (string $eventName, array $data) {
            $this->auditLog('update', $data[0] ?? null);
        });

        Event::listen('eloquent.deleted: *', function (string $eventName, array $data) {
            $this->auditLog('delete', $data[0] ?? null);
        });
    }

    private function auditLog(string $action, ?Model $model): void
    {
        if (!$model || in_array(get_class($model), self::AUDIT_SKIP, true)) {
            return;
        }

        $user = Auth::user();
        $short = class_basename($model);
        $labels = ['create' => 'Membuat', 'update' => 'Memperbarui', 'delete' => 'Menghapus'];

        AuditLog::insert([
            'user_id' => $user?->id,
            'action' => $action,
            'model' => $short,
            'model_id' => $model->getKey(),
            'description' => ($labels[$action] ?? ucfirst($action)) . " {$short} #{$model->getKey()}",
            'ip_address' => request()->ip() ?? '0.0.0.0',
            'user_agent' => substr(request()->userAgent() ?? '', 0, 255),
            'created_at' => now(),
        ]);
    }
}
