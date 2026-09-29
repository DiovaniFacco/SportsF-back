<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockout;
use App\Models\CourtFixedSchedule;
use App\Models\OperatingHour;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        protected BookingSlotService $bookingSlotService
    ) {}

    /**
     * Create a booking with strict validation rules and concurrency protection.
     *
     * @param User $user User performing the action (or booking owner)
     * @param int $courtId
     * @param string $startTimeStr
     * @param string $endTimeStr
     * @param array $extraParams Options for manual booking: ['is_manual' => bool, 'customer_name' => string, 'customer_phone' => string, 'notes' => string]
     * @return Booking
     * @throws ValidationException
     */
    public function createBooking(
        User $user,
        int $courtId,
        string $startTimeStr,
        string $endTimeStr,
        array $extraParams = []
    ): Booking {
        $startTime = Carbon::parse($startTimeStr);
        $endTime = Carbon::parse($endTimeStr);
        $now = now();

        $isManual = $extraParams['is_manual'] ?? false;

        if ($startTime->gte($endTime)) {
            throw ValidationException::withMessages([
                'end_time' => ['O horário de término deve ser posterior ao horário de início.'],
            ]);
        }

        if (!$isManual && $startTime->lt($now)) {
            throw ValidationException::withMessages([
                'start_time' => ['Não é possível agendar para um horário no passado.'],
            ]);
        }

        // If manual booking, verify that user is Gestor or Admin
        if ($isManual) {
            if (!$user->isGestor() && !$user->isAdmin()) {
                throw ValidationException::withMessages([
                    'is_manual' => ['Apenas gestores ou administradores podem realizar agendamentos manuais/presenciais.'],
                ]);
            }
            if (empty($extraParams['customer_name'])) {
                throw ValidationException::withMessages([
                    'customer_name' => ['O nome do cliente é obrigatório para agendamentos manuais de balcão.'],
                ]);
            }
        }

        return DB::transaction(function () use ($user, $courtId, $startTime, $endTime, $now, $isManual, $extraParams) {
            // 1. Lock court row pessimistically
            $court = Court::where('id', $courtId)->lockForUpdate()->first();

            if (!$court) {
                throw ValidationException::withMessages([
                    'court_id' => ['Quadra não encontrada.'],
                ]);
            }

            // If manual booking by gestor, check ownership of venue unless Admin
            if ($isManual && !$user->isAdmin() && $court->venue->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'court_id' => ['Você não tem permissão para gerenciar agendamentos nesta quadra.'],
                ]);
            }

            // 2. Validate Minimum Notice Hours (Antecedência Mínima)
            $minNoticeHours = $court->min_notice_hours ?? 1;
            if (!$isManual && $startTime->lt($now->copy()->addHours($minNoticeHours))) {
                throw ValidationException::withMessages([
                    'start_time' => ["Os agendamentos nesta quadra devem ser realizados com pelo menos {$minNoticeHours} hora(s) de antecedência."],
                ]);
            }

            // 3. Validate Maximum Advance Days (Antecedência Máxima)
            $maxAdvanceDays = $court->max_advance_days ?? 30;
            if (!$isManual && $startTime->gt($now->copy()->addDays($maxAdvanceDays))) {
                throw ValidationException::withMessages([
                    'start_time' => ["Os agendamentos só podem ser realizados com no máximo {$maxAdvanceDays} dias de antecedência."],
                ]);
            }

            // 4. Validate Maximum Active Bookings per User (Limite de Reservas Ativas por Cliente)
            if (!$isManual && $user->isCliente()) {
                $maxActiveBookings = $court->max_active_bookings_per_user ?? 3;
                $activeBookingsCount = Booking::where('user_id', $user->id)
                    ->where('status', Booking::STATUS_CONFIRMED)
                    ->where('start_time', '>=', $now)
                    ->count();

                if ($activeBookingsCount >= $maxActiveBookings) {
                    throw ValidationException::withMessages([
                        'user' => ["Você já possui {$activeBookingsCount} reserva(s) ativa(s). O limite máximo permitido é de {$maxActiveBookings} reserva(s) pendente(s)/futura(s)."],
                    ]);
                }
            }

            // 5. Validate Operating Hours (suporta vários turnos no dia:
            // o agendamento deve estar CONTIDO em um único turno, sem
            // atravessar o intervalo entre turnos).
            $dayOfWeek = $startTime->dayOfWeek;
            $shifts = OperatingHour::where('court_id', $courtId)
                ->where('day_of_week', $dayOfWeek)
                ->orderBy('start_time')
                ->get();

            if ($shifts->isEmpty()) {
                throw ValidationException::withMessages([
                    'start_time' => ['A quadra não possui horário de funcionamento cadastrado para este dia da semana.'],
                ]);
            }

            $dateStr = $startTime->format('Y-m-d');
            $coveringShift = $shifts->first(function (OperatingHour $op) use ($dateStr, $startTime, $endTime) {
                $opStart = Carbon::parse($dateStr . ' ' . $op->start_time);
                $opEnd = Carbon::parse($dateStr . ' ' . $op->end_time);
                return $startTime->gte($opStart) && $endTime->lte($opEnd);
            });

            if (!$coveringShift) {
                $ranges = $shifts->map(fn (OperatingHour $o) => substr((string) $o->start_time, 0, 5) . ' às ' . substr((string) $o->end_time, 0, 5))->join(', ');
                throw ValidationException::withMessages([
                    'start_time' => ["O agendamento deve estar dentro de um turno de funcionamento deste dia ({$ranges}), sem atravessar o intervalo entre turnos."],
                ]);
            }

            $opStart = Carbon::parse($dateStr . ' ' . $coveringShift->start_time);
            $slotDuration = $coveringShift->slot_duration_minutes ?? 60;
            $durationMinutes = $startTime->diffInMinutes($endTime);

            // 6. Validação de duração / alinhamento.
            // Grade (slot_duration_minutes) é só INTERVALO DE EXIBIÇÃO.
            // - Cliente (app): mantém o encaixe estrito na grade, para não
            //   fragmentar a agenda (reserva via multi-slot).
            // - Gestor (balcão/manual): duração livre (ex: 1h30 numa grade
            //   de 60min). A proteção contra duplo-booking é a checagem de
            //   sobreposição do passo 8, que já bloqueia os slots atingidos.
            if (!$isManual) {
                if ($durationMinutes % $slotDuration !== 0) {
                    throw ValidationException::withMessages([
                        'end_time' => ["A duração do agendamento deve ser múltipla de {$slotDuration} minutos."],
                    ]);
                }

                $minutesFromOpStart = $opStart->diffInMinutes($startTime, false);
                if ($minutesFromOpStart < 0 || $minutesFromOpStart % $slotDuration !== 0) {
                    throw ValidationException::withMessages([
                        'start_time' => ["O horário de início do agendamento deve ser alinhado com a grade de slots da quadra (a cada {$slotDuration} minutos a partir de " . substr((string) $coveringShift->start_time, 0, 5) . ').'],
                    ]);
                }
            } else {
                if ($durationMinutes < 15) {
                    throw ValidationException::withMessages([
                        'end_time' => ['A duração mínima do agendamento é de 15 minutos.'],
                    ]);
                }
                if ($startTime->second !== 0 || $endTime->second !== 0) {
                    throw ValidationException::withMessages([
                        'start_time' => ['Informe os horários com precisão de minuto (segundos zerados).'],
                    ]);
                }
            }

            // 7. Validate Court Blockouts (Bloqueio de Agenda por Manutenção/Eventos)
            // Inclui bloqueio indeterminado (sem end_time): bloqueia tudo após start_time.
            $hasBlockout = CourtBlockout::where('court_id', $courtId)
                ->overlapping($startTime, $endTime)
                ->first();

            if ($hasBlockout) {
                if ($hasBlockout->isIndefinite()) {
                    $reasonText = $hasBlockout->reason ? " Motivo: {$hasBlockout->reason}" : '';
                    throw ValidationException::withMessages([
                        'start_time' => ["Esta quadra está temporariamente indisponível por tempo indeterminado.{$reasonText} Tente outra quadra ou data."],
                    ]);
                }
                $reasonText = $hasBlockout->reason ? " Motivo: {$hasBlockout->reason}" : '';
                throw ValidationException::withMessages([
                    'start_time' => ["Este horário está bloqueado pelo gestor para manutenção ou evento.{$reasonText}"],
                ]);
            }

            // 7.5 Validate Fixed Schedules (horário fixo semanal ocupa o slot).
            // Exceção desmarcada neste dia libera o horário para avulso.
            // Vínculo explícito (lançar o dia, só gestor via is_manual): o booking
            // materializa aquela ocorrência e não sofre o bloqueio do próprio fixo.
            $linkedFixedId = $extraParams['fixed_schedule_id'] ?? null;
            $linkedFixed = null;
            if ($linkedFixedId && $isManual) {
                $linkedFixed = CourtFixedSchedule::find($linkedFixedId);
                if (!$linkedFixed || (int) $linkedFixed->court_id !== (int) $courtId) {
                    throw ValidationException::withMessages([
                        'fixed_schedule_id' => ['Horário fixo inválido para esta quadra.'],
                    ]);
                }
                $occDate = $startTime->format('Y-m-d');
                if (!$linkedFixed->appliesToDate($occDate)
                    || $linkedFixed->isSkippedOn($occDate)
                    || !$linkedFixed->overlaps($startTime, $endTime)) {
                    throw ValidationException::withMessages([
                        'fixed_schedule_id' => ['A data não corresponde a uma ocorrência válida deste horário fixo.'],
                    ]);
                }
            }

            try {
                $fixedConflict = CourtFixedSchedule::findForDate($courtId, $startTime->format('Y-m-d'))
                    ->reject(fn (CourtFixedSchedule $f) => $linkedFixed && (int) $f->id === (int) $linkedFixed->id)
                    ->first(fn (CourtFixedSchedule $f) => $f->overlaps($startTime, $endTime));
            } catch (\Throwable) {
                $fixedConflict = null;
            }

            if ($fixedConflict) {
                $weekday = \App\Services\FixedScheduleService::weekdayLabel((int) $fixedConflict->day_of_week);
                throw ValidationException::withMessages([
                    'start_time' => ["Este horário é fixo de {$fixedConflict->customer_name} toda {$weekday} (" . substr((string) $fixedConflict->start_time, 0, 5) . ' às ' . substr((string) $fixedConflict->end_time, 0, 5) . '). Escolha outro horário.'],
                ]);
            }

            // 8. Strict anti-double booking check
            $hasOverlap = Booking::where('court_id', $courtId)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->lockForUpdate()
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'start_time' => ['Este horário já se encontra reservado por outro cliente (Double booking bloqueado).'],
                ]);
            }

            // 9. Calculate Dynamic Total Price
            $hourlyRate = $this->bookingSlotService->determineHourlyRate(
                $court,
                $dateStr,
                $dayOfWeek,
                $startTime,
                $endTime
            );
            $totalPrice = round(($hourlyRate * $durationMinutes) / 60, 2);

            // 10. Create Booking (pagamento: gestor informa paid_amount, sistema define status)
            $paidAmount = round((float) ($extraParams['paid_amount'] ?? 0), 2);
            // Compat: se vier is_paid=true legado sem paid_amount, assume valor integral.
            if (($extraParams['is_paid'] ?? false) && $paidAmount <= 0) {
                $paidAmount = $totalPrice;
            }
            // O valor pago nunca pode superar o total do agendamento:
            // evita erro de digitação inflando o faturamento (métricas somam paid_amount).
            if ($paidAmount > $totalPrice) {
                throw ValidationException::withMessages([
                    'paid_amount' => ['O valor pago (R$ ' . number_format($paidAmount, 2, ',', '.') . ') não pode ser maior que o valor total do agendamento (R$ ' . number_format($totalPrice, 2, ',', '.') . ').'],
                ]);
            }
            $paymentStatus = Booking::resolvePaymentStatus($paidAmount, $totalPrice);

            return Booking::create([
                'court_id' => $court->id,
                'court_fixed_schedule_id' => $linkedFixed?->id,
                'user_id' => $isManual ? null : $user->id,
                'customer_name' => $isManual ? $extraParams['customer_name'] : $user->name,
                'customer_phone' => $isManual ? ($extraParams['customer_phone'] ?? null) : $user->phone,
                'is_manual' => $isManual,
                'notes' => $extraParams['notes'] ?? null,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'total_price' => $totalPrice,
                'paid_amount' => $paidAmount,
                'payment_status' => $paymentStatus,
                'status' => Booking::STATUS_CONFIRMED,
                'is_paid' => $paymentStatus === Booking::PAYMENT_PAGO,
            ]);
        });
    }

    /**
     * Create multiple bookings atomically (all-or-nothing).
     *
     * Usado pela seleção multi-slot do app: ou todos os horários são
     * reservados, ou nenhum é (evita "sucesso parcial"). Qualquer falha
     * de validação/concorrência em um slot aborta o lote inteiro via
     * rollback da transação externa (os createBooking internos usam
     * savepoints e participam do mesmo rollback).
     *
     * @param User $user
     * @param int $courtId
     * @param array $slots Lista de ['start_time' => 'Y-m-d H:i:s', 'end_time' => 'Y-m-d H:i:s']
     * @param array $extraParams
     * @return Booking[]
     * @throws ValidationException
     */
    public function createBookingsBatch(
        User $user,
        int $courtId,
        array $slots,
        array $extraParams = []
    ): array {
        if (empty($slots)) {
            throw ValidationException::withMessages([
                'slots' => ['Informe ao menos um horário para reservar.'],
            ]);
        }

        // paid_amount único não tem como ser distribuído entre os slots
        // (cada um tem seu próprio total): aplicar o mesmo valor em cada
        // reserva duplicaria a receita. O pagamento do lote deve ser
        // registrado por agendamento, após a criação.
        $batchPaid = round((float) ($extraParams['paid_amount'] ?? 0), 2);
        if ($batchPaid > 0) {
            throw ValidationException::withMessages([
                'paid_amount' => ['Para reservas em lote, não informe o valor pago na criação: registre o pagamento em cada agendamento após a confirmação.'],
            ]);
        }

        return DB::transaction(function () use ($user, $courtId, $slots, $extraParams) {
            $bookings = [];
            foreach ($slots as $slot) {
                $bookings[] = $this->createBooking(
                    $user,
                    $courtId,
                    $slot['start_time'],
                    $slot['end_time'],
                    $extraParams
                );
            }
            return $bookings;
        });
    }

    /**
     * Cancel a booking.
     */
    public function cancelBooking(User $user, Booking $booking): Booking
    {
        if (!$user->isAdmin() && $user->id !== $booking->user_id && $user->id !== $booking->court?->venue?->user_id) {
            throw ValidationException::withMessages([
                'booking' => ['Você não tem permissão para cancelar este agendamento.'],
            ]);
        }

        if ($booking->status === Booking::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'booking' => ['Este agendamento já foi cancelado.'],
            ]);
        }

        // Se for cliente, validar antecedência de cancelamento
        if (!$user->isAdmin() && $user->id !== $booking->court?->venue?->user_id) {
            $cancelLimitHours = $booking->court?->venue?->cancel_limit_hours ?? 0;
            if ($cancelLimitHours > 0) {
                $limitTime = now()->addHours($cancelLimitHours);
                if ($booking->start_time->lt($limitTime)) {
                    throw ValidationException::withMessages([
                        'booking' => ["O cancelamento deve ser feito com pelo menos {$cancelLimitHours} hora(s) de antecedência."],
                    ]);
                }
            } else if ($booking->start_time->lt(now())) {
                throw ValidationException::withMessages([
                    'booking' => ["Não é possível cancelar um agendamento no passado."],
                ]);
            }
        }

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
            // Cancelamento estorna o pagamento: sem ele, o paid_amount ficaria
            // registrado numa reserva cancelada e as métricas (que somam o valor
            // pago) divergiriam do caixa real.
            'paid_amount' => 0,
            'payment_status' => Booking::PAYMENT_NAO_PAGO,
            'is_paid' => false,
        ]);

        return $booking;
    }
}
