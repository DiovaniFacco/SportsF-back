<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PAGO = 'pago';
    public const PAYMENT_PENDENTE = 'pendente';
    public const PAYMENT_NAO_PAGO = 'nao_pago';

    protected $fillable = [
        'court_id',
        'user_id',
        'customer_name',
        'customer_phone',
        'is_manual',
        'notes',
        'start_time',
        'end_time',
        'total_price',
        'paid_amount',
        'payment_status',
        'status',
        'is_paid',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'total_price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'is_manual' => 'boolean',
            'is_paid' => 'boolean',
        ];
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Define o payment_status automaticamente a partir do valor pago.
     * Regra: pago só quando paid_amount >= total_price (valor exato da quadra),
     * pendente quando 0 < paid_amount < total_price, nao_pago quando <= 0.
     */
    public static function resolvePaymentStatus(float|string $paidAmount, float|string $totalPrice): string
    {
        $paid = round((float) $paidAmount, 2);
        $total = round((float) $totalPrice, 2);

        if ($paid >= $total && $total > 0) {
            return self::PAYMENT_PAGO;
        }

        if ($paid > 0) {
            return self::PAYMENT_PENDENTE;
        }

        return self::PAYMENT_NAO_PAGO;
    }

    /**
     * Aplica um valor pago e sincroniza payment_status + is_paid (legado).
     */
    public function applyPaidAmount(float|string $paidAmount): void
    {
        $this->paid_amount = round((float) $paidAmount, 2);
        $this->payment_status = self::resolvePaymentStatus($this->paid_amount, $this->total_price);
        // Compatibilidade: is_paid = true somente quando pago integralmente.
        $this->is_paid = $this->payment_status === self::PAYMENT_PAGO;
    }

    /**
     * Valor restante a pagar (total - pago, nunca negativo para exibição).
     */
    public function remainingAmount(): float
    {
        return max(0, round((float) $this->total_price - (float) $this->paid_amount, 2));
    }
}
