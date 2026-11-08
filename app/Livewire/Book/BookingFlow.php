<?php

declare(strict_types=1);

namespace App\Livewire\Book;

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\PriceQuote;
use App\Actions\Riders\SaveRider;
use App\Enums\AgeBracket;
use App\Enums\CreditPackStatus;
use App\Enums\ExperienceLevel;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\CreditPack;
use App\Models\Product;
use App\Models\Slot;
use App\Models\User;
use App\Support\Availability;
use App\Support\BusinessFacts;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /book (PLAN §6): product → slot → riders → review → order → checkout.
 *
 * The component only ever holds ids, counts and rider details. Prices are
 * looked up from the product row on every render (PriceQuote) and again
 * by CreateOrder, so nothing the browser sends can change an amount.
 * Guests fill in rider details and sign in with a code at the review
 * step; the draft waits in their session and resumes at /book?resume=1.
 * Customers with package credits can book straight away ("use credits").
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

    public ?string $error = null;

    public function mount(): void
    {
        $riders = request()->query('riders');
        $this->riderCount = max(1, min(is_numeric($riders) ? (int) $riders : 1, $this->maxRiders()));

        if (request()->boolean('resume')) {
            $this->resume();
        }

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
        session()->put(self::DRAFT_KEY, [
            'product' => $this->productSlug,
            'use' => $this->use,
            'slot' => $this->slotId,
            'later' => $this->later,
            'riders' => $this->guestRiders,
        ]);

        redirect()->setIntendedUrl(route('book', ['resume' => 1]));

        return $this->redirectRoute('login');
    }

    public function placeOrder(CreateOrder $createOrder, BookWithCredit $bookWithCredit): mixed
    {
        $user = $this->user();

        if ($user === null) {
            return $this->signInToContinue();
        }

        $this->error = null;

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

            $order = $createOrder->handle($user, $product, $this->later ? null : $this->slotId, $this->later ? [] : $this->riderIds);
        } catch (BookingRejected $e) {
            $this->error = $e->getMessage();

            if (in_array($e->reason, [BookingRejected::SLOT_FULL, BookingRejected::SLOT_NOT_BOOKABLE, BookingRejected::SLOT_NOT_EMPTY], true)) {
                $this->slotId = null;
                $this->step = 'slot';
            }

            return null;
        }

        return $this->redirectRoute('checkout.show', ['reference' => $order->reference]);
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
        ]);
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

    private function findProduct(string $slug): ?Product
    {
        return Product::query()->where('slug', $slug)->where('is_active', true)->first();
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

        return $slot->seatsFree() > 0 ? $slot : null;
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
            return $this->selectedSlot()->capacity ?? $perSlot;
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

        if ($this->step === 'product' && $this->slotId !== null && $this->selectedSlot() !== null) {
            $this->step = 'riders';
        } elseif ($this->step === 'product') {
            $this->step = 'slot';
        }

        if (in_array($this->step, ['riders', 'review'], true) && ! $this->later && $this->selectedSlot() === null) {
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

    /** Back from sign-in: save the guest riders and pick up where we left off. */
    private function resume(): void
    {
        $draft = session()->pull(self::DRAFT_KEY);
        $user = $this->user();

        if (! is_array($draft) || $user === null) {
            return;
        }

        $this->productSlug = is_string($draft['product'] ?? null) ? $draft['product'] : '';
        $this->use = ($draft['use'] ?? '') === self::CREDITS ? self::CREDITS : '';
        $this->slotId = is_int($draft['slot'] ?? null) ? $draft['slot'] : null;
        $this->later = (bool) ($draft['later'] ?? false);

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
}
