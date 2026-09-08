<?php

namespace App\Models\System;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'backup_logs';

    protected $fillable = [
        'user_id',
        'status',
        'file_path',
        'file_size',
        'type',
        'description',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}