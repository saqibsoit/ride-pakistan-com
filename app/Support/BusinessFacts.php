<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingKey;
use App\Models\Faq;
use App\Models\Product;
use App\Models\ScheduleRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One source for the entity facts repeated across pages, FAQs, JSON-LD and
 * llms.txt (PLAN §8: consistent facts everywhere). Everything is derived from
 * products, schedule rules and settings, so a price or time change in admin
 * shows up everywhere at once.
 */
final class BusinessFacts
{
    private const DAY_NAMES = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    /** @var Collection<int, Product>|null */
    private ?Collection $products = null;

    /** @var Collection<int, ScheduleRule>|null */
    private ?Collection $rules = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Active products in display order.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        return $this->products ??= Product::query()->active()->ordered()->get();
    }

    /**
     * Active schedule rules that apply today or later, in weekly order
     * (Saturday first, then Sunday, then weekdays).
     *
     * @return Collection<int, ScheduleRule>
     */
    public function rules(): Collection
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $today = CarbonImmutable::now((string) config('app.display_timezone'))->toDateString();

        return $this->rules = ScheduleRule::query()
            ->active()
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->get()
            ->sortBy(fn (ScheduleRule $rule): string => self::weekendFirst($rule->day_of_week).$rule->startTime())
            ->values();
    }

    public function product(string $slug): ?Product
    {
        return $this->products()->firstWhere('slug', $slug);
    }

    public function location(): string
    {
        return $this->settings->string(SettingKey::Location);
    }

    public function trainer(): string
    {
        return $this->settings->string(SettingKey::TrainerName);
    }

    /** Lowest active price in minor units, or null with no products. */
    public function fromPriceMinor(): ?int
    {
        $min = $this->products()->min('price_minor');

        return $min === null ? null : (int) $min;
    }

    /** "Saturday and Sunday" */
    public function daysText(): string
    {
        $days = array_values($this->rules()->pluck('day_of_week')->unique()
            ->map(fn (int $day): string => self::DAY_NAMES[$day])
            ->all());

        return self::join($days);
    }

    /** "Sat & Sun" (fact strip) */
    public function daysShort(): string
    {
        $days = $this->rules()->pluck('day_of_week')->unique()
            ->map(fn (int $day): string => substr(self::DAY_NAMES[$day], 0, 3))
            ->values()
            ->all();

        return implode(' & ', $days);
    }

    /**
     * Distinct time ranges, e.g. ["8–10 am", "3–5 pm"].
     *
     * @return list<string>
     */
    public function timeRanges(): array
    {
        return array_values($this->rules()
            ->map(fn (ScheduleRule $rule): string => self::timeRange($rule->startTime(), $rule->endTime()))
            ->unique()
            ->all());
    }

    /** "every Saturday and Sunday, 8–10 am or 3–5 pm" */
    public function scheduleText(): string
    {
        if ($this->rules()->isEmpty()) {
            return 'on weekends (times to be announced)';
        }

        return 'every '.$this->daysText().', '.self::join($this->timeRanges(), 'or');
    }

    /** "2 hours" from the most common session length. */
    public function sessionLength(): string
    {
        $rule = $this->rules()->first();

        if ($rule === null) {
            return '2 hours';
        }

        $minutes = (int) CarbonImmutable::parse($rule->startTime())->diffInMinutes(CarbonImmutable::parse($rule->endTime()));
        $hours = intdiv($minutes, 60);

        return $minutes % 60 === 0
            ? $hours.' '.($hours === 1 ? 'hour' : 'hours')
            : $minutes.' minutes';
    }

    /** "2-hour" (as in "a 2-hour session"). */
    public function sessionLengthAdjective(): string
    {
        return (string) preg_replace('/^(\d+) (hour|minute)s?$/', '$1-$2', $this->sessionLength());
    }

    /**
     * Fill FAQ/answer tokens: {schedule}, {location}, {trainer}, {cutoff},
     * {price:slug}, {per_session:slug}, {validity:slug}. Unknown tokens stay.
     */
    public function interpolate(string $text): string
    {
        $text = strtr($text, [
            '{schedule}' => $this->scheduleText(),
            '{location}' => $this->location(),
            '{trainer}' => $this->trainer(),
            '{cutoff}' => (string) ($this->settings->int(SettingKey::CancellationCutoffHours) ?? ''),
        ]);

        return (string) preg_replace_callback(
            '/\{(price|per_session|validity):([a-z0-9-]+)\}/',
            function (array $match): string {
                $product = $this->product($match[2]);

                if ($product === null) {
                    return $match[0];
                }

                return match ($match[1]) {
                    'price' => Money::format($product->price_minor),
                    'per_session' => Money::format($product->perSessionMinor()),
                    default => $product->validityWeeks() === null ? '' : $product->validityWeeks().' weeks',
                };
            },
            $text,
        );
    }

    /**
     * FAQs with their answer tokens filled in, for display and JSON-LD.
     * The models are display copies: never save them.
     *
     * @param  Collection<int, Faq>  $faqs
     * @return Collection<int, Faq>
     */
    public function withAnswers(Collection $faqs): Collection
    {
        return $faqs->map(function (Faq $faq): Faq {
            $copy = clone $faq;
            $copy->answer = $this->interpolate($faq->answer);

            return $copy;
        });
    }

    /** "8–10 am", "11 am–1 pm" */
    public static function timeRange(string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        $fmt = static fn (CarbonImmutable $time): string => $time->minute === 0 ? $time->format('g') : $time->format('g:i');

        if ($from->format('a') === $to->format('a')) {
            return $fmt($from).'–'.$fmt($to).' '.$to->format('a');
        }

        return $fmt($from).' '.$from->format('a').'–'.$fmt($to).' '.$to->format('a');
    }

    /**
     * @param  list<string>  $items
     */
    private static function join(array $items, string $last = 'and'): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $final = array_pop($items);

        return implode(', ', $items).' '.$last.' '.$final;
    }

    /** Saturday sorts first, then Sunday, then Monday to Friday. */
    private static function weekendFirst(int $day): string
    {
        return (string) (($day + 1) % 7);
    }
}
