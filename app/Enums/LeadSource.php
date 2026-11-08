<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a lead came from. Web sources are set by the server per form,
 * never taken from the request; the rest are chosen on manual entry.
 */
enum LeadSource: string implements HasLabel
{
    case ContactForm = 'contact_form';
    case PricesForm = 'prices_form';
    case InstagramDm = 'instagram_dm';
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ContactForm => 'Contact form',
            self::PricesForm => 'Prices page question',
            self::InstagramDm => 'Instagram DM',
            self::WhatsApp => 'WhatsApp',
            self::Phone => 'Phone call',
            self::WalkIn => 'Walk-in',
            self::Referral => 'Referral',
            self::Other => 'Other',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** Captured by a form on the site (not typed in by the team). */
    public function isWeb(): bool
    {
        return in_array($this, [self::ContactForm, self::PricesForm], true);
    }

    /**
     * Sources the team can pick when adding a lead by hand.
     *
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if (! $case->isWeb()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
