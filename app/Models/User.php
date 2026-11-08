<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\LogsPersonalDataChanges;
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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Customers and staff (PLAN §5). Staff and admins must use app-based
 * multi-factor authentication to enter the admin panel.
 *
 * @property int $id
 * @property string|null $name null until the customer fills in their profile
 * @property string $email
 * @property UserRole $role
 * @property string|null $phone E.164
 * @property bool $marketing_consent
 * @property CarbonImmutable|null $marketing_consent_at
 * @property CarbonImmutable|null $email_verified_at
 * @property int $session_version bumped by "sign out everywhere"
 * @property CarbonImmutable|null $deleted_at deletion requested (grace period)
 * @property CarbonImmutable|null $anonymised_at
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, LogsPersonalDataChanges, Notifiable, SoftDeletes;

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
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'customer',
        'session_version' => 0,
        'marketing_consent' => false,
    ];

    /**
     * Security changes to an account (PLAN §7): MFA set up, removed or new
     * recovery codes, and password changes are audited, never the values.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            $events = [
                'app_authentication_secret' => $user->app_authentication_secret === null ? ['mfa_disabled', 'MFA removed'] : ['mfa_enabled', 'MFA set up'],
                'app_authentication_recovery_codes' => ['mfa_recovery_codes_changed', 'MFA recovery codes changed'],
                'password' => ['password_changed', 'Password changed'],
            ];

            foreach ($events as $attribute => [$event, $description]) {
                if ($user->wasChanged($attribute)) {
                    activity('auth')->performedOn($user)->event($event)->log($description);
                }
            }
        });
    }

    /**
     * Role changes are logged by ChangeUserRole; secrets never.
     *
     * @return list<string>
     */
    protected function loggedActivityAttributes(): array
    {
        return ['name', 'email', 'phone', 'marketing_consent'];
    }

    /**
     * @return list<string>
     */
    protected function redactedActivityAttributes(): array
    {
        return ['email', 'phone'];
    }

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

    /** Name for greetings and lists; customers may not have given one yet. */
    public function displayName(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->email;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /** When a soft-deleted account stops being restorable by its owner. */
    public function deletionGraceEndsAt(int $graceDays): ?CarbonImmutable
    {
        return $this->deleted_at?->addDays($graceDays);
    }

    public function withinDeletionGracePeriod(int $graceDays): bool
    {
        $endsAt = $this->deletionGraceEndsAt($graceDays);

        return $endsAt !== null && $endsAt->isFuture();
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
            'session_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'anonymised_at' => 'immutable_datetime',
        ];
    }
}
