<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every admin-editable setting. Defaults live in config/settings.php;
 * values saved in the settings table override them.
 */
enum SettingKey: string
{
    case BusinessName = 'business.name';
    case ParentCompany = 'business.parent_company';
    case TrainerName = 'business.trainer_name';
    case Location = 'business.location';
    case Tagline = 'business.tagline';

    case Email = 'contact.email';
    case InstagramUrl = 'contact.instagram_url';
    case InstagramDmUrl = 'contact.instagram_dm_url';
    case ExpeditionsUrl = 'contact.expeditions_url';

    case ScheduleSummary = 'schedule.summary';

    case RidersPerSlot = 'booking.riders_per_slot';
    case TransferHoldHours = 'booking.transfer_hold_hours';
    case TransferMinNoticeHours = 'booking.transfer_min_notice_hours';
    case CancellationCutoffHours = 'booking.cancellation_cutoff_hours';
    case SellingFastThreshold = 'booking.selling_fast_threshold';
    case MinRiderAge = 'booking.min_rider_age';
    case SlotHorizonWeeks = 'booking.slot_horizon_weeks';
    case CardHoldMinutes = 'booking.card_hold_minutes';
    case MinNoticeHours = 'booking.min_notice_hours';
    case MapPinUrl = 'booking.map_pin_url';

    case RidingRules = 'policies.riding_rules';
    case BookingPolicies = 'policies.booking';
    case RefundPolicy = 'policies.refund';

    case BankAccountName = 'bank.account_name';
    case BankName = 'bank.name';
    case BankIban = 'bank.iban';
    case BankSwift = 'bank.swift';
    case BankCountry = 'bank.country';
    case BankAddress = 'bank.address';

    case DeletionGraceDays = 'privacy.deletion_grace_days';
    case OrderRetentionYears = 'privacy.order_retention_years';
    case TransferProofRetentionDays = 'privacy.transfer_proof_retention_days';

    case DefaultTitle = 'seo.default_title';
    case DefaultDescription = 'seo.default_description';
    case AiCrawlers = 'seo.ai_crawlers';

    public function type(): SettingType
    {
        return match ($this) {
            self::Email => SettingType::Email,
            self::InstagramUrl, self::InstagramDmUrl, self::ExpeditionsUrl, self::MapPinUrl => SettingType::Url,
            self::RidersPerSlot, self::TransferHoldHours, self::TransferMinNoticeHours, self::CancellationCutoffHours,
            self::SellingFastThreshold, self::MinRiderAge, self::SlotHorizonWeeks,
            self::CardHoldMinutes, self::MinNoticeHours,
            self::DeletionGraceDays, self::OrderRetentionYears, self::TransferProofRetentionDays => SettingType::Integer,
            self::RidingRules, self::BookingPolicies, self::AiCrawlers => SettingType::List,
            self::ScheduleSummary, self::RefundPolicy, self::DefaultDescription, self::BankAddress => SettingType::LongText,
            default => SettingType::Text,
        };
    }

    /** Settings group (the key prefix), used to lay out the admin settings page. */
    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::BusinessName => 'Business name',
            self::ParentCompany => 'Parent company',
            self::TrainerName => 'Trainer name',
            self::Location => 'Public location',
            self::Tagline => 'Tagline',
            self::Email => 'Contact email',
            self::InstagramUrl => 'Instagram profile URL',
            self::InstagramDmUrl => 'Instagram DM link',
            self::ExpeditionsUrl => 'Expeditions site URL',
            self::ScheduleSummary => 'Schedule summary',
            self::RidersPerSlot => 'Riders per slot',
            self::TransferHoldHours => 'Bank transfer hold window (hours)',
            self::TransferMinNoticeHours => 'Offer bank transfer only if the session starts at least this many hours later',
            self::CancellationCutoffHours => 'Cancellation and reschedule cutoff (hours before slot)',
            self::SellingFastThreshold => 'Show "Selling fast" at or below (seats)',
            self::MinRiderAge => 'Minimum rider age',
            self::SlotHorizonWeeks => 'Create bookable slots this many weeks ahead',
            self::CardHoldMinutes => 'Hold seats for card payment (minutes)',
            self::MinNoticeHours => 'Stop booking a slot this many hours before it starts (0 = until it starts)',
            self::MapPinUrl => 'Exact map pin URL (private, sent after confirmation)',
            self::RidingRules => 'Riding rules',
            self::BookingPolicies => 'Booking policies',
            self::RefundPolicy => 'Refund policy summary',
            self::BankAccountName => 'Account name',
            self::BankName => 'Bank',
            self::BankIban => 'IBAN',
            self::BankSwift => 'SWIFT',
            self::BankCountry => 'Country',
            self::BankAddress => 'Bank address',
            self::DeletionGraceDays => 'Days a deleted account can still be restored by its owner',
            self::OrderRetentionYears => 'Keep accounts with paid orders for this many years after the last payment',
            self::TransferProofRetentionDays => 'Delete bank transfer screenshots this many days after review (the payment record is kept)',
            self::DefaultTitle => 'Default page title',
            self::DefaultDescription => 'Default meta description',
            self::AiCrawlers => 'AI crawlers allowed in robots.txt',
        };
    }

    /** Private settings are never rendered on public pages or in llms.txt. */
    public function isPrivate(): bool
    {
        return $this === self::MapPinUrl;
    }

    public function default(): mixed
    {
        // Keys contain dots, so read the array directly (config() would nest them).
        $defaults = config('settings.defaults');

        return is_array($defaults) ? ($defaults[$this->value] ?? null) : null;
    }
}
