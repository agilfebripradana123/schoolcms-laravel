<?php

namespace App\Models\Examination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kisi-kisi line item (Phase 2A schema). Model-only foundation; blueprint
 * management/generation belongs to a later phase.
 */
class BlueprintItem extends Model
{
    protected $table = 'blueprint_items';

    protected $fillable = [
        'blueprint_id',
        'competency',
        'material',
        'indicator',
        'question_type',
        'difficulty',
        'count',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'blueprint_id' => 'integer',
            'count' => 'integer',
            'points' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class, 'blueprint_id');
    }
}