<?php

declare(strict_types=1);

use App\Jobs\RegenerateSitemap;
use App\Support\LegalPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publishes the approved legal texts (docs/legal, copied to
 * database/data/legal) as the CMS pages /privacy, /terms and
 * /refund-policy, and fills the policies.refund setting with the refund
 * policy (decided 2026-11-11).
 *
 * Like the other content migrations, it never overwrites an admin's work:
 * a page is replaced only while it is still the empty outline PageSeeder
 * created (unpublished, every text block empty); otherwise it is left as
 * it is and a warning is logged. A seeded site that lacks a legal page
 * gets it. The setting is filled only while empty, on a seeded site too
 * (a fresh install gets both from PageSeeder). Publishing shows the
 * pages in the footer and the sitemap (both list published pages); the
 * deploy clears the page cache, and the sitemap is rebuilt at the end.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $seeded = DB::table('pages')->exists();
        $published = false;

        foreach (LegalPages::SLUGS as $slug) {
            $content = LegalPages::content($slug);
            $row = DB::table('pages')->where('slug', $slug)->first(['id', 'body', 'is_published']);

            $values = [
                'title' => $content['title'],
                'answer' => $content['answer'],
                'meta_description' => $content['meta_description'],
                'body' => json_encode($content['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'is_published' => true,
                'is_indexable' => true,
                'updated_at' => $now,
            ];

            if ($row === null) {
                if ($seeded) {
                    DB::table('pages')->insert([...$values, 'slug' => $slug, 'created_at' => $now]);
                    $published = true;
                }

                continue;
            }

            if (! self::isOutline($row)) {
                Log::warning('Legal page /'.$slug.' already has content; the approved text was not applied. Update it in the admin Pages screen.');

                continue;
            }

            DB::table('pages')->where('id', $row->id)->update($values);
            $published = true;
        }

        if ($seeded) {
            LegalPages::fillRefundSetting();
        }

        // Query-builder writes skip the page observer, so rebuild the sitemap here.
        if ($published) {
            RegenerateSitemap::dispatch();
        }
    }

    public function down(): void
    {
        // Content stays: unpublishing the legal pages would break Stripe's
        // requirement for a public refund policy. Unpublish in the admin.
    }

    /** The empty outline from PageSeeder: unpublished and no text written. */
    private static function isOutline(object $row): bool
    {
        $row = (array) $row;

        if ((bool) ($row['is_published'] ?? false)) {
            return false;
        }

        $blocks = json_decode((string) ($row['body'] ?? ''), true);

        if (! is_array($blocks)) {
            return true;
        }

        foreach ($blocks as $block) {
            if (trim((string) ($block['data']['content'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
};
