<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordPtBr;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_GESTOR = 'GESTOR_ESPACO';
    public const ROLE_CLIENTE = 'CLIENTE';
    public const ROLE_FUNCIONARIO = 'FUNCIONARIO';

    protected $attributes = [
        'role' => self::ROLE_CLIENTE,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isGestor(): bool
    {
        return $this->role === self::ROLE_GESTOR || $this->isAdmin() || $this->venues()->exists();
    }

    public function isCliente(): bool
    {
        return $this->role === self::ROLE_CLIENTE && !$this->venues()->exists();
    }

    public function isFuncionario(): bool
    {
        return $this->role === self::ROLE_FUNCIONARIO;
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * Complexos onde o usuário atua como funcionário (pivot venue_staff).
     */
    public function staffVenues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'venue_staff')->withTimestamps();
    }

    public function isStaffOfVenue(int $venueId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return $this->staffVenues()->where('venues.id', $venueId)->exists();
    }

    /**
     * Pode operar a agenda/preços/horários do complexo?
     * Dono (user_id), admin ou funcionário vinculado.
     * NÃO autoriza editar o complexo nem gerenciar quadras.
     */
    public function canOperateVenue(?Venue $venue): bool
    {
        if (!$venue) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ((int) $venue->user_id === (int) $this->id) {
            return true;
        }
        return $this->isStaffOfVenue((int) $venue->id);
    }

    public function canOperateCourt(?Court $court): bool
    {
        if (!$court) {
            return false;
        }
        return $this->canOperateVenue($court->venue);
    }

    /**
     * Ids dos complexos que o usuário pode operar (dono + staff). Admin: null (= todos).
     *
     * @return int[]|null
     */
    public function operableVenueIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $owned = $this->venues()->pluck('venues.id')->all();
        $staff = $this->staffVenues()->pluck('venues.id')->all();
        return array_values(array_unique(array_merge($owned, $staff)));
    }

    /**
     * Envia o e-mail de recuperação em PT-BR com link para o front
     * (FRONTEND_URL + /redefinir-senha?token=…&email=…).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordPtBr($token, $this->email));
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
