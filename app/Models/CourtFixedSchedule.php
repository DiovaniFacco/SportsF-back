<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourtFixedSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'user_id',
        'customer_name',
        'customer_phone',
        'day_of_week',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(CourtFixedScheduleException::class, 'court_fixed_schedule_id');
    }

    /**
     * Dias lançados como agendamento normal (com pagamento).
     * Materializam a ocorrência sem remover a série.
     */
    public function launchedBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'court_fixed_schedule_id');
    }

    /**
     * Verifica se a série vale para uma data (YYYY-MM-DD ou Carbon).
     * Checa is_active, dia da semana e janela de validade.
     * NÃO checa exceptions (use isSkipped / findForDate que já filtra).
     */
    public function appliesToDate(string|\DateTimeInterface $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $d = $date instanceof \DateTimeInterface ? Carbon::parse($date->format('Y-m-d')) : Carbon::parse(substr((string) $date, 0, 10));

        if ((int) $d->dayOfWeek !== (int) $this->day_of_week) {
            return false;
        }

        $validFrom = Carbon::parse($this->valid_from)->startOfDay();
        if ($d->lt($validFrom)) {
            return false;
        }

        if ($this->valid_until) {
            $validUntil = Carbon::parse($this->valid_until)->startOfDay();
            if ($d->gt($validUntil)) {
                return false;
            }
        }

        return true;
    }

    public function isSkippedOn(string $dateStr): bool
    {
        $day = substr($dateStr, 0, 10);

        if ($this->relationLoaded('exceptions')) {
            return $this->exceptions->contains(fn ($e) => substr((string) $e->date, 0, 10) === $day);
        }

        return $this->exceptions()->whereDate('date', $day)->exists();
    }

    /**
     * Verifica sobreposição de intervalo [start,end) com a grade fixa.
     * Compara apenas HH:mm:ss (mesmo dia).
     */
    public function overlaps(string|\DateTimeInterface $start, string|\DateTimeInterface $end): bool
    {
        $s = $start instanceof \DateTimeInterface ? Carbon::parse($start) : Carbon::parse($start);
        $e = $end instanceof \DateTimeInterface ? Carbon::parse($end) : Carbon::parse($end);

        $fixedStart = substr((string) $this->start_time, 0, 8);
        $fixedEnd = substr((string) $this->end_time, 0, 8);

        $slotStart = $s->format('H:i:s');
        $slotEnd = $e->format('H:i:s');

        return $slotStart < $fixedEnd && $slotEnd > $fixedStart;
    }

    /**
     * Horários fixos ativos de uma quadra que valem para uma data.
     * Já exclui os desmarcados (exceptions) daquela data.
     */
    public static function findForDate(int $courtId, string $dateStr): \Illuminate\Support\Collection
    {
        $day = substr($dateStr, 0, 10);
        $dow = Carbon::parse($day)->dayOfWeek;

        $schedules = self::where('court_id', $courtId)
            ->where('is_active', true)
            ->where('day_of_week', $dow)
            ->whereDate('valid_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day);
            })
            ->with(['exceptions' => fn ($q) => $q->whereDate('date', $day)])
            ->get();

        // Filtra os que foram desmarcados só neste dia
        return $schedules->reject(fn (self $s) => $s->exceptions->isNotEmpty());
    }

    public function occurrenceStart(string $dateStr): string
    {
        $t = substr((string) $this->start_time, 0, 5);
        return substr($dateStr, 0, 10) . ' ' . $t . ':00';
    }

    public function occurrenceEnd(string $dateStr): string
    {
        $t = substr((string) $this->end_time, 0, 5);
        return substr($dateStr, 0, 10) . ' ' . $t . ':00';
    }
}
