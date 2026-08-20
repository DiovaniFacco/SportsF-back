<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'address',
        'number',
        'neighborhood',
        'city',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'phone',
        'cover_image_url',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected $appends = [
        'rating',
        'review_count',
        'sports',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'venue_amenity');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VenueReview::class);
    }

    public function getRatingAttribute(): float
    {
        if ($this->relationLoaded('reviews') && $this->reviews->count() > 0) {
            return round($this->reviews->avg('rating'), 1);
        }
        $avg = $this->reviews()->avg('rating');
        return $avg ? round((float) $avg, 1) : 4.8;
    }

    public function getReviewCountAttribute(): int
    {
        if ($this->relationLoaded('reviews')) {
            return $this->reviews->count();
        }
        return $this->reviews()->count();
    }

    public function getSportsAttribute(): array
    {
        if ($this->relationLoaded('courts')) {
            $allSports = [];
            foreach ($this->courts as $court) {
                $courtSports = $court->sports_list;
                if (!empty($courtSports)) {
                    foreach ($courtSports as $s) {
                        $allSports[] = $s;
                    }
                }
            }
            $uniqueSports = array_values(array_unique(array_filter($allSports)));
            if (!empty($uniqueSports)) {
                return $uniqueSports;
            }
        }
        return ['Futebol', 'Beach Tennis', 'Futsal'];
    }
}
