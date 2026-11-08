<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\SettingKey;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Gift voucher limits from settings (milestone 10e). Amounts in settings
 * are whole rupees; everything returned here is in paisa.
 */
final class VoucherRules
{
    /** A voucher can be scheduled at most this many days ahead (Pakistan date). */
    public const MAX_SEND_AHEAD_DAYS = 90;

    /** Delivery time on the chosen day, Pakistan time. */
    public const SEND_HOUR = 9;

    /** An unpaid card purchase is closed after this long. */
    public const UNPAID_HOURS = 24;

    public const MAX_MESSAGE_LENGTH = 500;

    public function __construct(private readonly Settings $settings) {}

    public function minAmountMinor(): int
    {
        return max(1, $this->settings->int(SettingKey::VoucherMinAmountPkr) ?? 1000) * Money::MINOR_PER_MAJOR;
    }

    public function maxAmountMinor(): int
    {
        return max($this->minAmountMinor(), ($this->settings->int(SettingKey::VoucherMaxAmountPkr) ?? 500_000) * Money::MINOR_PER_MAJOR);
    }

    public function validityMonths(): int
    {
        return max(1, $this->settings->int(SettingKey::VoucherValidityMonths) ?? 12);
    }

    /** Expiry of a voucher issued at $issuedAt: the same time N months later. */
    public function expiresAt(CarbonImmutable $issuedAt): CarbonImmutable
    {
        return $issuedAt->addMonthsNoOverflow($this->validityMonths());
    }

    /** When a voucher scheduled for a Pakistan date goes out (09:00 that day, as UTC). */
    public static function deliveryTime(?CarbonImmutable $sendOn): ?CarbonImmutable
    {
        if ($sendOn === null) {
            return null;
        }

        $tz = (string) config('app.display_timezone');

        return CarbonImmutable::parse($sendOn->format('Y-m-d').' '.sprintf('%02d:00', self::SEND_HOUR), $tz)->utc();
    }

    /** Today's date in Pakistan. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.display_timezone'))->startOfDay();
    }
}
