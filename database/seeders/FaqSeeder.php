<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * FAQs. Answers use tokens ({schedule}, {price:slug}, {cutoff} …) filled from
 * live data by App\Support\BusinessFacts, so prices and times never drift.
 * The first five also appear on the homepage.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $home = Page::query()->where('slug', 'home')->value('id');

        $faqs = [
            ['Getting started', 'I’ve never been near a horse. Is that okay?', 'Yes. The sessions are made for complete beginners. You’ll spend time on the ground with your horse before you ever get in the saddle, and your trainer stays with you throughout.', true],
            ['Getting started', 'What should I wear?', 'Full-length trousers and closed shoes or boots with a small heel are best. Avoid loose scarves and anything that could catch on the saddle.', true],
            ['Booking and prices', 'Can I book a private session?', 'Yes. Private sessions are available for individuals, couples, families and small groups, at {price:private-session} per rider. The whole slot is yours.', true],
            ['On the day', 'Where exactly are you?', 'We’re in {location}. We share the exact location pin when we confirm your booking.', true],
            ['On the day', 'Do the timings ever change?', 'Our current slots are {schedule}. They shift slightly with the seasons, and we always confirm your slot in advance.', true],
            ['Booking and prices', 'How much does a session cost?', 'A single 2-hour session is {price:single-session} per rider. The 8-class package is {price:8-class-package} ({per_session:8-class-package} per session) and the 16-class package is {price:16-class-package} ({per_session:16-class-package} per session).', false],
            ['Booking and prices', 'How do packages work?', 'A package gives you session credits. The 8-class package is valid {validity:8-class-package} and the 16-class package {validity:16-class-package}, counted from when your payment is confirmed. Book your sessions as you go; credits that pass their validity expire.', false],
            ['Booking and prices', 'How do I pay?', 'Online by card, or by bank transfer using your booking reference. Card payments confirm your place straight away; bank transfers are confirmed once we’ve checked your payment.', false],
            ['Booking and prices', 'Can I cancel or reschedule?', 'Yes, up to {cutoff} hours before your slot.', false],
        ];

        foreach ($faqs as $index => [$topic, $question, $answer, $onHome]) {
            Faq::query()->firstOrCreate(['question' => $question], [
                'answer' => $answer,
                'topic' => $topic,
                'sort' => ($index + 1) * 10,
                'page_id' => $onHome ? $home : null,
                'is_published' => true,
            ]);
        }
    }
}
