<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Customer: the public site and their account only.
 * Staff: the admin panel, read-only slots and bookings (check-in later).
 * Admin: everything in the admin panel.
 */
enum UserRole: string implements HasLabel
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canAccessPanel(): bool
    {
        return $this !== self::Customer;
    }
}
