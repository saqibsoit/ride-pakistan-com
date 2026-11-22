<?php

declare(strict_types=1);

namespace App\Livewire\Events;

use App\Actions\Account\SaveWhatsAppNumber;
use App\Actions\Events\CreateEventOrder;
use App\Enums\EventQuestionScope;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Http\Middleware\CaptureLeadReference;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use App\Support\Events\EventAvailability;
use App\Support\Events\EventMoney;
use App\Support\Events\EventTerms;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Booking an event (decided 2026-11-22), one page: the date, how many
 * people per price, each person's name and answers, the booking's own
 * questions and the required checkbox, then the price with the deposit
 * and the rules. Signed in: straight to checkout (CreateEventOrder).
 * Signed out: the draft waits in the session for /events/{slug}/details
 * (guest checkout), or for signing in. Nothing here decides seats or
 * prices: CreateEventOrder checks everything again under the slot lock.
 */
final class EventBooking extends Component
{
    public const DRAFT_KEY = 'event_booking.draft';

    #[Locked]
    public string $eventSlug = '';

    #[Url(as: 'date')]
    public ?int $date = null;

    /** @var array<int|string, int> people per tier id */
    public array $quantities = [];

    /** @var array<int, array<string, mixed>> one row per person: tier_id, name, answers (from the browser) */
    public array $people = [];

    /** @var array<string, string> */
    public array $answers = [];

    public bool $acknowledged = false;

    public string $whatsapp = '';

    public bool $whatsappIsPhone = false;

    public ?string $error = null;

    public function mount(RideEvent $event, bool $resume = false): void
    {
        $this->eventSlug = $event->slug;
        $tiers = $event->tiers;

        foreach ($tiers as $i => $tier) {
            $this->quantities[$tier->id] = $i === 0 ? max(1, $event->min_per_booking) : 0;
        }

        $draft = session(self::DRAFT_KEY);

        if ($resume && is_array($draft) && ($draft['event'] ?? null) === $event->slug) {
            $this->date = is_int($draft['date'] ?? null) ? $draft['date'] : $this->date;
            $this->quantities = array_map('intval', (array) ($draft['quantities'] ?? $this->quantities));
            $this->people = array_values((array) ($draft['people'] ?? []));
            $this->answers = array_map('strval', (array) ($draft['answers'] ?? []));
            $this->acknowledged = (bool) ($draft['acknowledged'] ?? false);
        }

        $this->syncPeople();

        if ($this->date !== null && ! $this->bookableDates()->contains('id', $this->date)) {
            $this->date = null;
        }

        $user = $this->user();

        if ($user !== null) {
            $this->whatsapp = (string) ($user->whatsapp_phone ?? '');
            $this->whatsappIsPhone = $this->whatsapp === '' && $user->phone !== null;
        }
    }

    public function updatedQuantities(): void
    {
        $this->error = null;
        $this->syncPeople();
    }

    public function add(int $tierId): void
    {
        $this->quantities[$tierId] = (int) ($this->quantities[$tierId] ?? 0) + 1;
        $this->updatedQuantities();
    }

    public function remove(int $tierId): void
    {
        $this->quantities[$tierId] = max(0, (int) ($this->quantities[$tierId] ?? 0) - 1);
        $this->updatedQuantities();
    }

    public function choose(int $date): void
    {
        $this->error = null;
        $this->date = $this->bookableDates()->contains('id', $date) ? $date : null;
    }

    public function book(CreateEventOrder $orders): mixed
    {
        $this->error = null;
        $event = $this->event();

        if ($this->date === null) {
            $this->error = 'Choose a date.';

            return null;
        }

        $user = $this->user();

        if ($user === null) {
            return $this->continueAsGuest();
        }

        app(SaveWhatsAppNumber::class)->handle($user, $this->validateWhatsApp($user));

        try {
            $leadRef = session(CaptureLeadReference::SESSION_KEY);
            $order = $orders->handle($user, $event, $this->date, $this->attendees(), $this->answers, $this->acknowledged, is_int($leadRef) ? $leadRef : null);
        } catch (BookingRejected $e) {
            $this->error = $e->getMessage();

            return null;
        }

        session()->forget(self::DRAFT_KEY);

        return $this->redirectRoute('checkout.show', ['reference' => $order->reference]);
    }

    /** Signed out: keep the draft and give the contact details on a plain page (Turnstile). */
    public function continueAsGuest(): mixed
    {
        if ($this->user() !== null) {
            return null;
        }

        if ($this->date === null) {
            $this->error = 'Choose a date.';

            return null;
        }

        // Same rules as at checkout, before asking for the email.
        try {
            app(CreateEventOrder::class)->assertBookable($this->event(), Slot::query()->find($this->date));
        } catch (BookingRejected $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $this->saveDraft();

        return $this->redirectRoute('events.details', ['event' => $this->eventSlug]);
    }

    public function signInToContinue(): mixed
    {
        $this->saveDraft();
        redirect()->setIntendedUrl(route('events.book', ['event' => $this->eventSlug, 'resume' => 1]));

        return $this->redirectRoute('login');
    }

    public function render(): View
    {
        $event = $this->event();
        $tiers = $event->tiers->keyBy('id');
        $subtotal = 0;

        foreach ($this->people as $person) {
            $subtotal += (int) ($tiers->get($person['tier_id'])->price_minor ?? 0);
        }

        $split = EventMoney::split($subtotal, $event->depositPercent());

        return view('livewire.events.event-booking', [
            'event' => $event,
            'dates' => $this->bookableDates(),
            'threshold' => app(Settings::class)->int(SettingKey::SellingFastThreshold) ?? 2,
            'tiers' => $event->tiers,
            'personQuestions' => $event->questionsFor(EventQuestionScope::Attendee),
            'bookingQuestions' => $event->questionsFor(EventQuestionScope::Booking),
            'subtotal' => $subtotal,
            'payNow' => $split['now'],
            'balance' => $split['balance'],
            'terms' => EventTerms::lines($event),
            'user' => $this->user(),
            'holdMinutes' => app(Settings::class)->int(SettingKey::CardHoldMinutes) ?? 30,
        ]);
    }

    /**
     * @return list<array{tier_id: int, name: string, answers: array<string, string>}>
     */
    private function attendees(): array
    {
        $attendees = [];

        foreach ($this->people as $p) {
            $attendees[] = [
                'tier_id' => is_numeric($p['tier_id'] ?? null) ? (int) $p['tier_id'] : 0,
                'name' => is_string($p['name'] ?? null) ? $p['name'] : '',
                'answers' => array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) ($p['answers'] ?? [])),
            ];
        }

        return $attendees;
    }

    /** One row per person, in tier order, keeping names already typed. */
    private function syncPeople(): void
    {
        $event = $this->event();
        $byTier = [];

        foreach ($this->people as $person) {
            $byTier[(int) $person['tier_id']][] = $person;
        }

        $people = [];
        $total = 0;
        $max = $event->maxPerBooking();

        foreach ($event->tiers as $tier) {
            $count = max(0, min($max - $total, (int) ($this->quantities[$tier->id] ?? 0)));
            $this->quantities[$tier->id] = $count;
            $total += $count;

            for ($i = 0; $i < $count; $i++) {
                $people[] = $byTier[$tier->id][$i] ?? ['tier_id' => $tier->id, 'name' => '', 'answers' => []];
            }
        }

        $this->people = $people;
    }

    private function saveDraft(): void
    {
        session()->put(self::DRAFT_KEY, [
            'event' => $this->eventSlug,
            'date' => $this->date,
            'quantities' => $this->quantities,
            'people' => $this->attendees(),
            'answers' => $this->answers,
            'acknowledged' => $this->acknowledged,
        ]);
    }

    private function validateWhatsApp(User $user): string
    {
        if ($this->whatsappIsPhone && $user->phone !== null) {
            return $user->phone;
        }

        $this->validate(['whatsapp' => SaveWhatsAppNumber::rules()], [
            'whatsapp.required' => 'Enter your WhatsApp number so we can reach you about your booking.',
            'whatsapp' => SaveWhatsAppNumber::MESSAGE,
        ]);

        return $this->whatsapp;
    }

    /**
     * @return Collection<int, Slot>
     */
    private function bookableDates(): Collection
    {
        return EventAvailability::for($this->event())->bookable();
    }

    private function event(): RideEvent
    {
        return RideEvent::query()->with('tiers')->where('slug', $this->eventSlug)->published()->firstOrFail();
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
