<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtFixedSchedule;
use App\Models\OperatingHour;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class FixedScheduleService
{
    public const WEEKDAYS_PT = [
        0 => 'domingo',
        1 => 'segunda-feira',
        2 => 'terça-feira',
        3 => 'quarta-feira',
        4 => 'quinta-feira',
        5 => 'sexta-feira',
        6 => 'sábado',
    ];

    /**
     * Valida dados de um horário fixo e retorna dados normalizados.
     * $ignoreId = id a ignorar na checagem de sobreposição (update).
     */
    public function validate(Court $court, array $data, ?int $ignoreId = null): array
    {
        $dayOfWeek = (int) ($data['day_of_week'] ?? -1);
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw ValidationException::withMessages([
                'day_of_week' => ['Informe o dia da semana (0=domingo ... 6=sábado).'],
            ]);
        }

        $startStr = $this->normalizeTime($data['start_time'] ?? null, 'start_time');
        $endStr = $this->normalizeTime($data['end_time'] ?? null, 'end_time');

        if ($endStr <= $startStr) {
            throw ValidationException::withMessages([
                'end_time' => ['O término deve ser após o início (no mesmo dia).'],
            ]);
        }

        $validFrom = $this->normalizeDate($data['valid_from'] ?? null, 'valid_from', true);
        $validUntil = $this->normalizeDate($data['valid_until'] ?? null, 'valid_until', false);

        if ($validUntil && $validUntil->lt($validFrom)) {
            throw ValidationException::withMessages([
                'valid_until' => ['A data final deve ser posterior ou igual à data inicial.'],
            ]);
        }

        // Deve existir grade de funcionamento cobrindo o intervalo
        $shifts = OperatingHour::where('court_id', $court->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')
            ->get();

        if ($shifts->isEmpty()) {
            throw ValidationException::withMessages([
                'day_of_week' => ['A quadra não possui horário de funcionamento neste dia da semana. Cadastre a grade primeiro.'],
            ]);
        }

        $covering = $shifts->first(function (OperatingHour $op) use ($startStr, $endStr) {
            $opStart = substr((string) $op->start_time, 0, 5);
            $opEnd = substr((string) $op->end_time, 0, 5);
            return $opStart <= substr($startStr, 0, 5) && $opEnd >= substr($endStr, 0, 5);
        });

        if (!$covering) {
            $ranges = $shifts->map(fn ($o) => substr((string) $o->start_time, 0, 5) . ' às ' . substr((string) $o->end_time, 0, 5))->join(', ');
            throw ValidationException::withMessages([
                'start_time' => ["O horário fixo deve estar dentro da grade de funcionamento deste dia ({$ranges})."],
            ]);
        }

        // Alinhamento à grade (mesma regra do BookingService)
        $slotDuration = $covering->slot_duration_minutes ?? 60;
        $refDate = '2000-01-01';
        $s = Carbon::parse("{$refDate} {$startStr}");
        $e = Carbon::parse("{$refDate} {$endStr}");
        $opStart = Carbon::parse($refDate . ' ' . $covering->start_time);
        $durationMinutes = $s->diffInMinutes($e);

        if ($durationMinutes % $slotDuration !== 0) {
            throw ValidationException::withMessages([
                'end_time' => ["A duração deve ser múltipla de {$slotDuration} minutos (grade da quadra)."],
            ]);
        }

        $minutesFromOpStart = $opStart->diffInMinutes($s, false);
        if ($minutesFromOpStart < 0 || $minutesFromOpStart % $slotDuration !== 0) {
            throw ValidationException::withMessages([
                'start_time' => ["O início deve ser alinhado à grade (a cada {$slotDuration} min a partir de " . substr((string) $covering->start_time, 0, 5) . ').'],
            ]);
        }

        // Sobreposição com outra série fixa ativa (mesma quadra + mesmo dia)
        $overlap = CourtFixedSchedule::where('court_id', $court->id)
            ->where('is_active', true)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<', $endStr)
            ->where('end_time', '>', $startStr)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            // Só conflita se as vigências se cruzam
            ->whereDate('valid_from', '<=', $validUntil?->format('Y-m-d') ?? '9999-12-31')
            ->where(function ($q) use ($validFrom) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $validFrom->format('Y-m-d'));
            })
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_time' => ["Já existe um horário fixo (" . substr((string) $overlap->start_time, 0, 5) . ' às ' . substr((string) $overlap->end_time, 0, 5) . " de {$overlap->customer_name}) neste dia que se sobrepõe."],
            ]);
        }

        // Conflito com reservas avulsas já confirmadas nas próximas ocorrências.
        // Varre até 120 dias (ou até valid_until) para dar erro amigável já na criação.
        $conflictDate = $this->findBookingConflict($court->id, $dayOfWeek, $startStr, $endStr, $validFrom, $validUntil);
        if ($conflictDate) {
            throw ValidationException::withMessages([
                'start_time' => ["Este horário já possui reserva avulsa em {$conflictDate}. Cancele/remaneje a reserva ou escolha outro horário."],
            ]);
        }

        return [
            'day_of_week' => $dayOfWeek,
            'start_time' => substr($startStr, 0, 5),
            'end_time' => substr($endStr, 0, 5),
            'valid_from' => $validFrom->format('Y-m-d'),
            'valid_until' => $validUntil?->format('Y-m-d'),
        ];
    }

    public static function weekdayLabel(int $dow): string
    {
        return self::WEEKDAYS_PT[$dow] ?? "dia {$dow}";
    }

    private function normalizeTime(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)) {
            throw ValidationException::withMessages([
                $field => ['Informe o horário no formato HH:mm (ex: 19:00).'],
            ]);
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            throw ValidationException::withMessages([
                $field => ['Horário inválido.'],
            ]);
        }

        return sprintf('%02d:%02d:00', $h, $min);
    }

    private function normalizeDate(mixed $value, string $field, bool $required): ?Carbon
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    $field => ['Informe a data inicial da vigência (YYYY-MM-DD).'],
                ]);
            }
            return null;
        }

        try {
            $d = Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => ['Data inválida. Use o formato YYYY-MM-DD.'],
            ]);
        }

        if ($required && $d->lt(Carbon::today())) {
            // Permite retroativo? Não — série fixa vale daqui pra frente.
            throw ValidationException::withMessages([
                $field => ['A data inicial deve ser hoje ou uma data futura.'],
            ]);
        }

        return $d;
    }

    /**
     * Procura a primeira data futura em que o horário fixo colidiria
     * com uma reserva confirmada. Retorna 'DD/MM/YYYY' ou null.
     */
    private function findBookingConflict(
        int $courtId,
        int $dayOfWeek,
        string $startStr,
        string $endStr,
        Carbon $validFrom,
        ?Carbon $validUntil
    ): ?string {
        $limit = Carbon::today()->addDays(120);
        $end = $validUntil ? min($validUntil, $limit) : $limit;
        // max()/min() retornam a própria instância (sem clone): copiar para
        // não mutar $validFrom/$validUntil do chamador nos addDay/addWeek abaixo.
        $end = $end->copy();
        $cursor = max($validFrom, Carbon::today())->copy();

        // Avança até o primeiro dia da semana correspondente
        $guard = 0;
        while ((int) $cursor->dayOfWeek !== $dayOfWeek && $guard++ < 8) {
            $cursor->addDay();
        }

        $startHm = substr($startStr, 0, 5);
        $endHm = substr($endStr, 0, 5);

        while ($cursor->lte($end) && $guard++ < 130) {
            $day = $cursor->format('Y-m-d');
            $occStart = "{$day} {$startHm}:00";
            $occEnd = "{$day} {$endHm}:00";

            $exists = Booking::where('court_id', $courtId)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where('start_time', '<', $occEnd)
                ->where('end_time', '>', $occStart)
                ->exists();

            if ($exists) {
                return $cursor->format('d/m/Y');
            }

            $cursor->addWeek();
        }

        return null;
    }
}
