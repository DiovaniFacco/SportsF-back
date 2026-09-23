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

    public const MODE_COMPLETO = 'completo';
    public const MODE_SIMPLIFICADO = 'simplificado';

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
        'cancel_limit_hours',
        'mode',
        'whatsapp_number',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'cancel_limit_hours' => 'integer',
    ];

    protected $appends = [
        'rating',
        'review_count',
        'sports',
        'is_open_today',
        'slug',
        'is_simplified',
    ];

    public function getSlugAttribute(): string
    {
        return \Illuminate\Support\Str::slug($this->name);
    }

    /**
     * Indica se o complexo está no modo Simplificado (agenda visual + WhatsApp).
     * Qualquer valor diferente de 'simplificado' é tratado como modo completo,
     * mantendo o comportamento atual para complexos existentes.
     */
    public function getIsSimplifiedAttribute(): bool
    {
        return strtolower(trim((string) ($this->attributes['mode'] ?? self::MODE_COMPLETO))) === self::MODE_SIMPLIFICADO;
    }

    public function isSimplified(): bool
    {
        return $this->is_simplified;
    }

    /**
     * Número de WhatsApp efetivo: prioriza whatsapp_number, com fallback para phone.
     */
    public function getWhatsappNumberResolvedAttribute(): ?string
    {
        $raw = $this->whatsapp_number ?? $this->phone;
        if (empty($raw)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $raw);
        return $digits !== '' ? $digits : null;
    }

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
        return ['Vôlei', 'Futevôlei', 'Beach Tennis', 'Padel'];
    }

    public function getIsOpenTodayAttribute(): bool
    {
        $today = now()->dayOfWeek; // 0 (Domingo) a 6 (Sábado)

        if ($this->relationLoaded('courts')) {
            $courts = $this->courts;
            if ($courts->isEmpty()) {
                return true;
            }

            $hasAnyHoursConfigured = false;
            foreach ($courts as $court) {
                if ($court->relationLoaded('operatingHours')) {
                    if ($court->operatingHours->isNotEmpty()) {
                        $hasAnyHoursConfigured = true;
                    }
                    if ($court->operatingHours->where('day_of_week', $today)->isNotEmpty()) {
                        return true;
                    }
                } else {
                    if ($court->operatingHours()->exists()) {
                        $hasAnyHoursConfigured = true;
                    }
                    if ($court->operatingHours()->where('day_of_week', $today)->exists()) {
                        return true;
                    }
                }
            }

            return !$hasAnyHoursConfigured;
        }

        $courtIds = Court::where('venue_id', $this->id)->pluck('id');
        if ($courtIds->isEmpty()) {
            return true;
        }

        $hasAnyHoursConfigured = OperatingHour::whereIn('court_id', $courtIds)->exists();
        if (!$hasAnyHoursConfigured) {
            return true;
        }

        return OperatingHour::whereIn('court_id', $courtIds)
            ->where('day_of_week', $today)
            ->exists();
    }
}
