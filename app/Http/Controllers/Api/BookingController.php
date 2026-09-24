<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Booking::with(['court.venue', 'user']);

        if ($user->isGestor() && !$user->isAdmin()) {
            // Gestor sees bookings for courts in venues they own
            $query->whereHas('court.venue', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        } elseif (!$user->isAdmin()) {
            // Cliente sees their own bookings
            $query->where('user_id', $user->id);
        }

        if ($request->has('court_id') && $request->query('court_id') !== 'ALL') {
            $query->where('court_id', $request->query('court_id'));
        }

        if ($request->has('date')) {
            $query->whereDate('start_time', $request->query('date'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        } elseif ($request->has('date') || $request->has('court_id')) {
            $query->where('status', Booking::STATUS_CONFIRMED);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->query('payment_status'));
        }

        if ($request->has('date') || $request->boolean('all')) {
            $bookings = $query->orderBy('start_time', 'asc')->get();
            return response()->json([
                'data' => $bookings,
            ]);
        }

        $bookings = $query->orderBy('start_time', 'desc')->paginate($request->query('per_page', 15));

        return response()->json($bookings);
    }

    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'court_id' => ['required', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:Y-m-d H:i:s'],
            'end_time' => ['required', 'date_format:Y-m-d H:i:s', 'after:start_time'],
            'is_manual' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        $extraParams = [
            'is_manual' => $validated['is_manual'] ?? false,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'paid_amount' => $validated['paid_amount'] ?? 0,
            'is_paid' => $validated['is_paid'] ?? false,
        ];

        $booking = $bookingService->createBooking(
            $request->user(),
            $validated['court_id'],
            $validated['start_time'],
            $validated['end_time'],
            $extraParams
        );

        return response()->json([
            'message' => 'Agendamento realizado com sucesso!',
            'booking' => $booking->load(['court.venue', 'user']),
        ], 201);
    }

    /**
     * Cria várias reservas de uma vez, de forma atômica (tudo ou nada).
     * Qualquer slot inválido/conflitante aborta o lote inteiro.
     */
    public function storeBatch(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'court_id' => ['required', 'exists:courts,id'],
            'slots' => ['required', 'array', 'min:1', 'max:10'],
            'slots.*.start_time' => ['required', 'date_format:Y-m-d H:i:s'],
            'slots.*.end_time' => ['required', 'date_format:Y-m-d H:i:s'],
            'notes' => ['nullable', 'string'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        $bookings = $bookingService->createBookingsBatch(
            $request->user(),
            $validated['court_id'],
            $validated['slots'],
            [
                'notes' => $validated['notes'] ?? null,
                'paid_amount' => $validated['paid_amount'] ?? 0,
                'is_paid' => $validated['is_paid'] ?? false,
            ]
        );

        foreach ($bookings as $booking) {
            $booking->load(['court.venue', 'user']);
        }

        return response()->json([
            'message' => count($bookings) === 1
                ? 'Agendamento realizado com sucesso!'
                : count($bookings) . ' agendamentos realizados com sucesso!',
            'bookings' => $bookings,
            'booking' => $bookings[0] ?? null,
        ], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $booking->user_id !== $user->id && $booking->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para visualizar este agendamento.'], 403);
        }

        return response()->json([
            'booking' => $booking->load(['court.venue', 'user']),
        ]);
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookingService): JsonResponse
    {
        $updatedBooking = $bookingService->cancelBooking($request->user(), $booking);

        return response()->json([
            'message' => 'Agendamento cancelado com sucesso.',
            'booking' => $updatedBooking,
        ]);
    }

    /**
     * Registra quanto foi pago em um agendamento (valor em R$).
     * O SISTEMA define automaticamente o payment_status:
     * - pago: paid_amount >= total_price (valor exato da quadra)
     * - pendente: 0 < paid_amount < total_price (pagou só X valor)
     * - nao_pago: paid_amount = 0 (não pagou nada)
     * Apenas o gestor do complexo ou admin — o cliente não marca o próprio pagamento.
     *
     * Payload novo: { "paid_amount": 40 }
     * Legado (compat): { "is_paid": true|false } — true quita total, false zera.
     */
    public function updatePayment(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $booking->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para alterar o pagamento deste agendamento.'], 403);
        }

        $validated = $request->validate([
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        if (!array_key_exists('paid_amount', $validated) && !array_key_exists('is_paid', $validated)) {
            return response()->json([
                'message' => 'Informe o valor pago (paid_amount).',
                'errors' => ['paid_amount' => ['Informe o valor pago (paid_amount).']],
            ], 422);
        }

        // Resolve o valor pago: prioridade para paid_amount; is_paid legado como fallback.
        if (array_key_exists('paid_amount', $validated) && $validated['paid_amount'] !== null) {
            $paidAmount = round((float) $validated['paid_amount'], 2);
        } elseif ($validated['is_paid'] ?? false) {
            $paidAmount = (float) $booking->total_price; // quita total
        } else {
            $paidAmount = 0;
        }

        $booking->applyPaidAmount($paidAmount);
        $booking->save();

        $messages = [
            Booking::PAYMENT_PAGO => 'Agendamento marcado como pago (valor integral recebido).',
            Booking::PAYMENT_PENDENTE => 'Agendamento marcado como pendente (pagamento parcial de R$ ' . number_format((float) $booking->paid_amount, 2, ',', '.') . ' de R$ ' . number_format((float) $booking->total_price, 2, ',', '.') . ').',
            Booking::PAYMENT_NAO_PAGO => 'Agendamento marcado como não pago.',
        ];

        return response()->json([
            'message' => $messages[$booking->payment_status],
            'booking' => $booking->load(['court.venue', 'user']),
        ]);
    }
}
