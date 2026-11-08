<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The quick replies the site ships with (milestone 10c). Prices, times,
 * links and the location come from tokens, so they always match the live
 * data. Only facts confirmed in PLAN §2 are written out; bank details are
 * never put in a template (the customer sees them at checkout).
 * Seeded once by key: a template an admin edited or deleted stays so.
 */
return new class extends Migration
{
    /** @return list<array{key: string, title: string, category: string, body: string}> */
    public static function templates(): array
    {
        return [
            [
                'key' => 'first-reply',
                'title' => 'First reply',
                'category' => 'booking',
                'body' => "Hi {lead_name}, thanks for getting in touch with Ride Pakistan!\n\n"
                    .'Our sessions run {schedule} in {location} with our trainer {trainer}. No experience is needed. '
                    .'In each session you bring the horses in from the paddock, groom and tack up, ride (mostly on the trails), '
                    ."then untack and give your horse a massage and stretch.\n\n"
                    .'A single session is {price:single-session} per rider. You can pick a date and book here: {booking_link}',
            ],
            [
                'key' => 'prices',
                'title' => 'Prices',
                'category' => 'prices',
                'body' => "Hi {lead_name}, here are our prices:\n\n"
                    ."- Single session: {price:single-session} per rider\n"
                    ."- Private session: {price:private-session} per rider\n"
                    ."- 8-class package: {price:8-class-package} ({per_session:8-class-package} a session, valid {validity:8-class-package})\n"
                    ."- 16-class package: {price:16-class-package} ({per_session:16-class-package} a session, valid {validity:16-class-package})\n\n"
                    .'Book here: {booking_link}',
            ],
            [
                'key' => 'timings',
                'title' => 'Timings',
                'category' => 'timings',
                'body' => 'Hi {lead_name}, we ride {schedule}. You can see every open slot with the seats left and book here: {booking_link}',
            ],
            [
                'key' => 'location',
                'title' => 'Location',
                'category' => 'location',
                'body' => "Hi {lead_name}, we're in {location}. We send the exact location pin with your booking confirmation. You can book here: {booking_link}",
            ],
            [
                'key' => 'private-sessions',
                'title' => 'Private sessions',
                'category' => 'private',
                'body' => 'Hi {lead_name}, a private session is just for you and your group, with the whole slot to yourselves. '
                    .'It costs {price:private-session} per rider. Details and dates: {product_link:private-session}',
            ],
            [
                'key' => 'packages',
                'title' => 'Packages',
                'category' => 'packages',
                'body' => "Hi {lead_name}, if you'd like to ride regularly, our packages work out cheaper per session:\n\n"
                    ."- 8 classes for {price:8-class-package}, valid {validity:8-class-package} from payment\n"
                    ."- 16 classes for {price:16-class-package}, valid {validity:16-class-package} from payment\n\n"
                    .'Any of your riders can use the credits. Details: {product_link:8-class-package}',
            ],
            [
                'key' => 'bank-transfer',
                'title' => 'Paying by bank transfer',
                'category' => 'payment',
                'body' => "Hi {lead_name}, you can pay by bank transfer:\n\n"
                    ."1. Book your session here: {booking_link}\n"
                    ."2. At checkout, choose \"Bank transfer\". You'll see our bank details and your booking reference.\n"
                    ."3. Transfer the amount with the reference, then upload a screenshot of the transfer on the same page.\n\n"
                    .'We hold your seats while we check the transfer and confirm by email.',
            ],
            [
                'key' => 'follow-up',
                'title' => 'Follow-up after no reply',
                'category' => 'booking',
                'body' => 'Hi {lead_name}, just checking in. Would you still like to ride with us? Sessions run {schedule} and seats fill up quickly. '
                    .'You can book here: {booking_link}',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach (self::templates() as $sort => $template) {
            if (DB::table('quick_replies')->where('key', $template['key'])->exists()) {
                continue;
            }

            DB::table('quick_replies')->insert([...$template, 'sort' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('quick_replies')->whereIn('key', array_column(self::templates(), 'key'))->delete();
    }
};
