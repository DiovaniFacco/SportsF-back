<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtSpecialPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'event_date',
        'hourly_rate',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'hourly_rate' => 'decimal:2',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
