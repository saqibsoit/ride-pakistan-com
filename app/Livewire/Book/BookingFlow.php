<?php

declare(strict_types=1);

namespace App\Livewire\Book;

use App\Actions\Account\SaveWhatsAppNumber;
use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\PriceQuote;
use App\Actions\PrivateRequests\PrivateRequestRules;
use App\Actions\PrivateRequests\SubmitPrivateRequest;
use App\Actions\Riders\SaveRider;
use App\Actions\Slots\SlotOverlap;
use App\Enums\AgeBracket;
use App\Enums\CreditPackStatus;
use App\Enums\ExperienceLevel;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Exceptions\PrivateRequestRejected;
use App\Http\Middleware\CaptureLeadReference;
use App\Models\CreditPack;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Availability;
use App\Support\BusinessFacts;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /book (PLAN §6): product → slot → riders → review → order → checkout.
 *
 * The component only ever holds ids, counts and rider details. Prices are
 * looked up from the product row on every render (PriceQuote) and again
 * by CreateOrder, so nothing the browser sends can change an amount.
 * Guests fill in rider details, then at the review step either continue
 * as a guest (their details on /book/details, guest checkout decided
 * 2026-11-12) or sign in with a code; either way the draft waits in their
 * session (sign-in resumes at /book?resume=1).
 * Customers with package credits can book straight away ("use credits").
 *
 * Private sessions can also be requested for the customer's own date and
 * time (prompt 15B): the slot step's request panel, then riders and
 * review as usual; "Send request" hands over to SubmitPrivateRequest
 * instead of creating an order. An admin approves it later.
 */
final class BookingFlow extends Component
{
    public const DRAFT_KEY = 'booking.draft';

    public const STEPS = ['product', 'slot', 'riders', 'review'];

    public const CREDITS = 'credits';

    #[Url(as: 'product')]
    public string $productSlug = '';

    #[Url(as: 'slot')]
    public ?int $slotId = null;

    #[Url(as: 'use')]
    public string $use = '';

    #[Url]
    public string $step = 'product';

    /** Package bought without choosing a first date. */
    public bool $later = false;

    /** @var list<int> */
    public array $riderIds = [];

    /** @var list<array{name: string, age_bracket: string, experience_level: string}> */
    public array $guestRiders = [];

    public int $riderCount = 1;

    public string $newRiderName = '';

    /** Private session request (prompt 15B): a date and start time of the customer's choice, Pakistan time. */
    public bool $requesting = false;

    public string $requestDate = '';

    public string $requestTime = '';

    public string $requestNote = '';

    /** WhatsApp number, required for every booking (prompt 14); saved to the profile. */
    public string $whatsapp = '';

    /** "This is also my WhatsApp": use the profile phone instead of typing it again. */
    public bool $whatsappIsPhone = false;

    #[Locked]
    public ?string $error = null;

    /** A group slot that just filled up: the error offers its waitlist (milestone 10d). */
    #[Locked]
    public ?int $waitlistSlotId = null;

    public function mount(): void
    {
        $riders = request()->query('riders');
        $this->riderCount = max(1, min(is_numeric($riders) ? (int) $riders : 1, $this->maxRiders()));

        if (request()->boolean('resume')) {
            $this->resume();
        }

        // Sent back from /book/details (a slot that just filled up, a draft changed in another tab).
        $notice = session('booking_notice');

        if (is_string($notice)) {
            $this->error = $notice;
        }

        $this->prefillWhatsApp();
        $this->normaliseStep();
    }

    /*
    |--------------------------------------------------------------------------
    | Steps
    |--------------------------------------------------------------------------
    */

    public function chooseProduct(string $slug): void
    {
        $this->error = null;

        if ($slug === self::CREDITS) {
            if ($this->usablePacks()->isEmpty()) {
                return;
            }

            $this->use = self::CREDITS;
            $this->productSlug = '';
        } else {
            if ($this->findProduct($slug) === null) {
                return;
            }

            $this->use = '';
            $this->productSlug = $slug;
        }

        // A preselected slot stays if it suits the new choice.
        if ($this->slotId !== null && $this->selectedSlot() === null) {
            $this->slotId = null;
        }

        $this->later = false;
        $this->requesting = false;
        $this->step = $this->slotId !== null ? 'riders' : 'slot';
    }

    public function chooseSlot(int $slotId): void
    {
        $this->error = null;
        $this->slotId = $slotId;

        if ($this->selectedSlot() === null) {
            $this->slotId = null;
            $this->error = 'That slot can no longer be booked. Please choose another.';

            return;
        }

        $this->later = false;
        $this->requesting = false;
        $this->step = 'riders';
    }

    /** Private product: ask for this date and start time instead of a slot (prompt 15B). */
    public function chooseRequestTime(): void
    {
        $this->error = null;

        if ($this->product()?->type !== ProductType::Private) {
            return;
        }

        $this->validate([
            'requestDate' => ['required', 'date_format:Y-m-d'],
            'requestTime' => ['required', 'date_format:H:i'],
        ], [
            'requestDate.*' => 'Choose a date.',
            'requestTime.*' => 'Choose a start time.',
        ]);

        $start = $this->requestedStart();
        $rules = app(PrivateRequestRules::class);
        $problem = $start === null ? 'Choose a date and start time.' : $rules->problem($start);

        if ($problem === null && $start !== null && app(SlotOverlap::class)->firstClash($start, $start->addMinutes($rules->durationMinutes())) !== null) {
            $problem = 'That time isn’t free: there’s another session close to it. Please choose another time, or pick one of the slots above.';
        }

        if ($problem !== null) {
            $this->addError('requestTime', $problem);

            return;
        }

        $this->slotId = null;
        $this->later = false;
        $this->requesting = true;
        $this->step = 'riders';
    }

    /** Package without a first session. */
    public function chooseLater(): void
    {
        if ($this->product()?->type !== ProductType::Package) {
            return;
        }

        $this->slotId = null;
        $this->later = true;
        $this->requesting = false;
        $this->riderIds = [];
        $this->step = 'review';
    }

    public function addGuestRider(): void
    {
        if (count($this->guestRiders) < $this->maxRiders()) {
            $this->guestRiders[] = ['name' => '', 'age_bracket' => '', 'experience_level' => ''];
        }
    }

    public function removeGuestRider(int $index): void
    {
        $riders = $this->guestRiders;
        array_splice($riders, $index, 1);
        $this->guestRiders = $riders;
    }

    /** Signed in: save a new rider and select them. */
    public function addRider(SaveRider $save): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $this->validate(['newRiderName' => ['required', 'string', 'max:120']], ['newRiderName.required' => 'Enter the rider’s name.']);

        $rider = $save->handle($user, ['name' => $this->newRiderName]);
        $this->riderIds[] = $rider->id;
        $this->newRiderName = '';
    }

    public function confirmRiders(): void
    {
        $this->error = null;
        $max = $this->maxRiders();

        if ($this->user() === null) {
            $this->validate([
                'guestRiders' => ['required', 'array', 'min:1', 'max:'.$max],
                'guestRiders.*.name' => ['required', 'string', 'max:120'],
                'guestRiders.*.age_bracket' => ['nullable', Rule::enum(AgeBracket::class)],
                'guestRiders.*.experience_level' => ['nullable', Rule::enum(ExperienceLevel::class)],
            ], [
                'guestRiders.required' => 'Add at least one rider.',
                'guestRiders.max' => 'Up to '.$max.' riders can book this.',
                'guestRiders.*.name.required' => 'Enter each rider’s name.',
            ]);
        } else {
            $owned = $this->user()->riders()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $this->riderIds = array_values(array_intersect(array_map('intval', $this->riderIds), $owned));

            if ($this->riderIds === []) {
                $this->addError('riderIds', 'Choose at least one rider.');

                return;
            }

            if (count($this->riderIds) > $max) {
                $this->addError('riderIds', 'Up to '.$max.' riders can book this.');

                return;
            }
        }

        $this->step = 'review';
    }

    public function goTo(string $step): void
    {
        if (in_array($step, self::STEPS, true) && array_search($step, self::STEPS, true) < array_search($this->step, self::STEPS, true)) {
            $this->error = null;
            $this->step = $step;
        }
    }

    /** Guests: keep the draft in the session and sign in with a code. */
    public function signInToContinue(): mixed
    {
        $this->saveDraft();

        redirect()->setIntendedUrl(route('book', ['resume' => 1]));

        return $this->redirectRoute('login');
    }

    /**
     * Guests (guest checkout, decided 2026-11-12): keep the draft in the
     * session and give their details on /book/details, a plain page so the
     * Turnstile check works without extra scripts.
     */
    public function continueAsGuest(): mixed
    {
        if ($this->user() !== null || $this->use === self::CREDITS) {
            return null;
        }

        $this->saveDraft();

        return $this->redirectRoute('book.details');
    }

    private function saveDraft(): void
    {
        session()->put(self::DRAFT_KEY, [
            'whatsapp' => $this->whatsapp,
            'product' => $this->productSlug,
            'use' => $this->use,
            'slot' => $this->slotId,
            'later' => $this->later,
            'riders' => $this->guestRiders,
            'request' => $this->requesting ? ['date' => $this->requestDate, 'time' => $this->requestTime, 'note' => $this->requestNote] : null,
        ]);
    }

    public function placeOrder(CreateOrder $createOrder, BookWithCredit $bookWithCredit): mixed
    {
        $user = $this->user();

        if ($user === null) {
            return $this->continueAsGuest();
        }

        $this->error = null;
        app(SaveWhatsAppNumber::class)->handle($user, $this->validateWhatsApp());

        if ($this->requesting) {
            return $this->sendRequest($user);
        }

        try {
            if ($this->use === self::CREDITS) {
                if ($this->slotId === null) {
                    $this->step = 'slot';

                    return null;
                }

                $bookWithCredit->handle($user, $this->slotId, $this->riderIds);
                session()->flash('status', 'booked-with-credits');

                return $this->redirectRoute('account.bookings');
            }

            $product = $this->product();

            if ($product === null) {
                $this->step = 'product';

                return null;
            }

            $leadRef = session(CaptureLeadReference::SESSION_KEY);
            $order = $createOrder->handle($user, $product, $this->later ? null : $this->slotId, $this->later ? [] : $this->riderIds, is_int($leadRef) ? $leadRef : null);
        } catch (BookingRejected $e) {
            $this->error = $e->getMessage();
            $this->waitlistSlotId = $e->reason === BookingRejected::SLOT_FULL && $this->product()?->type !== ProductType::Private ? $this->slotId : null;

            if (in_array($e->reason, [BookingRejected::SLOT_FULL, BookingRejected::SLOT_NOT_BOOKABLE, BookingRejected::SLOT_NOT_EMPTY], true)) {
                $this->slotId = null;
                $this->step = 'slot';
            }

            return null;
        }

        return $this->redirectRoute('checkout.show', ['reference' => $order->reference]);
    }

    private function sendRequest(User $user): mixed
    {
        $this->validate(['requestNote' => ['nullable', 'string', 'max:'.PrivateSessionRequest::NOTE_MAX_LENGTH]], [
            'requestNote.max' => 'Keep the note under '.PrivateSessionRequest::NOTE_MAX_LENGTH.' characters, please.',
        ]);

        $product = $this->product();
        $start = $this->requestedStart();

        if ($product === null || $start === null) {
            $this->requesting = false;
            $this->step = 'slot';

            return null;
        }

        try {
            app(SubmitPrivateRequest::class)->handle($user, $product, $start, array_map('intval', $this->riderIds), $this->requestNote);
        } catch (PrivateRequestRejected $e) {
            $this->error = $e->getMessage();

            return null;
        }

        session()->flash('status', 'private-request-sent');

        return $this->redirectRoute('account.private-requests');
    }

    /** The requested start in UTC, from the Pakistan date and time fields. */
    private function requestedStart(): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->requestDate) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->requestTime)) {
            return null;
        }

        return CarbonImmutable::parse($this->requestDate.' '.$this->requestTime, Slot::displayTimezone())->utc();
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render(BusinessFacts $facts, Settings $settings): View
    {
        $this->normaliseStep();

        $product = $this->product();
        $slot = $this->selectedSlot();
        $count = $this->user() !== null ? count($this->riderIds) : count($this->guestRiders);

        return view('livewire.book.booking-flow', [
            'user' => $this->user(),
            'products' => $facts->products(),
            'product' => $product,
            'chosenSlot' => $slot,
            'slotOptions' => $this->step === 'slot' ? $this->slotChoices() : new Collection,
            'packs' => $this->usablePacks(),
            'riders' => $this->user()?->riders()->orderBy('name')->get() ?? new Collection,
            'quote' => $product !== null ? PriceQuote::for($product, max(1, $count)) : null,
            'creditsNeeded' => $this->use === self::CREDITS ? $count : 0,
            'maxRiders' => $this->maxRiders(),
            'ageBrackets' => AgeBracket::cases(),
            'experienceLevels' => ExperienceLevel::cases(),
            'holdMinutes' => $settings->int(SettingKey::CardHoldMinutes) ?? 30,
            'usingCredits' => $this->use === self::CREDITS,
            'requestRules' => app(PrivateRequestRules::class),
            'requestLabel' => $this->requestLabel(),
        ]);
    }

    /** "Sat 14 Nov 2026, 10:00–12:00" for the requested time, or null. */
    private function requestLabel(): ?string
    {
        $start = $this->requesting ? $this->requestedStart() : null;

        if ($start === null) {
            return null;
        }

        $tz = Slot::displayTimezone();
        $end = $start->addMinutes(app(PrivateRequestRules::class)->durationMinutes());

        return $start->setTimezone($tz)->format('D j M Y, H:i').'–'.$end->setTimezone($tz)->format('H:i');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function product(): ?Product
    {
        return $this->productSlug === '' ? null : $this->findProduct($this->productSlug);
    }

    /**
     * Products looked up during this request, by slug (milestone 11: one
     * query per slug instead of one per call). Not a Livewire property:
     * it never leaves the server and is rebuilt on every request.
     *
     * @var array<string, Product|null>
     */
    private array $products = [];

    private function findProduct(string $slug): ?Product
    {
        if (! array_key_exists($slug, $this->products)) {
            $this->products[$slug] = Product::query()->where('slug', $slug)->active()->first();
        }

        return $this->products[$slug];
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** The chosen slot, if it is still bookable for the current choice. */
    private function selectedSlot(): ?Slot
    {
        if ($this->slotId === null) {
            return null;
        }

        $slot = app(Availability::class)->find($this->slotId);

        if ($slot === null) {
            return null;
        }

        $product = $this->product();

        if ($product?->type === ProductType::Private) {
            return $slot->isEmpty() ? $slot : null;
        }

        if ($this->use === self::CREDITS && ! $this->usablePacks()->contains(fn (CreditPack $pack): bool => $pack->covers($slot))) {
            return null;
        }

        if ($product?->type === ProductType::Package && $slot->starts_at->isAfter(now()->addDays((int) $product->validity_days))) {
            return null;
        }

        // Seats a waitlist offer holds for this customer are free for them (milestone 10d).
        $user = $this->user();
        $offered = $user === null ? 0 : WaitlistEntry::offeredSeats($user, $slot->id);

        return $slot->seatsFree() + $offered > 0 ? $slot : null;
    }

    /**
     * @return Collection<int, Slot>
     */
    private function slotChoices(): Collection
    {
        $product = $this->product();
        $private = $product?->type === ProductType::Private;
        $slots = app(Availability::class)->upcoming(24, $private ? Availability::TYPE_PRIVATE : Availability::TYPE_GROUP, 6);

        if ($this->use === self::CREDITS) {
            $packs = $this->usablePacks();
            $slots = $slots->filter(fn (Slot $slot): bool => $packs->contains(fn (CreditPack $pack): bool => $pack->covers($slot)))->values();
        }

        if ($product?->type === ProductType::Package) {
            $until = now()->addDays((int) $product->validity_days);
            $slots = $slots->filter(fn (Slot $slot): bool => $slot->starts_at->isBefore($until))->values();
        }

        return $slots;
    }

    /**
     * @return Collection<int, CreditPack>
     */
    private function usablePacks(): Collection
    {
        $user = $this->user();

        if ($user === null) {
            return new Collection;
        }

        return $user->creditPacks()
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '>', now())
            ->whereColumn('credits_used', '<', 'credits_total')
            ->orderBy('expires_at')
            ->get();
    }

    private function maxRiders(): int
    {
        $perSlot = app(Settings::class)->int(SettingKey::RidersPerSlot) ?? 6;

        if ($this->use === self::CREDITS) {
            return max(1, min($perSlot, (int) $this->usablePacks()->sum(fn (CreditPack $pack): int => $pack->creditsRemaining())));
        }

        $product = $this->productSlug === '' ? null : $this->findProduct($this->productSlug);

        if ($product?->type === ProductType::Package) {
            return max(1, min($perSlot, $product->sessions_count));
        }

        if ($product?->type === ProductType::Private) {
            return $this->requesting ? $perSlot : ($this->selectedSlot()->capacity ?? $perSlot);
        }

        return $perSlot;
    }

    /** Never show a step whose earlier choices are missing. */
    private function normaliseStep(): void
    {
        if (! in_array($this->step, self::STEPS, true)) {
            $this->step = 'product';
        }

        $hasChoice = $this->use === self::CREDITS ? $this->usablePacks()->isNotEmpty() : $this->product() !== null;

        if (! $hasChoice) {
            $this->use = '';
            $this->step = 'product';

            return;
        }

        // A request is only for a private product, with its date and time filled in.
        if ($this->requesting && ($this->product()?->type !== ProductType::Private || $this->requestedStart() === null)) {
            $this->requesting = false;
        }

        if ($this->step === 'product' && $this->slotId !== null && $this->selectedSlot() !== null) {
            $this->step = 'riders';
        } elseif ($this->step === 'product') {
            $this->step = 'slot';
        }

        if (in_array($this->step, ['riders', 'review'], true) && ! $this->later && ! $this->requesting && $this->selectedSlot() === null) {
            $this->step = 'slot';
        }

        if ($this->step === 'review' && ! $this->later) {
            $hasRiders = $this->user() !== null ? $this->riderIds !== [] : $this->guestRiders !== [];

            if (! $hasRiders) {
                $this->step = 'riders';
            }
        }

        if ($this->step === 'riders' && $this->user() === null && $this->guestRiders === []) {
            $this->guestRiders = array_fill(0, $this->riderCount, ['name' => '', 'age_bracket' => '', 'experience_level' => '']);
        }
    }

    /**
     * The number this booking uses: the profile phone when the customer
     * ticked "This is also my WhatsApp", otherwise the one they typed.
     */
    private function validateWhatsApp(): string
    {
        $phone = $this->user()?->phone;

        if ($this->whatsappIsPhone && $phone !== null) {
            return $phone;
        }

        $this->validate(['whatsapp' => SaveWhatsAppNumber::rules()], [
            'whatsapp.required' => 'Enter your WhatsApp number so we can reach you about your booking.',
            'whatsapp' => SaveWhatsAppNumber::MESSAGE,
        ]);

        return $this->whatsapp;
    }

    /**
     * Customers who gave a WhatsApp number before see it again; otherwise
     * "This is also my WhatsApp" starts ticked when the profile has a phone.
     */
    private function prefillWhatsApp(): void
    {
        $user = $this->user();

        if ($user === null || $this->whatsapp !== '') {
            return;
        }

        if ($user->whatsapp_phone !== null) {
            $this->whatsapp = $user->whatsapp_phone;
            $this->whatsappIsPhone = $user->phone === $user->whatsapp_phone;

            return;
        }

        $this->whatsappIsPhone = $user->phone !== null;
    }

    /**
     * Back from sign-in: save the guest riders and pick up where we left
     * off. Still a guest (back from /book/details): the same draft, riders
     * as typed, kept in the session for the next try.
     */
    private function resume(): void
    {
        $user = $this->user();
        $draft = $user === null ? session()->get(self::DRAFT_KEY) : session()->pull(self::DRAFT_KEY);

        if (! is_array($draft)) {
            return;
        }

        if ($user === null) {
            $this->restoreGuestDraft($draft);

            return;
        }

        $this->productSlug = is_string($draft['product'] ?? null) ? $draft['product'] : '';
        $this->use = ($draft['use'] ?? '') === self::CREDITS ? self::CREDITS : '';
        $this->slotId = is_int($draft['slot'] ?? null) ? $draft['slot'] : null;
        $this->later = (bool) ($draft['later'] ?? false);
        $this->whatsapp = is_string($draft['whatsapp'] ?? null) ? $draft['whatsapp'] : '';

        $request = $draft['request'] ?? null;

        if (is_array($request)) {
            $this->requesting = true;
            $this->requestDate = is_string($request['date'] ?? null) ? $request['date'] : '';
            $this->requestTime = is_string($request['time'] ?? null) ? $request['time'] : '';
            $this->requestNote = is_string($request['note'] ?? null) ? $request['note'] : '';
        }

        $save = app(SaveRider::class);
        $existing = $user->riders()->get()->keyBy(fn ($rider): string => mb_strtolower(trim($rider->name)));
        $ids = [];

        foreach ((array) ($draft['riders'] ?? []) as $guest) {
            $name = is_array($guest) && is_string($guest['name'] ?? null) ? trim($guest['name']) : '';

            if ($name === '') {
                continue;
            }

            $rider = $existing->get(mb_strtolower($name)) ?? $save->handle($user, [
                'name' => $name,
                'age_bracket' => is_string($guest['age_bracket'] ?? null) ? $guest['age_bracket'] : null,
                'experience_level' => is_string($guest['experience_level'] ?? null) ? $guest['experience_level'] : null,
            ]);
            $ids[] = $rider->id;
        }

        $this->riderIds = array_values(array_unique($ids));
        $this->step = 'review';
    }

    /** @param  array<mixed>  $draft */
    private function restoreGuestDraft(array $draft): void
    {
        $this->productSlug = is_string($draft['product'] ?? null) ? $draft['product'] : '';
        $this->slotId = is_int($draft['slot'] ?? null) ? $draft['slot'] : null;
        $this->later = (bool) ($draft['later'] ?? false);

        $request = $draft['request'] ?? null;

        if (is_array($request)) {
            $this->requesting = true;
            $this->requestDate = is_string($request['date'] ?? null) ? $request['date'] : '';
            $this->requestTime = is_string($request['time'] ?? null) ? $request['time'] : '';
            $this->requestNote = is_string($request['note'] ?? null) ? $request['note'] : '';
        }

        $riders = [];

        foreach ((array) ($draft['riders'] ?? []) as $guest) {
            if (is_array($guest) && is_string($guest['name'] ?? null)) {
                $riders[] = [
                    'name' => $guest['name'],
                    'age_bracket' => is_string($guest['age_bracket'] ?? null) ? $guest['age_bracket'] : '',
                    'experience_level' => is_string($guest['experience_level'] ?? null) ? $guest['experience_level'] : '',
                ];
            }
        }

        $this->guestRiders = $riders;
        $this->riderCount = max(1, count($riders));
        // normaliseStep() sends a slot that filled up back to the slot step.
        $this->step = 'review';
    }
}
