<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A booking rule said no. The message is safe to show the customer;
 * the code lets callers (and tests) tell the reasons apart.
 */
final class BookingRejected extends RuntimeException
{
    public const SLOT_FULL = 'slot_full';

    public const SLOT_NOT_BOOKABLE = 'slot_not_bookable';

    public const SLOT_NOT_EMPTY = 'slot_not_empty';

    public const PRIVATE_ONLY = 'private_only';

    /** A private session holds or has booked the whole slot (prompt 14). */
    public const BOOKED_PRIVATELY = 'booked_privately';

    public const TOO_MANY_RIDERS = 'too_many_riders';

    public const RIDER_ALREADY_BOOKED = 'rider_already_booked';

    public const INVALID_RIDERS = 'invalid_riders';

    public const INVALID_PRODUCT = 'invalid_product';

    public const NO_CREDITS = 'no_credits';

    public const PAST_CUTOFF = 'past_cutoff';

    public const NOT_ALLOWED = 'not_allowed';

    public const ORDER_NOT_FULFILLABLE = 'order_not_fulfillable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function slotFull(int $free): self
    {
        return new self(self::SLOT_FULL, $free === 0
            ? 'That slot is fully booked. Please choose another.'
            : 'Only '.$free.' '.($free === 1 ? 'seat is' : 'seats are').' left in that slot.');
    }

    public static function slotNotBookable(): self
    {
        return new self(self::SLOT_NOT_BOOKABLE, 'That slot can no longer be booked. Please choose another.');
    }

    public static function privateOnly(): self
    {
        return new self(self::PRIVATE_ONLY, 'That session is for private bookings only. Please choose another time.');
    }

    public static function bookedPrivately(): self
    {
        return new self(self::BOOKED_PRIVATELY, 'That session has been booked privately, so no seats will come free. Please choose another time.');
    }

    public static function slotNotEmpty(): self
    {
        return new self(self::SLOT_NOT_EMPTY, 'A private session needs a slot nobody else has booked. Please choose another.');
    }

    public static function tooManyRiders(int $max): self
    {
        return new self(self::TOO_MANY_RIDERS, 'Up to '.$max.' riders can book this.');
    }

    public static function riderAlreadyBooked(string $name): self
    {
        return new self(self::RIDER_ALREADY_BOOKED, $name.' is already booked in that slot.');
    }

    public static function invalidRiders(): self
    {
        return new self(self::INVALID_RIDERS, 'Choose at least one of your riders.');
    }

    public static function invalidProduct(): self
    {
        return new self(self::INVALID_PRODUCT, 'That option is not available.');
    }

    public static function noCredits(string $message = 'You don’t have enough valid credits for that.'): self
    {
        return new self(self::NO_CREDITS, $message);
    }

    public static function pastCutoff(int $hours): self
    {
        return new self(self::PAST_CUTOFF, 'Changes are possible up to '.$hours.' hours before the session.');
    }

    public static function notAllowed(string $message): self
    {
        return new self(self::NOT_ALLOWED, $message);
    }

    public static function orderNotFulfillable(string $reference, string $status): self
    {
        return new self(self::ORDER_NOT_FULFILLABLE, 'Order '.$reference.' cannot be fulfilled from status '.$status.'.');
    }
}
