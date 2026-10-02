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
        'is_covered',
        'image_url',
        'min_notice_hours',
        'max_advance_days',
        'max_active_bookings_per_user',
    ];

    protected $appends = [
        'sports_list',
        'is_blocked_indefinitely',
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
        return ['Vôlei'];
    }

    /**
     * Flag pública para o usuário comum saber (antes de abrir os horários)
     * se a quadra está bloqueada por tempo indeterminado.
     * Usa a relação já carregada quando disponível para evitar N+1.
     */
    public function getIsBlockedIndefinitelyAttribute(): bool
    {
        try {
            if ($this->relationLoaded('activeIndefiniteBlockout')) {
                return $this->activeIndefiniteBlockout !== null;
            }

            // Evita query extra quando o modelo ainda não existe ou já foi avaliado
            if (!$this->exists) {
                return false;
            }

            return $this->activeIndefiniteBlockout()->exists();
        } catch (\Throwable $e) {
            // Coluna ainda não migrada ou DB indisponível: não bloqueia
            return false;
        }
    }

    public function getIndefiniteBlockReasonAttribute(): ?string
    {
        try {
            if ($this->relationLoaded('activeIndefiniteBlockout')) {
                return $this->activeIndefiniteBlockout?->reason;
            }

            if (!$this->exists) {
                return null;
            }

            return $this->activeIndefiniteBlockout()->first()?->reason;
        } catch (\Throwable $e) {
            return null;
        }
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

    public function fixedSchedules(): HasMany
    {
        return $this->hasMany(CourtFixedSchedule::class);
    }

    /**
     * Bloqueio indeterminado ativo (start_time <= agora).
     * Usado para avisar o usuário comum que a quadra está indisponível
     * por tempo indeterminado até o gestor desbloquear.
     */
    public function activeIndefiniteBlockout(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CourtBlockout::class)
            ->where(function ($q) {
                $q->where('is_indefinite', true)->orWhereNull('end_time');
            })
            ->where('start_time', '<=', now())
            ->orderByDesc('start_time')
            ->orderByDesc('id');
    }

    public function getActiveIndefiniteBlockout(?string $referenceDateTime = null): ?CourtBlockout
    {
        $ref = $referenceDateTime ?? now()->toDateTimeString();

        return $this->blockouts()
            ->indefiniteActive($ref)
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->first();
    }

    public function isBlockedIndefinitely(?string $referenceDateTime = null): bool
    {
        return $this->getActiveIndefiniteBlockout($referenceDateTime) !== null;
    }
}
