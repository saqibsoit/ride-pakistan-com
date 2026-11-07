<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Customer: the public site and their account only.
 * Staff: the admin panel, read-only slots and bookings (check-in later).
 * Admin: everything in the admin panel.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canAccessPanel(): bool
    {
        return $this !== self::Customer;
    }
}
