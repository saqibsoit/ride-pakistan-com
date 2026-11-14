<?php

declare(strict_types=1);

use App\Support\PageCache;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The venue is public (decided 2026-11-14): Yashwala Ranch, with a map and
 * directions on the site. Copy that still says the pin is private and sent
 * only after booking is replaced, but only where it holds the shipped
 * wording; anything an admin rewrote is left alone. An empty saved map link
 * is removed so the default from config/settings.php applies.
 */
return new class extends Migration
{
    private const FAQ_OLD = 'We’re in {location}. We share the exact location pin when we confirm your booking.';

    private const FAQ_NEW = 'We ride at {venue}, {location}. Directions are on our contact page and in your confirmation email.';

    private const POLICY_OLD = 'We send the exact location pin when your booking is confirmed.';

    private const POLICY_NEW = 'Your confirmation email has your tickets and directions to the ranch.';

    private const REPLY_OLD = "Hi {lead_name}, we're in {location}. We send the exact location pin with your booking confirmation. You can book here: {booking_link}";

    private const REPLY_NEW = 'Hi {lead_name}, we ride at {venue}, {location}. Directions: {map_link} You can book here: {booking_link}';

    public function up(): void
    {
        $this->swap(self::FAQ_OLD, self::FAQ_NEW, self::POLICY_OLD, self::POLICY_NEW, self::REPLY_OLD, self::REPLY_NEW);

        DB::table('settings')->where('key', 'booking.map_pin_url')
            ->where(fn ($q) => $q->whereNull('value')->orWhere('value', '""'))
            ->delete();

        $this->flushCaches();
    }

    public function down(): void
    {
        $this->swap(self::FAQ_NEW, self::FAQ_OLD, self::POLICY_NEW, self::POLICY_OLD, self::REPLY_NEW, self::REPLY_OLD);
        $this->flushCaches();
    }

    private function swap(string $faqFrom, string $faqTo, string $policyFrom, string $policyTo, string $replyFrom, string $replyTo): void
    {
        DB::table('faqs')->where('answer', $faqFrom)->update(['answer' => $faqTo, 'updated_at' => now()]);

        DB::table('quick_replies')->where('body', $replyFrom)->update(['body' => $replyTo, 'updated_at' => now()]);

        $row = DB::table('settings')->where('key', 'policies.booking')->first(['id', 'value']);
        $policies = is_object($row) && is_string($row->value) ? json_decode($row->value, true) : null;

        if (is_array($policies) && in_array($policyFrom, $policies, true)) {
            $policies = array_map(fn (mixed $line): mixed => $line === $policyFrom ? $policyTo : $line, $policies);
            DB::table('settings')->where('id', $row->id)->update(['value' => json_encode(array_values($policies)), 'updated_at' => now()]);
        }
    }

    private function flushCaches(): void
    {
        app(Settings::class)->flush();
        app(PageCache::class)->invalidate();
    }
};
