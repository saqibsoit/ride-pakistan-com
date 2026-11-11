<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SlotChangeNotice;
use Illuminate\Support\Facades\URL;

/**
 * Signed links for a slot time change (prompt 15A): keep the new time,
 * move, or cancel, straight from the email without signing in. They
 * expire when the session starts at its new time; the signature ties them
 * to the one notice.
 */
final class SlotChangeLink
{
    public static function show(SlotChangeNotice $notice): string
    {
        return self::route('slot-change.show', $notice);
    }

    public static function accept(SlotChangeNotice $notice): string
    {
        return self::route('slot-change.accept', $notice);
    }

    public static function move(SlotChangeNotice $notice): string
    {
        return self::route('slot-change.move', $notice);
    }

    public static function cancel(SlotChangeNotice $notice): string
    {
        return self::route('slot-change.cancel', $notice);
    }

    private static function route(string $name, SlotChangeNotice $notice): string
    {
        return URL::temporarySignedRoute($name, $notice->new_starts_at, ['notice' => $notice->id]);
    }
}
