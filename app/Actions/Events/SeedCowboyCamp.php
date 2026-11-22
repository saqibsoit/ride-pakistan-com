<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\BalanceDue;
use App\Enums\EventPaymentMode;
use App\Enums\EventQuestionScope;
use App\Enums\EventQuestionType;
use App\Enums\LessonClosure;
use App\Enums\RideEventStatus;
use App\Enums\SlotSource;
use App\Events\PublicContentChanged;
use App\Models\RideEvent;
use App\Models\RideEventCategory;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The first event (decided 2026-11-22), seeded once by slug by the
 * migration 2026_11_27_100100: Cowboy Camp, published, Sat 19 and Sun 20
 * Dec 2026, 10:00 to 17:00 Pakistan time, 10 places each, PKR 10,000 per
 * person with a 50 % non-refundable deposit and the balance on the day.
 * Both dates close the day's lesson slots; lesson bookings already made
 * are kept and listed for an admin (ApplyLessonClosure). Nothing happens
 * when the event exists (admin edits stick) or the dates have passed.
 */
final class SeedCowboyCamp
{
    public function __construct(private readonly ApplyLessonClosure $closures) {}

    public function handle(): ?RideEvent
    {
        if (RideEvent::query()->where('slug', 'cowboy-camp')->exists()) {
            return null;
        }

        $tz = 'Asia/Karachi';
        $days = [CarbonImmutable::parse('2026-12-19 10:00', $tz), CarbonImmutable::parse('2026-12-20 10:00', $tz)];

        if (! $days[0]->isFuture()) {
            return null;
        }

        $event = DB::transaction(function () use ($days): RideEvent {
            $event = new RideEvent([
                'slug' => 'cowboy-camp',
                'title' => 'Cowboy Camp',
                'ride_event_category_id' => RideEventCategory::query()->where('slug', 'camp')->value('id'),
                'summary' => 'A full day as a ranch hand at Yashwala Ranch: meet the herd, learn horsemanship, ride in the arena and out on a 45-minute trail, then cowboy games until golden hour. Lunch included.',
                'description' => "Spend a whole day with the horses, cowboy style. You'll meet the herd, groom and tack up, take an arena lesson with our trainer and head out on a 45-minute outride. There's a cowboy photo shoot, a ranch lunch, time for Zuhr and rest, and cowboy games before a golden-hour farewell.\n\nNo experience needed: the day is built for beginners and confident riders alike.",
                'location_name' => 'Yashwala Ranch, Islamabad',
                'cover_image' => 'camp.jpg',
                'cover_image_alt' => 'A camp at the ranch with horses grazing nearby',
                'payment_mode' => EventPaymentMode::Deposit,
                'deposit_percent' => 50,
                'non_refundable_percent' => 50,
                'cancellation_cutoff_days' => 7,
                'balance_due' => BalanceDue::OnTheDay,
                'balance_deadline_hours' => 0,
                'min_per_booking' => 1,
                'max_per_booking' => 10,
                'booking_closes_hours' => 24,
                'waitlist_enabled' => false,
                'acknowledgement_text' => 'I’ll wear a cowboy hat and western wear, and bring my own riding helmet. No hat, no ride.',
                'is_featured' => true,
                'meta_title' => 'Cowboy Camp at Yashwala Ranch, Islamabad',
                'meta_description' => 'A full-day cowboy camp in Islamabad on 19 and 20 December 2026: horsemanship, arena lesson, 45-minute outride, ranch lunch. PKR 10,000.',
                'blocks' => [
                    ['type' => 'programme', 'data' => ['items' => [
                        ['time' => '10:00', 'activity' => 'Arrival and meeting the herd'],
                        ['time' => '10:30', 'activity' => 'Horsemanship: grooming, tacking and handling'],
                        ['time' => '11:30', 'activity' => 'Arena lesson'],
                        ['time' => '12:30', 'activity' => 'Cowboy photo shoot'],
                        ['time' => '1:00', 'activity' => 'Ranch lunch'],
                        ['time' => '2:00', 'activity' => 'Zuhr and rest'],
                        ['time' => '2:30', 'activity' => 'The outride (45 minutes)'],
                        ['time' => '3:30', 'activity' => 'Cowboy games'],
                        ['time' => '4:30', 'activity' => 'Golden-hour farewell'],
                    ]]],
                    ['type' => 'included', 'data' => ['included' => ['Ranch lunch', 'A 45-minute outride', 'Arena lesson with our trainer', 'Horsemanship: grooming, tacking and handling', 'Cowboy photo shoot and games'], 'not_included' => ['Transport to the ranch', 'Riding helmet (bring your own)']]],
                    ['type' => 'dress_code', 'data' => ['text' => 'No hat, no ride.', 'items' => ['A cowboy hat and western wear (compulsory)', 'Your own riding helmet for riding (riders without one can’t ride)', 'Closed shoes or boots']]],
                    ['type' => 'bring', 'data' => ['items' => ['Your own riding helmet', 'A cowboy hat', 'Water bottle and sun cream']]],
                    ['type' => 'requirements', 'data' => ['level' => 'No experience needed', 'age' => '', 'fitness' => 'A full day outdoors, partly on horseback']],
                ],
                'questions' => [
                    ['key' => (string) Str::uuid(), 'label' => 'Riding experience', 'type' => EventQuestionType::Select->value, 'options' => ['Never ridden', 'A few times', 'Confident'], 'required' => true, 'scope' => EventQuestionScope::Attendee->value],
                    ['key' => (string) Str::uuid(), 'label' => 'Dietary needs', 'type' => EventQuestionType::Text->value, 'options' => [], 'required' => false, 'scope' => EventQuestionScope::Attendee->value],
                ],
            ]);
            $event->status = RideEventStatus::Published;
            $event->save();

            $event->tiers()->create(['name' => 'Per person', 'price_minor' => 1_000_000, 'sort' => 0]);

            foreach ($days as $start) {
                $slot = new Slot(['starts_at' => $start->utc(), 'ends_at' => $start->setTime(17, 0)->utc(), 'capacity' => 10, 'lesson_closure' => LessonClosure::Day]);
                $slot->source = SlotSource::Manual;
                $slot->ride_event_id = $event->id;
                $slot->save();
            }

            return $event;
        });

        // Close the lesson slots on both days (bookings kept, listed for an admin).
        $this->closures->forEvent($event);
        PublicContentChanged::dispatch();

        return $event;
    }
}
