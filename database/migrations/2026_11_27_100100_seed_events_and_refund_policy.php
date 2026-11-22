<?php

declare(strict_types=1);

use App\Actions\Events\SeedCowboyCamp;
use App\Enums\SettingKey;
use App\Models\RideEventCategory;
use App\Support\LegalPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Events (decided 2026-11-22), seeded once:
 *
 * - the event categories (camp, workshop, trip, ride), each once by slug;
 * - the first event, Cowboy Camp, published, once by slug: Sat 19 and Sun
 *   20 Dec 2026, 10:00–17:00 Pakistan time, 10 places each, PKR 10,000
 *   per person with a 50 % non-refundable deposit and the balance on the
 *   day; both dates close the day's lesson slots (bookings already made
 *   are kept and listed for an admin);
 * - the "Events" section of the refund policy, on the published page and
 *   in policies.refund, only where the 10 November wording is unchanged.
 *
 * Admin edits stick: nothing here runs again for a slug that exists.
 */
return new class extends Migration
{
    private const CATEGORIES = ['camp' => 'Camps', 'workshop' => 'Workshops', 'trip' => 'Trips', 'ride' => 'Rides'];

    public function up(): void
    {
        foreach (array_keys(self::CATEGORIES) as $i => $slug) {
            if (! RideEventCategory::query()->where('slug', $slug)->exists()) {
                RideEventCategory::query()->create(['slug' => $slug, 'name' => self::CATEGORIES[$slug], 'sort' => $i, 'is_active' => true]);
            }
        }

        // Tests build their own events; the seeded camp would sit in every test.
        if (! app()->runningUnitTests()) {
            app(SeedCowboyCamp::class)->handle();
        }

        $this->updateRefundPolicy();
    }

    public function down(): void
    {
        // Seeded content is left in place: it may have bookings by then.
    }

    private function updateRefundPolicy(): void
    {
        $old = '## 8. Contact';
        $events = LegalPages::content('refund-policy')['body'];
        $section = collect($events)->first(fn (array $block): bool => str_starts_with((string) ($block['data']['heading'] ?? ''), '8. Events'));

        if ($section === null) {
            return;
        }

        // The published page: insert the Events block before Contact.
        $row = DB::table('pages')->where('slug', 'refund-policy')->first(['id', 'body']);
        $body = $row === null || ! is_string($row->body) ? null : json_decode($row->body, true);

        if (is_array($body)) {
            $headings = array_map(fn (mixed $b): string => is_array($b) ? (string) ($b['data']['heading'] ?? '') : '', $body);
            $contact = array_keys($headings, '8. Contact', true);

            if (in_array('8. Events (camps, workshops and trips)', $headings, true)) {
                // Already there.
            } elseif (count($contact) === 1) {
                $i = $contact[0];
                $body[$i]['data']['heading'] = '9. Contact';
                array_splice($body, $i, 0, [$section]);

                if (isset($body[0]['data']['content']) && is_string($body[0]['data']['content'])) {
                    $body[0]['data']['content'] = str_replace('Last updated: 10 November 2026', 'Last updated: 22 November 2026', $body[0]['data']['content']);
                }

                DB::table('pages')->where('id', $row->id)->update(['body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            } else {
                Log::warning('The refund policy page was edited since it was published; the Events section was not added. Add it in the admin Pages screen.');
            }
        }

        // The setting (Markdown), shown on /prices.
        $setting = DB::table('settings')->where('key', SettingKey::RefundPolicy->value)->first(['id', 'value']);
        $text = $setting === null ? null : json_decode((string) $setting->value, true);

        if (is_string($text) && substr_count($text, $old) === 1 && ! str_contains($text, '8. Events')) {
            $markdown = LegalPages::refundPolicySetting();
            $sectionMarkdown = substr($markdown, (int) strpos($markdown, '## 8. Events'), (int) strpos($markdown, '## 9. Contact') - (int) strpos($markdown, '## 8. Events'));
            $text = str_replace($old, $sectionMarkdown.'## 9. Contact', $text);
            $text = str_replace('Last updated: 10 November 2026', 'Last updated: 22 November 2026', $text);

            DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($text, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }
};
