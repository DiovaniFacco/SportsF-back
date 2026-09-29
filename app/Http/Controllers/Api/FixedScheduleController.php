<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtFixedSchedule;
use App\Models\CourtFixedScheduleException;
use App\Services\FixedScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedScheduleController extends Controller
{
    private function ensureManager(Request $request, Court $court): ?JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar horários fixos nesta quadra.'], 403);
        }
        return null;
    }

    private function ensureManagerOfFixed(Request $request, CourtFixedSchedule $fixed): ?JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $fixed->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar este horário fixo.'], 403);
        }
        return null;
    }

    /** Lista as séries fixas de uma quadra (gestor). */
    public function index(Request $request, ?Court $court = null): JsonResponse
    {
        $user = $request->user();
        $query = CourtFixedSchedule::with(['court', 'exceptions'])->orderBy('day_of_week')->orderBy('start_time');

        if ($court && $court->exists) {
            if ($denied = $this->ensureManager($request, $court)) {
                return $denied;
            }
            $query->where('court_id', $court->id);
        } elseif ($request->has('court_id')) {
            $c = Court::findOrFail($request->query('court_id'));
            if ($denied = $this->ensureManager($request, $c)) {
                return $denied;
            }
            $query->where('court_id', $c->id);
        } elseif (!$user->isAdmin()) {
            $courtIds = Court::whereHas('venue', fn ($q) => $q->where('user_id', $user->id))->pluck('id');
            $query->whereIn('court_id', $courtIds);
        }

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function show(Request $request, CourtFixedSchedule $fixedSchedule): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $fixedSchedule->load(['court', 'exceptions']),
        ]);
    }

    /** Cria série fixa semanal. Ex: toda segunda 19:00-20:00. */
    public function store(Request $request, Court $court, FixedScheduleService $service): JsonResponse
    {
        if ($denied = $this->ensureManager($request, $court)) {
            return $denied;
        }

        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'string', 'max:8'],
            'end_time' => ['required', 'string', 'max:8'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $normalized = $service->validate($court, $validated);

        $fixed = $court->fixedSchedules()->create([
            'user_id' => $request->user()->id,
            'customer_name' => $validated['customer_name'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'day_of_week' => $normalized['day_of_week'],
            'start_time' => $normalized['start_time'],
            'end_time' => $normalized['end_time'],
            'valid_from' => $normalized['valid_from'],
            'valid_until' => $normalized['valid_until'],
            'is_active' => $validated['is_active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Horário fixo criado: toda ' . FixedScheduleService::weekdayLabel($fixed->day_of_week) . ' das ' . substr((string) $fixed->start_time, 0, 5) . ' às ' . substr((string) $fixed->end_time, 0, 5) . '.',
            'data' => $fixed->load('exceptions'),
        ], 201);
    }

    public function update(Request $request, CourtFixedSchedule $fixedSchedule, FixedScheduleService $service): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        $validated = $request->validate([
            'day_of_week' => ['sometimes', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'string', 'max:8'],
            'end_time' => ['sometimes', 'string', 'max:8'],
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'valid_from' => ['sometimes', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merged = array_merge($fixedSchedule->toArray(), $validated);
        // valid_until pode ser explicitamente null (série sem fim)
        if (array_key_exists('valid_until', $validated)) {
            $merged['valid_until'] = $validated['valid_until'];
        }

        $normalized = $service->validate($fixedSchedule->court, $merged, $fixedSchedule->id);

        $fixedSchedule->update([
            'customer_name' => $validated['customer_name'] ?? $fixedSchedule->customer_name,
            'customer_phone' => array_key_exists('customer_phone', $validated) ? $validated['customer_phone'] : $fixedSchedule->customer_phone,
            'day_of_week' => $normalized['day_of_week'],
            'start_time' => $normalized['start_time'],
            'end_time' => $normalized['end_time'],
            'valid_from' => $normalized['valid_from'],
            'valid_until' => $normalized['valid_until'],
            'is_active' => $validated['is_active'] ?? $fixedSchedule->is_active,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $fixedSchedule->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Horário fixo atualizado.',
            'data' => $fixedSchedule->fresh()->load('exceptions'),
        ]);
    }

    /** Exclui a série inteira (todas as ocorrências futuras). */
    public function destroy(Request $request, CourtFixedSchedule $fixedSchedule): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        $fixedSchedule->delete();

        return response()->json(['success' => true, 'message' => 'Horário fixo excluído. Todas as ocorrências futuras foram liberadas.']);
    }

    /**
     * Desmarca UMA ocorrência (ex: segunda 19h do dia 13/10).
     * A série continua valendo nos demais dias.
     */
    public function skip(Request $request, CourtFixedSchedule $fixedSchedule): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $day = substr($validated['date'], 0, 10);
        $dow = Carbon::parse($day)->dayOfWeek;

        if ((int) $dow !== (int) $fixedSchedule->day_of_week) {
            return response()->json([
                'message' => 'Esta data não cai no dia da semana da série fixa.',
                'errors' => ['date' => ['A data ' . Carbon::parse($day)->format('d/m/Y') . ' não é ' . FixedScheduleService::weekdayLabel($fixedSchedule->day_of_week) . '.']],
            ], 422);
        }

        if (!$fixedSchedule->appliesToDate($day)) {
            return response()->json([
                'message' => 'Esta data está fora da vigência da série fixa.',
                'errors' => ['date' => ['Data fora da vigência (valid_from/valid_until).']],
            ], 422);
        }

        $exception = CourtFixedScheduleException::firstOrCreate(
            ['court_fixed_schedule_id' => $fixedSchedule->id, 'date' => $day],
            ['reason' => $validated['reason'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Horário desmarcado só para ' . Carbon::parse($day)->format('d/m/Y') . '. A série continua nos demais dias.',
            'data' => $exception,
        ], 201);
    }

    /** Reativa uma data desmarcada (remove a exceção). */
    public function unskip(Request $request, CourtFixedSchedule $fixedSchedule, CourtFixedScheduleException $exception): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        if ((int) $exception->court_fixed_schedule_id !== (int) $fixedSchedule->id) {
            return response()->json(['message' => 'Exceção não pertence a este horário fixo.'], 404);
        }

        $exception->delete();

        return response()->json(['success' => true, 'message' => 'Data reativada. O horário fixo volta a valer neste dia.']);
    }

    /**
     * Expande as próximas ocorrências da série (para o gestor visualizar).
     * GET /fixed-schedules/{id}/occurrences?from=YYYY-MM-DD&to=YYYY-MM-DD (máx 92 dias)
     */
    public function occurrences(Request $request, CourtFixedSchedule $fixedSchedule): JsonResponse
    {
        if ($denied = $this->ensureManagerOfFixed($request, $fixedSchedule)) {
            return $denied;
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::today();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->startOfDay() : $from->copy()->addDays(60);

        if ($to->diffInDays($from) > 92) {
            $to = $from->copy()->addDays(92);
        }

        $skipped = $fixedSchedule->exceptions()
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $out = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            if ((int) $cursor->dayOfWeek === (int) $fixedSchedule->day_of_week
                && $fixedSchedule->appliesToDate($cursor->format('Y-m-d'))) {
                $day = $cursor->format('Y-m-d');
                $out[] = [
                    'date' => $day,
                    'date_br' => $cursor->format('d/m/Y'),
                    'start_time' => $fixedSchedule->occurrenceStart($day),
                    'end_time' => $fixedSchedule->occurrenceEnd($day),
                    'skipped' => in_array($day, $skipped, true),
                ];
            }
            $cursor->addDay();
        }

        return response()->json(['success' => true, 'data' => $out]);
    }
}
