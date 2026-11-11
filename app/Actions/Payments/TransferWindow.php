<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * When a bank transfer must arrive (PLAN §3.2): the transfer hold window
 * (booking.transfer_hold_hours), but never later than the order's first
 * session. Transfer is not offered when that session starts within
 * booking.transfer_min_notice_hours.
 *
 * An approved private session request (prompt 15B) also caps it at its
 * payment window (orders.meta.pay_by): the transfer must be uploaded by then.
 */
final class TransferWindow
{
    public function __construct(private readonly Settings $settings) {}

    public function deadline(Order $order, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $deadline = $now->addHours($this->settings->int(SettingKey::TransferHoldHours) ?? 24);
        $start = $this->firstSessionStart($order);

        if ($start !== null && $start->isBefore($deadline)) {
            $deadline = $start;
        }

        $payBy = self::payBy($order);

        return $payBy !== null && $payBy->isBefore($deadline) ? $payBy : $deadline;
    }

    /** The payment window of an approved private request's order, if any. */
    public static function payBy(Order $order): ?CarbonImmutable
    {
        $payBy = $order->meta['pay_by'] ?? null;

        return is_string($payBy) && $payBy !== '' ? CarbonImmutable::parse($payBy)->utc() : null;
    }

    public function isOffered(Order $order, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $start = $this->firstSessionStart($order);

        return $start === null || $start->isAfter($now->addHours($this->minNoticeHours()));
    }

    public function minNoticeHours(): int
    {
        return $this->settings->int(SettingKey::TransferMinNoticeHours) ?? 12;
    }

    public function firstSessionStart(Order $order): ?CarbonImmutable
    {
        $slotIds = $order->items()->whereNotNull('slot_id')->pluck('slot_id')->all();

        if ($slotIds === []) {
            return null;
        }

        $start = Slot::query()->whereIn('id', $slotIds)->min('starts_at');

        return $start === null ? null : CarbonImmutable::parse((string) $start, 'UTC');
    }
}
