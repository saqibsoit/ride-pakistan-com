<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Booking\HoldSeats;
use App\Actions\Booking\SlotLock;
use App\Actions\Discounts\ReferralDiscount;
use App\Actions\Discounts\RepriceOrder;
use App\Actions\Orders\GenerateOrderReference;
use App\Actions\Riders\SaveRider;
use App\Enums\EventQuestionScope;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\RideEvent;
use App\Models\RideEventTier;
use App\Models\Slot;
use App\Models\User;
use App\Support\Events\EventAnswers;
use App\Support\Events\EventMoney;
use App\Support\Events\EventTiers;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An unpaid order for an event occurrence (decided 2026-11-22), on the
 * lesson machinery: the same order, reference, seat hold (HoldSeats under
 * the slot row lock, with the tier caps), discounts, checkout and
 * FulfilOrder. What this adds is the event's own rules:
 *
 * - the event is published and the occurrence is one of its dates, open,
 *   inside the booking window (opens / closes N hours before);
 * - the group size is within the event's min and max;
 * - every attendee has a name (one name once), a tier of this event and
 *   the required answers; the booking's own questions are answered; the
 *   required checkbox (dress code…) is ticked;
 * - prices come from the tier rows, never from the request; a deposit
 *   event charges the deposit now and keeps the rest as the balance.
 *
 * Attendees become riders of the account: a guest's are always new riders
 * (CreateGuestRiders' rule), a signed-in customer's reuse a rider with the
 * same name.
 */
final class CreateEventOrder
{
    public function __construct(
        private readonly HoldSeats $holdSeats,
        private readonly SlotLock $slotLock,
        private readonly GenerateOrderReference $references,
        private readonly Settings $settings,
        private readonly SaveRider $saveRider,
        private readonly ReferralDiscount $referrals,
        private readonly RepriceOrder $reprice,
    ) {}

    /**
     * @param  list<array{tier_id: int, name: string, answers?: array<string, mixed>}>  $attendees
     * @param  array<string, mixed>  $bookingAnswers
     * @param  array{name: string, whatsapp: string|null}|null  $guest  guest checkout contact
     *
     * @throws BookingRejected
     */
    public function handle(User $user, RideEvent $event, int $slotId, array $attendees, array $bookingAnswers = [], bool $acknowledged = false, ?int $leadId = null, ?array $guest = null): Order
    {
        // Re-read: prices and rules come from the database.
        $event = RideEvent::query()->with('tiers')->whereKey($event->id)->firstOrFail();
        $occurrence = Slot::query()->whereKey($slotId)->first();

        $this->assertBookable($event, $occurrence);

        /** @var Slot $occurrence */
        $attendees = $this->cleanAttendees($event, $attendees);
        $answers = EventAnswers::clean($event->questionsFor(EventQuestionScope::Booking), $bookingAnswers);

        if ($event->acknowledgement_text !== null && trim($event->acknowledgement_text) !== '' && ! $acknowledged) {
            throw BookingRejected::eventRule('Please tick: '.$event->acknowledgement_text);
        }

        $subtotal = (int) array_sum(array_column($attendees, 'price_minor'));
        $split = EventMoney::split($subtotal, $event->depositPercent());
        $expiresAt = CarbonImmutable::now()->addMinutes($this->settings->int(SettingKey::CardHoldMinutes) ?? 30);

        return DB::transaction(function () use ($user, $event, $occurrence, $attendees, $answers, $acknowledged, $subtotal, $split, $expiresAt, $leadId, $guest): Order {
            // Slot lock first (see CreateOrder: the order items' foreign key
            // check would otherwise deadlock two orders in HoldSeats).
            $this->slotLock->acquire($occurrence->id);

            $riderIds = $this->riders($user, $attendees, $guest !== null);

            $order = Order::query()->create([
                'reference' => $this->references->handle(),
                'user_id' => $user->id,
                'product_id' => null,
                'ride_event_id' => $event->id,
                'deposit_percent' => $event->depositPercent(),
                'subtotal_minor' => $subtotal,
                'amount_minor' => $split['now'],
                'balance_minor' => $split['balance'],
                'currency' => 'PKR',
                'expires_at' => $expiresAt,
                'answers' => $answers === [] ? null : $answers,
                'meta' => array_filter([
                    'lead_id' => $leadId,
                    // What the customer agreed to, as worded then.
                    'acknowledgement' => $acknowledged && $event->acknowledgement_text !== null ? ['text' => $event->acknowledgement_text, 'at' => now()->toIso8601String()] : null,
                    // The questions as asked, so later edits don't relabel old answers.
                    'event_questions' => $event->questions ?? null,
                    'non_refundable_percent' => $event->non_refundable_percent,
                    'cancellation_cutoff_days' => $event->cancellation_cutoff_days,
                ], fn (mixed $v): bool => $v !== null),
                'placed_as_guest' => $guest !== null,
                'contact_name' => $guest['name'] ?? null,
                'contact_whatsapp' => $guest['whatsapp'] ?? null,
            ]);

            $items = [];

            foreach ($attendees as $i => $attendee) {
                $items[] = $order->items()->create([
                    'slot_id' => $occurrence->id,
                    'rider_id' => $riderIds[$i],
                    'ride_event_tier_id' => $attendee['tier_id'],
                    'unit_price_minor' => $attendee['price_minor'],
                    'answers' => $attendee['answers'] === [] ? null : $attendee['answers'],
                ]);
            }

            $this->holdSeats->handle($order, $occurrence->id, $riderIds, false, $expiresAt, EventTiers::forItems($items));

            activity('orders')
                ->performedOn($order)
                ->causedBy($user)
                ->event('created')
                ->withProperties([
                    'event' => $event->slug,
                    'slot_id' => $occurrence->id,
                    'people' => count($riderIds),
                    'subtotal_minor' => $subtotal,
                    'amount_minor' => $split['now'],
                    'balance_minor' => $split['balance'],
                    'guest' => $guest !== null,
                ])
                ->log('Order '.$order->reference.' created');

            if ($guest === null && $this->referrals->referralFor($user, $order) !== null) {
                $this->reprice->handle($order, null, $user);
            }

            return $order;
        });
    }

    /**
     * @throws BookingRejected
     */
    public function assertBookable(RideEvent $event, ?Slot $occurrence, ?CarbonImmutable $now = null): void
    {
        if (! $event->isPublished() || $event->tiers->isEmpty()) {
            throw BookingRejected::eventRule('This event isn’t taking bookings.');
        }

        if ($occurrence === null || $occurrence->ride_event_id !== $event->id || $occurrence->status !== SlotStatus::Open) {
            throw BookingRejected::slotNotBookable();
        }

        $now ??= CarbonImmutable::now();

        if ($event->booking_opens_at !== null && $now->isBefore($event->booking_opens_at)) {
            throw BookingRejected::eventRule('Bookings open on '.$event->booking_opens_at->setTimezone(Slot::displayTimezone())->format('D j M Y, H:i').'.');
        }

        if (! $now->isBefore($event->bookingClosesAt($occurrence))) {
            throw BookingRejected::eventRule('Bookings for this date have closed.');
        }
    }

    /**
     * @param  list<array{tier_id: int, name: string, answers?: array<string, mixed>}>  $attendees
     * @return list<array{tier_id: int, name: string, price_minor: int, answers: array<string, string>}>
     *
     * @throws BookingRejected
     */
    private function cleanAttendees(RideEvent $event, array $attendees): array
    {
        $count = count($attendees);

        if ($count < $event->min_per_booking) {
            throw BookingRejected::eventRule('This event takes bookings of at least '.$event->min_per_booking.' '.Str::plural('person', $event->min_per_booking).'.');
        }

        if ($count > $event->maxPerBooking()) {
            throw BookingRejected::eventRule('Up to '.$event->maxPerBooking().' people can be booked at once.');
        }

        /** @var array<int, RideEventTier> $tiers */
        $tiers = $event->tiers->keyBy('id')->all();
        $questions = $event->questionsFor(EventQuestionScope::Attendee);
        $seen = [];
        $clean = [];

        foreach ($attendees as $attendee) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $attendee['name']));
            $tier = $tiers[(int) $attendee['tier_id']] ?? null;

            if ($name === '' || mb_strlen($name) > 120) {
                throw BookingRejected::eventRule('Enter each person’s name (up to 120 characters).');
            }

            if (isset($seen[Str::lower($name)])) {
                throw BookingRejected::eventRule('Each person needs their own name: '.$name.' is listed twice.');
            }

            if ($tier === null) {
                throw BookingRejected::eventRule('Choose a ticket type for '.$name.'.');
            }

            $seen[Str::lower($name)] = true;
            $clean[] = [
                'tier_id' => $tier->id,
                'name' => $name,
                'price_minor' => $tier->price_minor,
                'answers' => EventAnswers::clean($questions, (array) ($attendee['answers'] ?? []), $name),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{tier_id: int, name: string, price_minor: int, answers: array<string, string>}>  $attendees
     * @return list<int> rider ids, one per attendee in order
     */
    private function riders(User $user, array $attendees, bool $guest): array
    {
        $existing = $guest ? collect() : $user->riders()->get(['id', 'name'])
            ->keyBy(fn ($rider): string => Str::lower(trim((string) $rider->name)));
        $ids = [];

        foreach ($attendees as $attendee) {
            $match = $existing->get(Str::lower($attendee['name']));
            $ids[] = $match !== null ? (int) $match->id : $this->saveRider->handle($user, ['name' => $attendee['name']])->id;
        }

        return $ids;
    }
}
