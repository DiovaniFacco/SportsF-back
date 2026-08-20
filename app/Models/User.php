<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_GESTOR = 'GESTOR_ESPACO';
    public const ROLE_CLIENTE = 'CLIENTE';

    protected $attributes = [
        'role' => self::ROLE_CLIENTE,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
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
        return $this->role === self::ROLE_GESTOR || $this->isAdmin();
    }

    public function isCliente(): bool
    {
        return $this->role === self::ROLE_CLIENTE;
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
