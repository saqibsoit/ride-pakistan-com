<?php

declare(strict_types=1);

use App\Support\PageCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sessions run every day (decided 2026-11-16), so shipped copy that says
 * "weekend" is rewritten: the private session's short description, the
 * expeditions page's description and answer, and one sentence of the
 * Terms (with its "Last updated" date). The same change is in the seeders,
 * docs/legal and database/data/legal for fresh installs.
 *
 * Only text that still holds the shipped wording changes; anything an
 * admin rewrote is left alone (a Terms page that no longer matches logs a
 * warning).
 */
return new class extends Migration
{
    private const PRIVATE_OLD = 'Just you, or you and your people: your own trainer and pace on a weekend slot. No other riders join.';

    private const PRIVATE_NEW = 'Just you, or you and your people: your own trainer and pace on a slot of your choice. No other riders join.';

    private const EXPEDITIONS_META_OLD = 'After weekend lessons in Islamabad, ride the mountains: Ride Pakistan expeditions through the northern valleys.';

    private const EXPEDITIONS_META_NEW = 'After lessons in Islamabad, ride the mountains: Ride Pakistan expeditions through the northern valleys.';

    private const EXPEDITIONS_ANSWER_OLD = 'Our weekend sessions in Islamabad are the first step.';

    private const EXPEDITIONS_ANSWER_NEW = 'Our sessions in Islamabad are the first step.';

    private const TERMS_OLD = 'We offer weekend horse riding and horsemanship sessions in Islamabad';

    private const TERMS_NEW = 'We offer horse riding and horsemanship sessions in Islamabad';

    private const DATE_OLD = 'Last updated: 12 November 2026';

    private const DATE_NEW = 'Last updated: 16 November 2026';

    public function up(): void
    {
        $this->swap(self::PRIVATE_OLD, self::PRIVATE_NEW, self::EXPEDITIONS_META_OLD, self::EXPEDITIONS_META_NEW, self::EXPEDITIONS_ANSWER_OLD, self::EXPEDITIONS_ANSWER_NEW);
        $this->terms([self::DATE_OLD => self::DATE_NEW, self::TERMS_OLD => self::TERMS_NEW]);
        app(PageCache::class)->invalidate();
    }

    public function down(): void
    {
        $this->swap(self::PRIVATE_NEW, self::PRIVATE_OLD, self::EXPEDITIONS_META_NEW, self::EXPEDITIONS_META_OLD, self::EXPEDITIONS_ANSWER_NEW, self::EXPEDITIONS_ANSWER_OLD);
        $this->terms([self::DATE_NEW => self::DATE_OLD, self::TERMS_NEW => self::TERMS_OLD]);
        app(PageCache::class)->invalidate();
    }

    private function swap(string $privateFrom, string $privateTo, string $metaFrom, string $metaTo, string $answerFrom, string $answerTo): void
    {
        DB::table('products')->where('slug', 'private-session')->where('short_description', $privateFrom)
            ->update(['short_description' => $privateTo, 'updated_at' => now()]);

        DB::table('pages')->where('slug', 'expeditions')->where('meta_description', $metaFrom)
            ->update(['meta_description' => $metaTo, 'updated_at' => now()]);

        $page = DB::table('pages')->where('slug', 'expeditions')->first(['id', 'answer']);

        if ($page !== null && is_string($page->answer) && substr_count($page->answer, $answerFrom) === 1) {
            DB::table('pages')->where('id', $page->id)->update(['answer' => str_replace($answerFrom, $answerTo, $page->answer), 'updated_at' => now()]);
        }
    }

    /** @param  array<string, string>  $replacements  old => new, each old found exactly once in the page */
    private function terms(array $replacements): void
    {
        $row = DB::table('pages')->where('slug', 'terms')->first(['id', 'body']);

        if ($row === null || ! is_string($row->body)) {
            return; // not seeded yet: PageSeeder brings the current text
        }

        $body = json_decode($row->body, true);

        if (! is_array($body)) {
            return;
        }

        $all = implode("\n", array_map(fn (mixed $block): string => is_array($block) ? (string) ($block['data']['content'] ?? '') : '', $body));

        // Already the new wording (published from the updated files): nothing to do.
        if (array_filter($replacements, fn (string $new): bool => ! str_contains($all, $new)) === []) {
            return;
        }

        foreach (array_keys($replacements) as $old) {
            if (substr_count($all, $old) !== 1) {
                Log::warning('The Terms page was edited since it was published; the daily sessions wording was not applied. Update it in the admin Pages screen.');

                return;
            }
        }

        foreach ($body as $i => $block) {
            if (is_array($block) && isset($block['data']['content']) && is_string($block['data']['content'])) {
                $body[$i]['data']['content'] = strtr($block['data']['content'], $replacements);
            }
        }

        DB::table('pages')->where('id', $row->id)->update([
            'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
