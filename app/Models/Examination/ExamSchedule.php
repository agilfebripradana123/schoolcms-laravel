<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Facilities\Room;
class ExamSchedule extends Model
{
    protected $table = 'exam_schedules';

    protected $fillable = [
        'exam_id',
        'room_id',
        'session_id',
        'exam_date',
        'supervisor_id',
        'start_datetime',
        'end_datetime',
    ];

    protected function casts(): array
    {
        return [
            'exam_id' => 'integer',
            'room_id' => 'integer',
            'session_id' => 'integer',
            'exam_date' => 'date:Y-m-d',
            'supervisor_id' => 'integer',
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }
}
