<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\SettingKey;
use App\Models\Post;
use App\Models\Product;
use App\Models\RideEvent;
use App\Support\BusinessFacts;
use App\Support\Money;
use App\Support\Settings;

/**
 * /llms.txt (llmstxt.org format): a plain-language summary of the business
 * for AI answer engines, generated from settings, products and schedule
 * rules so it never drifts from the site. Private settings never appear.
 */
final class LlmsTxt
{
    public function __construct(
        private readonly Settings $settings,
        private readonly BusinessFacts $facts,
    ) {}

    public function render(): string
    {
        $name = $this->settings->string(SettingKey::BusinessName);
        $location = $this->facts->location();
        $trainer = $this->facts->trainer();

        $out = [];
        $out[] = '# '.$name;
        $out[] = '> '.$name.' runs horse riding sessions in '.$location.', Pakistan, '
            .$this->facts->scheduleText().', with trainer '.$trainer.'. No experience needed. '
            .'Each '.$this->facts->sessionLengthAdjective().' session covers bringing the horse in from the paddock, grooming, tacking up, '
            .'a guided ride (mostly trail, arena work on request), untacking, massage and stretch.';

        $out[] = '## Key facts';
        $out[] = implode("\n", [
            '- Location: '.$this->facts->venueWithLocation().'.'.($this->facts->mapUrl() !== null ? ' Map and directions: '.$this->facts->mapUrl() : ''),
            '- Schedule: '.ucfirst($this->facts->scheduleText()).' (Asia/Karachi time). Times shift slightly with the seasons.',
            '- Trainer: '.$trainer,
            '- Experience: none needed; complete beginners welcome.',
            '- Payment: PKR, by card online or by bank transfer (verified before the booking is confirmed).',
            '- Part of '.$this->settings->string(SettingKey::ParentCompany).'.',
        ]);

        $out[] = '## Sessions and prices (PKR)';
        $out[] = $this->facts->products()->map(fn (Product $product): string => '- ['.$product->name.']('.route('sessions.show', $product).'): '
            .$this->price($product).'. '.$product->short_description)->implode("\n");

        // Events (decided 2026-11-22): dates, price and deposit, from the event rows.
        $events = RideEvent::query()->listed()->ordered()->with('tiers')->get();

        if ($events->isNotEmpty()) {
            $out[] = '## Events (PKR)';
            $out[] = $events->map(function (RideEvent $event): string {
                $dates = $event->upcomingOccurrences()->map(fn ($d) => $d->dateRangeLabel())->implode('; ');

                return '- ['.$event->title.']('.route('events.show', $event).'): from '.Money::format($event->fromPriceMinor()).' per person'
                    .($event->takesDeposit() ? ' ('.$event->deposit_percent.'% non-refundable deposit to book)' : '')
                    .'. '.($dates !== '' ? 'Dates (Asia/Karachi): '.$dates.'. ' : '').$event->summary;
            })->implode("\n");
        }

        $policies = $this->settings->policies(SettingKey::BookingPolicies);

        if ($policies !== []) {
            $out[] = '## Booking policies';
            $out[] = implode("\n", array_map(fn (string $item): string => '- '.$item, $policies));
        }

        $out[] = '## Book and contact';
        $out[] = implode("\n", [
            '- [Book a session]('.route('book').')',
            '- [Weekly schedule]('.route('schedule').')',
            '- [Prices and policies]('.route('prices').')',
            '- [Contact form]('.route('contact').')',
            '- Email: '.$this->settings->string(SettingKey::Email),
            '- Instagram: '.$this->settings->string(SettingKey::InstagramUrl),
        ]);

        $out[] = '## More';
        $more = [
            '- [Trainer '.$trainer.']('.route('trainer').')',
            '- [FAQ]('.route('faq').')',
            '- [Riding expeditions in northern Pakistan]('.$this->settings->string(SettingKey::ExpeditionsUrl).')',
        ];

        foreach (Post::query()->published()->latest('published_at')->limit(20)->get() as $post) {
            $more[] = '- ['.$post->title.']('.route('guides.show', $post).')';
        }

        $out[] = implode("\n", $more);

        return implode("\n\n", $out)."\n";
    }

    private function price(Product $product): string
    {
        if (! $product->isPackage()) {
            return Money::format($product->price_minor).' per rider, one '.$this->facts->sessionLengthAdjective().' session';
        }

        return Money::format($product->price_minor).' for '.$product->sessions_count.' sessions ('
            .Money::format($product->perSessionMinor()).' per session), valid '
            .$product->validityWeeks().' weeks from confirmation';
    }
}
