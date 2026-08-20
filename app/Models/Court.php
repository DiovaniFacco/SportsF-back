<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Court extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'venue_id',
        'sport_id',
        'name',
        'type',
        'hourly_rate',
        'surface',
        'capacity',
        'is_covered',
        'min_notice_hours',
        'max_advance_days',
        'max_active_bookings_per_user',
    ];

    protected $appends = [
        'sports_list',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'is_covered' => 'boolean',
            'min_notice_hours' => 'integer',
            'max_advance_days' => 'integer',
            'max_active_bookings_per_user' => 'integer',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function sports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'court_sport');
    }

    public function getSportsListAttribute(): array
    {
        if ($this->relationLoaded('sports') && $this->sports->count() > 0) {
            return $this->sports->pluck('name')->toArray();
        }
        if ($this->sport) {
            return [$this->sport->name];
        }
        if ($this->type) {
            return array_map('trim', explode(',', $this->type));
        }
        return ['Futebol'];
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(CourtPricingRule::class);
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(CourtSpecialPrice::class);
    }

    public function blockouts(): HasMany
    {
        return $this->hasMany(CourtBlockout::class);
    }
}
