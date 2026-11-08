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
    case GoogleReviewUrl = 'contact.google_review_url';
    case ExpeditionsUrl = 'contact.expeditions_url';

    case RidersPerSlot = 'booking.riders_per_slot';
    case TransferHoldHours = 'booking.transfer_hold_hours';
    case TransferMinNoticeHours = 'booking.transfer_min_notice_hours';
    case CancellationCutoffHours = 'booking.cancellation_cutoff_hours';
    case SellingFastThreshold = 'booking.selling_fast_threshold';
    case MinRiderAge = 'booking.min_rider_age';
    case CheckinOpensMinutes = 'booking.checkin_opens_minutes';
    case ArrivalMinutes = 'booking.arrival_minutes';
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
            self::InstagramUrl, self::InstagramDmUrl, self::GoogleReviewUrl, self::ExpeditionsUrl, self::MapPinUrl => SettingType::Url,
            self::RidersPerSlot, self::TransferHoldHours, self::TransferMinNoticeHours, self::CancellationCutoffHours,
            self::SellingFastThreshold, self::MinRiderAge, self::CheckinOpensMinutes, self::ArrivalMinutes, self::SlotHorizonWeeks,
            self::CardHoldMinutes, self::MinNoticeHours,
            self::DeletionGraceDays, self::OrderRetentionYears, self::TransferProofRetentionDays => SettingType::Integer,
            self::RidingRules, self::BookingPolicies, self::AiCrawlers => SettingType::List,
            self::RefundPolicy, self::DefaultDescription, self::BankAddress => SettingType::LongText,
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
            self::GoogleReviewUrl => 'Google review link (sent in the thank-you email; hidden while empty)',
            self::ExpeditionsUrl => 'Expeditions site URL',
            self::RidersPerSlot => 'Riders per slot',
            self::TransferHoldHours => 'Bank transfer hold window (hours)',
            self::TransferMinNoticeHours => 'Offer bank transfer only if the session starts at least this many hours later',
            self::CancellationCutoffHours => 'Cancellation and reschedule cutoff (hours before slot)',
            self::SellingFastThreshold => 'Show "Selling fast" at or below (seats)',
            self::MinRiderAge => 'Minimum rider age',
            self::CheckinOpensMinutes => 'Check-in opens this many minutes before a session',
            self::ArrivalMinutes => 'Ask riders to arrive this many minutes early (reminder email; hidden while empty)',
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

    /**
     * One plain sentence for the admin settings page: what it does, where
     * it shows and its unit (milestone 10a). Every key has one.
     */
    public function help(): string
    {
        return match ($this) {
            self::BusinessName => 'Shown in page titles, emails, structured data and llms.txt.',
            self::ParentCompany => 'The company behind the business, shown in the footer, on receipts, in structured data and in llms.txt.',
            self::TrainerName => 'Used wherever the site names the trainer (session pages, FAQs with {trainer}, structured data).',
            self::Location => 'The public location, e.g. "Islamabad". Never put the exact address here; that is the private map pin.',
            self::Tagline => 'Short line shown in the footer, in structured data and as the default social-share image description.',
            self::Email => 'Where customers write to. Shown in the footer and in every email.',
            self::InstagramUrl => 'Link to the Instagram profile in the header, footer and structured data.',
            self::InstagramDmUrl => 'Opens an Instagram message to us ("Send us a DM" buttons).',
            self::GoogleReviewUrl => 'Review link in the thank-you email after a session. Leave empty to leave the button out.',
            self::ExpeditionsUrl => 'Where the "Expeditions" page and chips link to (the separate expeditions site).',
            self::RidersPerSlot => 'Default number of riders for new schedule rules and manual slots. Existing slots keep their own capacity.',
            self::TransferHoldHours => 'How long seats stay held after a customer chooses bank transfer and before they upload proof. Never past the session start.',
            self::TransferMinNoticeHours => 'Bank transfer is only offered when the first session is at least this many hours away, so there is time to verify it.',
            self::CancellationCutoffHours => 'Customers can cancel or reschedule credit bookings until this many hours before the session. Shown in the booking policies as ":cutoff".',
            self::SellingFastThreshold => 'Slots with this many seats left or fewer show a "Selling fast" badge.',
            self::MinRiderAge => 'Minimum age for riders, shown in "Good to know" and the policies as ":min_age". Leave empty until decided; it is then hidden.',
            self::CheckinOpensMinutes => 'Staff can mark riders as attended from this many minutes before the start until the session ends.',
            self::ArrivalMinutes => 'Reminder emails ask riders to arrive this many minutes early. Leave empty to leave it out.',
            self::SlotHorizonWeeks => 'How far ahead slots are created from the schedule rules (and how far the rule preview looks). 1 to 52 weeks.',
            self::CardHoldMinutes => 'How long seats stay held while a customer pays by card. Stripe needs at least 31, so shorter values use 31.',
            self::MinNoticeHours => 'Online booking for a slot closes this many hours before it starts. 0 keeps it open until the start.',
            self::MapPinUrl => 'Exact location link (e.g. Google Maps). Never shown on public pages; sent only in confirmation and reminder emails.',
            self::RidingRules => 'One rule per line (helmet, shoes, ...), shown on the prices page and in reminder emails.',
            self::BookingPolicies => 'One policy per line, shown on the prices and session pages. ":cutoff" becomes the cutoff hours; ":min_age" the minimum age.',
            self::RefundPolicy => 'Short refund summary shown in the booking policies on the prices page. The full text is the Refund policy page.',
            self::BankAccountName => 'Account name customers pay by bank transfer. Shown only on the checkout transfer page.',
            self::BankName => 'Bank name for transfers, shown only on the checkout transfer page.',
            self::BankIban => 'IBAN for transfers, shown only on the checkout transfer page. Check it twice: payments go here.',
            self::BankSwift => 'SWIFT/BIC code for transfers from abroad, shown on the checkout transfer page.',
            self::BankCountry => 'Country of the bank account, shown on the checkout transfer page.',
            self::BankAddress => 'Branch address, shown on the checkout transfer page for international transfers.',
            self::DeletionGraceDays => 'A customer who deletes their account can sign in and keep it for this many days.',
            self::OrderRetentionYears => 'Deleted accounts with paid orders are anonymised only after this many years since the last payment (accounting records).',
            self::TransferProofRetentionDays => 'Transfer screenshots are deleted this many days after an admin approves or rejects them. The payment record is kept.',
            self::DefaultTitle => 'Page title used when a page has none of its own.',
            self::DefaultDescription => 'Search-result description used when a page has none of its own (up to about 160 characters).',
            self::AiCrawlers => 'One crawler name per line (e.g. GPTBot) allowed in robots.txt to read the site for AI answers.',
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
