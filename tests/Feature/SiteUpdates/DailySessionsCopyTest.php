<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
| Sessions run every day (decided 2026-11-16): the migration rewrites the
| shipped "weekend" copy in the database and leaves edited text alone.
*/

beforeEach(function (): void {
    $this->seed();
});

function dailyCopyMigration(): object
{
    return require database_path('migrations/2026_11_26_100000_update_copy_for_daily_sessions.php');
}

/** The Terms page body as one string. */
function dailyTermsText(): string
{
    $body = Page::query()->where('slug', 'terms')->firstOrFail()->body;

    return implode("\n", array_map(fn (array $block): string => (string) ($block['data']['content'] ?? ''), (array) $body));
}

it('seeds the daily wording on a fresh install', function (): void {
    expect(Product::query()->where('slug', 'private-session')->value('short_description'))->not->toContain('weekend')
        ->and(Page::query()->where('slug', 'expeditions')->value('answer'))->not->toContain('weekend')
        ->and(dailyTermsText())->toContain('We offer horse riding and horsemanship sessions in Islamabad')
        ->and(dailyTermsText())->toContain('Last updated: 16 November 2026');
});

it('rewrites the shipped weekend copy, and down() puts it back', function (): void {
    dailyCopyMigration()->down();

    expect(Product::query()->where('slug', 'private-session')->value('short_description'))->toContain('on a weekend slot')
        ->and(Page::query()->where('slug', 'expeditions')->value('meta_description'))->toStartWith('After weekend lessons')
        ->and(Page::query()->where('slug', 'expeditions')->value('answer'))->toContain('Our weekend sessions in Islamabad')
        ->and(dailyTermsText())->toContain('We offer weekend horse riding')
        ->and(dailyTermsText())->toContain('Last updated: 12 November 2026');

    dailyCopyMigration()->up();

    expect(Product::query()->where('slug', 'private-session')->value('short_description'))->toContain('on a slot of your choice')
        ->and(Page::query()->where('slug', 'expeditions')->value('meta_description'))->toStartWith('After lessons in Islamabad')
        ->and(Page::query()->where('slug', 'expeditions')->value('answer'))->toContain('Our sessions in Islamabad are the first step.')
        ->and(dailyTermsText())->toContain('We offer horse riding and horsemanship sessions in Islamabad')
        ->and(dailyTermsText())->toContain('Last updated: 16 November 2026');
});

it('leaves copy an admin rewrote alone', function (): void {
    dailyCopyMigration()->down();
    DB::table('products')->where('slug', 'private-session')->update(['short_description' => 'Our own words about weekends.']);
    DB::table('pages')->where('slug', 'expeditions')->update(['meta_description' => 'Custom weekend text.']);

    dailyCopyMigration()->up();

    expect(Product::query()->where('slug', 'private-session')->value('short_description'))->toBe('Our own words about weekends.')
        ->and(Page::query()->where('slug', 'expeditions')->value('meta_description'))->toBe('Custom weekend text.');
});
