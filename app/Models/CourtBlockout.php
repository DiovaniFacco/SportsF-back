<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtBlockout extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'user_id',
        'start_time',
        'end_time',
        'reason',
        'is_indefinite',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'is_indefinite' => 'boolean',
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
     * Bloqueio indeterminado = sem end_time, válido por tempo indefinido
     * até ser removido (desbloqueado) manualmente pelo gestor.
     */
    public function isIndefinite(): bool
    {
        return (bool) ($this->is_indefinite || is_null($this->end_time));
    }

    /**
     * Verifica se este bloqueio atinge um intervalo [start, end).
     * Bloqueio indeterminado atinge tudo a partir de start_time.
     */
    public function overlaps(string|\DateTimeInterface $start, string|\DateTimeInterface $end): bool
    {
        $startTime = $this->start_time;
        $endTime = $this->end_time;

        if ($this->isIndefinite()) {
            return $startTime->lt($end);
        }

        return $startTime->lt($end) && $endTime->gt($start);
    }

    /**
     * Query scope: bloqueios que atingem o intervalo [start, end),
     * incluindo os indeterminados (end_time NULL).
     */
    public function scopeOverlapping($query, string|\DateTimeInterface $start, string|\DateTimeInterface $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            // Bloqueio normal com sobreposição
            $q->where(function ($nq) use ($start, $end) {
                $nq->whereNotNull('end_time')
                    ->where('start_time', '<', $end)
                    ->where('end_time', '>', $start);
            })->orWhere(function ($iq) use ($end) {
                // Bloqueio indeterminado: vale para tudo após start_time
                $iq->where(function ($w) {
                    $w->where('is_indefinite', true)->orWhereNull('end_time');
                })->where('start_time', '<', $end);
            });
        });
    }

    /**
     * Query scope: bloqueio indeterminado ativo numa referência (default: agora).
     * Ativo = is_indefinite (ou end_time NULL) E start_time <= referência.
     */
    public function scopeIndefiniteActive($query, string|\DateTimeInterface|null $reference = null)
    {
        $ref = $reference ?? now();

        return $query->where(function ($q) {
            $q->where('is_indefinite', true)->orWhereNull('end_time');
        })->where('start_time', '<=', $ref);
    }
}
