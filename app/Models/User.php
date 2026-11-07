<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Customers and staff (PLAN §5). Staff and admins must use app-based
 * multi-factor authentication to enter the admin panel.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $phone E.164
 * @property bool $marketing_consent
 * @property CarbonImmutable|null $marketing_consent_at
 * @property CarbonImmutable|null $email_verified_at
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Role is deliberately not mass assignable: it changes only through
     * the admin customer form (logged) or the admin:create command.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'marketing_consent',
        'marketing_consent_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'customer',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role->canAccessPanel();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    /** Staff or admin. */
    public function isTeamMember(): bool
    {
        return $this->role->canAccessPanel();
    }

    /**
     * @return HasMany<Rider, $this>
     */
    public function riders(): HasMany
    {
        return $this->hasMany(Rider::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<CreditPack, $this>
     */
    public function creditPacks(): HasMany
    {
        return $this->hasMany(CreditPack::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'immutable_datetime',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}
