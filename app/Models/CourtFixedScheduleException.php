<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtFixedScheduleException extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_fixed_schedule_id',
        'date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    public function fixedSchedule(): BelongsTo
    {
        return $this->belongsTo(CourtFixedSchedule::class, 'court_fixed_schedule_id');
    }
}
