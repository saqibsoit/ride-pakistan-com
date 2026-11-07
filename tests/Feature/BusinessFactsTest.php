<?php

declare(strict_types=1);

use App\Models\ScheduleRule;
use App\Support\BusinessFacts;
use Carbon\CarbonInterface;

beforeEach(function (): void {
    $this->seed();
});

it('describes the schedule from rules', function (): void {
    $facts = app(BusinessFacts::class);

    expect($facts->daysText())->toBe('Saturday and Sunday')
        ->and($facts->daysShort())->toBe('Sat & Sun')
        ->and($facts->timeRanges())->toBe(['8–10 am', '3–5 pm'])
        ->and($facts->scheduleText())->toBe('every Saturday and Sunday, 8–10 am or 3–5 pm')
        ->and($facts->sessionLength())->toBe('2 hours')
        ->and($facts->sessionLengthAdjective())->toBe('2-hour')
        ->and($facts->fromPriceMinor())->toBe(1_000_000);
});

it('ignores inactive and expired rules', function (): void {
    ScheduleRule::query()->where('day_of_week', CarbonInterface::SUNDAY)->update(['is_active' => false]);
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::FRIDAY, 'valid_to' => now()->subDay()]);

    expect(app(BusinessFacts::class)->daysText())->toBe('Saturday');
});

it('formats time ranges across noon', function (string $start, string $end, string $expected): void {
    expect(BusinessFacts::timeRange($start, $end))->toBe($expected);
})->with([
    ['08:00', '10:00', '8–10 am'],
    ['15:00', '17:00', '3–5 pm'],
    ['11:00', '13:00', '11 am–1 pm'],
    ['07:30', '09:30', '7:30–9:30 am'],
]);

it('fills answer tokens from live data and leaves unknown ones alone', function (): void {
    $text = app(BusinessFacts::class)->interpolate(
        '{price:single-session} | {per_session:16-class-package} | {validity:8-class-package} | {schedule} | {location} | {trainer} | {cutoff} | {price:nope}',
    );

    expect($text)->toBe('PKR 10,000 | PKR 6,250 | 6 weeks | every Saturday and Sunday, 8–10 am or 3–5 pm | Islamabad | Omar Saleem | 48 | {price:nope}');
});
