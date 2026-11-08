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
    case WaitlistOfferHours = 'booking.waitlist_offer_hours';

    case RidingRules = 'policies.riding_rules';
    case BookingPolicies = 'policies.booking';
    case RefundPolicy = 'policies.refund';

    case BankAccountName = 'bank.account_name';
    case BankName = 'bank.name';
    case BankIban = 'bank.iban';
    case BankSwift = 'bank.swift';
    case BankCountry = 'bank.country';
    case BankAddress = 'bank.address';

    case LeadFollowUpDays = 'leads.follow_up_days';

    case VoucherValidityMonths = 'vouchers.validity_months';
    case VoucherMinAmountPkr = 'vouchers.min_amount_pkr';
    case VoucherMaxAmountPkr = 'vouchers.max_amount_pkr';

    case ReferralFriendDiscountPkr = 'referrals.friend_discount_pkr';
    case ReferralReferrerCreditPkr = 'referrals.referrer_credit_pkr';

    case DeletionGraceDays = 'privacy.deletion_grace_days';
    case OrderRetentionYears = 'privacy.order_retention_years';
    case TransferProofRetentionDays = 'privacy.transfer_proof_retention_days';

    case DefaultTitle = 'seo.default_title';
    case DefaultDescription = 'seo.default_description';
    case AiCrawlers = 'seo.ai_crawlers';

    case WeatherEnabled = 'weather.enabled';
    case WeatherLatitude = 'weather.latitude';
    case WeatherLongitude = 'weather.longitude';
    case WeatherRainProbability = 'weather.rain_probability';
    case WeatherPrecipitationMm = 'weather.precipitation_mm';
    case WeatherWindKmh = 'weather.wind_kmh';
    case WeatherHeatC = 'weather.heat_c';
    case WeatherColdC = 'weather.cold_c';
    case WeatherThunderCodes = 'weather.thunder_codes';
    case WeatherRecipients = 'weather.recipients';
    case WeatherTemplateUpdate = 'weather.template_update';
    case WeatherTemplateMoved = 'weather.template_moved';
    case WeatherTemplateCancelled = 'weather.template_cancelled';

    public function type(): SettingType
    {
        return match ($this) {
            self::Email => SettingType::Email,
            self::InstagramUrl, self::InstagramDmUrl, self::GoogleReviewUrl, self::ExpeditionsUrl, self::MapPinUrl => SettingType::Url,
            self::RidersPerSlot, self::TransferHoldHours, self::TransferMinNoticeHours, self::CancellationCutoffHours,
            self::SellingFastThreshold, self::MinRiderAge, self::CheckinOpensMinutes, self::ArrivalMinutes, self::SlotHorizonWeeks,
            self::CardHoldMinutes, self::MinNoticeHours, self::WaitlistOfferHours,
            self::DeletionGraceDays, self::OrderRetentionYears, self::TransferProofRetentionDays, self::LeadFollowUpDays,
            self::WeatherRainProbability, self::WeatherWindKmh, self::WeatherHeatC, self::WeatherColdC,
            self::VoucherValidityMonths, self::VoucherMinAmountPkr, self::VoucherMaxAmountPkr,
            self::ReferralFriendDiscountPkr, self::ReferralReferrerCreditPkr => SettingType::Integer,
            self::WeatherLatitude, self::WeatherLongitude, self::WeatherPrecipitationMm => SettingType::Decimal,
            self::WeatherEnabled => SettingType::Boolean,
            self::WeatherRecipients => SettingType::Roles,
            self::RidingRules, self::BookingPolicies, self::AiCrawlers, self::WeatherThunderCodes => SettingType::List,
            self::RefundPolicy, self::DefaultDescription, self::BankAddress,
            self::WeatherTemplateUpdate, self::WeatherTemplateMoved, self::WeatherTemplateCancelled => SettingType::LongText,
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
            self::WaitlistOfferHours => 'Waitlist offer: hold freed seats for the next person this many hours',
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
            self::LeadFollowUpDays => 'Suggest a follow-up this many days after a lead is contacted (0 = no suggestion)',
            self::VoucherValidityMonths => 'Gift vouchers are valid for (months)',
            self::VoucherMinAmountPkr => 'Smallest gift voucher amount (PKR)',
            self::VoucherMaxAmountPkr => 'Largest gift voucher amount (PKR)',
            self::ReferralFriendDiscountPkr => 'Discount for a referred friend on their first paid booking (PKR)',
            self::ReferralReferrerCreditPkr => 'Account credit for the referrer (PKR)',
            self::DeletionGraceDays => 'Days a deleted account can still be restored by its owner',
            self::OrderRetentionYears => 'Keep accounts with paid orders for this many years after the last payment',
            self::TransferProofRetentionDays => 'Delete bank transfer screenshots this many days after review (the payment record is kept)',
            self::DefaultTitle => 'Default page title',
            self::DefaultDescription => 'Default meta description',
            self::AiCrawlers => 'AI crawlers allowed in robots.txt',
            self::WeatherEnabled => 'Check the weather forecast for upcoming sessions',
            self::WeatherLatitude => 'Venue latitude (private)',
            self::WeatherLongitude => 'Venue longitude (private)',
            self::WeatherRainProbability => 'Alert when the chance of rain reaches (%)',
            self::WeatherPrecipitationMm => 'Alert when rain in an hour reaches (mm)',
            self::WeatherWindKmh => 'Alert when wind gusts reach (km/h)',
            self::WeatherHeatC => 'Alert when the temperature reaches (°C)',
            self::WeatherColdC => 'Alert when the temperature drops to (°C)',
            self::WeatherThunderCodes => 'Thunderstorm weather codes',
            self::WeatherRecipients => 'Who gets weather alerts',
            self::WeatherTemplateUpdate => 'Customer notice template: weather update',
            self::WeatherTemplateMoved => 'Customer notice template: session moved',
            self::WeatherTemplateCancelled => 'Customer notice template: session cancelled',
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
            self::WaitlistOfferHours => 'When seats free up in a full slot, the first person on its waitlist who fits gets an email and the seats are held for them this long (never past the booking cutoff). If they do not book in time, the offer moves to the next person.',
            self::RidingRules => 'One rule per line (helmet, shoes, ...), shown on the prices page and in reminder emails.',
            self::BookingPolicies => 'One policy per line, shown on the prices and session pages. ":cutoff" becomes the cutoff hours; ":min_age" the minimum age.',
            self::RefundPolicy => 'Short refund summary shown in the booking policies on the prices page. The full text is the Refund policy page.',
            self::BankAccountName => 'Account name customers pay by bank transfer. Shown only on the checkout transfer page.',
            self::BankName => 'Bank name for transfers, shown only on the checkout transfer page.',
            self::BankIban => 'IBAN for transfers, shown only on the checkout transfer page. Check it twice: payments go here.',
            self::BankSwift => 'SWIFT/BIC code for transfers from abroad, shown on the checkout transfer page.',
            self::BankCountry => 'Country of the bank account, shown on the checkout transfer page.',
            self::BankAddress => 'Branch address, shown on the checkout transfer page for international transfers.',
            self::LeadFollowUpDays => 'When a lead is moved to "Contacted", its next follow-up date is suggested this many days later (you can change it). Leads due appear on the dashboard and in the 09:00 email digest.',
            self::VoucherValidityMonths => 'A gift voucher can be used for this many months after it is issued (payment confirmed). Vouchers already issued keep their own expiry date.',
            self::VoucherMinAmountPkr => 'Customers can buy a gift voucher for any amount from this many rupees (or for the price of a product).',
            self::VoucherMaxAmountPkr => 'The most a customer can put on one gift voucher, in rupees.',
            self::ReferralFriendDiscountPkr => 'A new customer who signs up through a referral link gets this many rupees off their first paid booking. 0 turns the discount off.',
            self::ReferralReferrerCreditPkr => 'The customer who shared the link earns this much account credit once the friend has paid for and attended a session. 0 turns the reward off.',
            self::DeletionGraceDays => 'A customer who deletes their account can sign in and keep it for this many days.',
            self::OrderRetentionYears => 'Deleted accounts with paid orders are anonymised only after this many years since the last payment (accounting records).',
            self::TransferProofRetentionDays => 'Transfer screenshots are deleted this many days after an admin approves or rejects them. The payment record is kept.',
            self::DefaultTitle => 'Page title used when a page has none of its own.',
            self::DefaultDescription => 'Search-result description used when a page has none of its own (up to about 160 characters).',
            self::AiCrawlers => 'One crawler name per line (e.g. GPTBot) allowed in robots.txt to read the site for AI answers.',
            self::WeatherEnabled => 'Fetches the Open-Meteo forecast at 06:00 and 18:00 Pakistan time and emails the team when a session in the next 48 hours crosses a threshold. Needs the venue coordinates.',
            self::WeatherLatitude => 'Venue latitude in decimal degrees, e.g. 33.6844. Used only to fetch the forecast; never shown on the site or in emails.',
            self::WeatherLongitude => 'Venue longitude in decimal degrees, e.g. 73.0479. Used only to fetch the forecast; never shown on the site or in emails.',
            self::WeatherRainProbability => 'A session hour with this chance of rain or more counts as crossed (0 to 100).',
            self::WeatherPrecipitationMm => 'A session hour with this much rain or more counts as crossed, e.g. 2 or 0.5.',
            self::WeatherWindKmh => 'A session hour with gusts this strong or stronger counts as crossed.',
            self::WeatherHeatC => 'A session hour this hot or hotter counts as crossed.',
            self::WeatherColdC => 'A session hour this cold or colder counts as crossed. Can be below zero.',
            self::WeatherThunderCodes => 'One WMO weather code per line that means a thunderstorm (Open-Meteo uses 95, 96 and 99). A thunderstorm always makes it a warning.',
            self::WeatherRecipients => 'Admins and staff get every alert; a trainer only for the sessions they run.',
            self::WeatherTemplateUpdate => 'Starting text of a "weather update" email to booked customers, sent from a slot page. ":session" becomes the session date and time. You can edit it before sending.',
            self::WeatherTemplateMoved => 'Starting text of a "session moved" email. It only sends the message; move the bookings themselves from the booking pages.',
            self::WeatherTemplateCancelled => 'Starting reason when cancelling a session for weather. The slot is cancelled as usual (credits back, paid bookings flagged) and the cancellation email shows this text. Up to 500 characters.',
        };
    }

    /** Private settings are never rendered on public pages or in llms.txt. */
    public function isPrivate(): bool
    {
        return in_array($this, [self::MapPinUrl, self::WeatherLatitude, self::WeatherLongitude], true);
    }

    public function default(): mixed
    {
        // Keys contain dots, so read the array directly (config() would nest them).
        $defaults = config('settings.defaults');

        return is_array($defaults) ? ($defaults[$this->value] ?? null) : null;
    }
}
