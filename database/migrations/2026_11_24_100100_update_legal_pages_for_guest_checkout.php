<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Guest checkout (decided 2026-11-12): the Terms and Privacy Policy now
 * say that you can book without signing in, that the account for your
 * email is used (or created), and what the "Manage your booking" links
 * are. The same change is in docs/legal and database/data/legal (fresh
 * installs get it from PageSeeder).
 *
 * Only the published pages that still hold the 11 November wording are
 * changed, and only the sentences below: each old sentence must be found
 * exactly once, otherwise the page is left as it is (an admin may have
 * edited it) and a warning is logged.
 */
return new class extends Migration
{
    private const TERMS_OLD = 'You sign in with a one-time code sent to your email. Keep access to your email secure. You are responsible for bookings made from your account. Please give accurate details, including a WhatsApp number we can reach you on.';

    private const TERMS_NEW = 'You can book as a guest or sign in with a one-time code sent to your email. When you book as a guest, we use the account that already belongs to your email address, or create one. Each booking email has a private link to manage that booking: anyone with the link can see the booking, so please keep it to yourself. The link stops working 7 days after your last session, and you can stop it at any time. Keep access to your email secure. You are responsible for bookings made with your email address. Please give accurate details, including a WhatsApp number we can reach you on.';

    private const PRIVACY_OLD_1 = '- **Account and contact details:** your name, email address and WhatsApp number.';

    private const PRIVACY_NEW_1 = '- **Account and contact details:** your name, email address and WhatsApp number. If you book without signing in, we use the account that already belongs to your email address, or create one, so your bookings, tickets and receipts stay together.';

    private const PRIVACY_OLD_2 = '- To send booking confirmations, reminders, schedule changes and other service messages by email and WhatsApp.';

    private const PRIVACY_NEW_2 = self::PRIVACY_OLD_2."\n".'- To email you a private link for each booking, so you can see your tickets, pay and make changes without signing in. Anyone with the link can see that booking. It stops working 7 days after your last session, and you can stop it at any time from the link or your account.';

    private const DATE_OLD = 'Last updated: 10 November 2026';

    private const DATE_NEW = 'Last updated: 12 November 2026';

    public function up(): void
    {
        $this->apply('terms', [self::DATE_OLD => self::DATE_NEW, self::TERMS_OLD => self::TERMS_NEW]);
        $this->apply('privacy', [self::DATE_OLD => self::DATE_NEW, self::PRIVACY_OLD_1 => self::PRIVACY_NEW_1, self::PRIVACY_OLD_2 => self::PRIVACY_NEW_2]);
    }

    public function down(): void
    {
        $this->apply('terms', [self::DATE_NEW => self::DATE_OLD, self::TERMS_NEW => self::TERMS_OLD]);
        $this->apply('privacy', [self::DATE_NEW => self::DATE_OLD, self::PRIVACY_NEW_2 => self::PRIVACY_OLD_2, self::PRIVACY_NEW_1 => self::PRIVACY_OLD_1]);
    }

    /** @param  array<string, string>  $replacements  old => new, each old found exactly once in the page */
    private function apply(string $slug, array $replacements): void
    {
        $row = DB::table('pages')->where('slug', $slug)->first(['id', 'body']);

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
                Log::warning('Legal page /'.$slug.' was edited since it was published; the guest checkout wording was not applied. Update it in the admin Pages screen.');

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
