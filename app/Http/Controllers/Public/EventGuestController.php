<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Account\SaveWhatsAppNumber;
use App\Actions\Account\UpdateProfile;
use App\Actions\Events\PlaceGuestEventOrder;
use App\Exceptions\BookingRejected;
use App\Exceptions\GuestCheckoutRejected;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureLeadReference;
use App\Livewire\Events\EventBooking;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Support\Events\EventAvailability;
use App\Support\Events\EventMoney;
use App\Support\GuestAccess;
use App\Support\Turnstile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * /events/{slug}/details (decided 2026-11-22): a signed-out visitor's last
 * step before paying for an event, the guest checkout rules of 2026-11-12
 * (GuestBookingController): name, email twice, WhatsApp, Turnstile, the
 * guest-checkout limits and the per-email limit shared with lessons, one
 * neutral message for any refusal about the email. A draft changed in
 * another tab goes back to the booking form.
 */
final class EventGuestController extends Controller
{
    public function show(Request $request, RideEvent $event, Turnstile $turnstile): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('events.book', ['event' => $event->slug, 'resume' => 1]);
        }

        $draft = $this->draft($request, $event);

        if ($draft === null) {
            return redirect()->route('events.book', ['event' => $event->slug]);
        }

        return view('public.events.details', [
            ...$draft,
            'event' => $event,
            'draftHash' => $this->hash($request),
            'turnstileSiteKey' => $turnstile->siteKey(),
        ]);
    }

    public function store(Request $request, RideEvent $event, Turnstile $turnstile, PlaceGuestEventOrder $place, GuestAccess $guests): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('events.book', ['event' => $event->slug, 'resume' => 1]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'confirmed'],
            'whatsapp' => SaveWhatsAppNumber::rules(),
            'draft' => ['required', 'string'],
        ], [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'email.confirmed' => 'The two email addresses don’t match.',
            'whatsapp.required' => 'Enter your WhatsApp number so we can reach you about your booking.',
            'whatsapp' => SaveWhatsAppNumber::MESSAGE,
        ]);

        $draft = $this->draft($request, $event);
        $back = ['event' => $event->slug, 'resume' => 1];

        if ($draft === null || ! hash_equals($this->hash($request), (string) $data['draft'])) {
            return redirect()->route('events.book', $back)->with('booking_notice', 'Your booking changed in another tab. Please check it again.');
        }

        try {
            if (! $turnstile->verify($request->string(Turnstile::RESPONSE_FIELD)->toString(), $request->ip())) {
                throw GuestCheckoutRejected::verification();
            }

            // Shared with lesson bookings and gift vouchers (GuestBookingController).
            $emailKey = 'guest-checkout-email:'.hash('sha256', Str::lower(trim((string) $data['email'])));

            if (RateLimiter::tooManyAttempts($emailKey, GuestBookingController::EMAIL_LIMIT_PER_HOUR)) {
                throw GuestCheckoutRejected::tooMany();
            }

            RateLimiter::hit($emailKey, 3600);

            $leadRef = $request->session()->get(CaptureLeadReference::SESSION_KEY);
            $order = $place->handle(
                ['name' => (string) $data['name'], 'email' => (string) $data['email'], 'whatsapp' => (string) UpdateProfile::toE164((string) $data['whatsapp'])],
                $event,
                $draft['slot']->id,
                $draft['people'],
                $draft['answers'],
                $draft['acknowledged'],
                is_int($leadRef) ? $leadRef : null,
            );
        } catch (GuestCheckoutRejected $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput($request->except('draft'));
        } catch (BookingRejected $e) {
            return redirect()->route('events.book', $back)->with('booking_notice', $e->getMessage());
        }

        $guests->grantOrder($order);
        $request->session()->forget(EventBooking::DRAFT_KEY);

        return redirect()->route('checkout.show', $order->reference)->with('guest_email', $order->user->email);
    }

    /**
     * The draft re-checked against the database: a published event, one of
     * its dates still taking bookings, and named people.
     *
     * @return array{slot: Slot, people: list<array{tier_id: int, name: string, answers: array<string, string>}>, answers: array<string, string>, acknowledged: bool, subtotal: int, payNow: int, balance: int}|null
     */
    private function draft(Request $request, RideEvent $event): ?array
    {
        $draft = $request->session()->get(EventBooking::DRAFT_KEY);

        if (! $event->isPublished() || ! is_array($draft) || ($draft['event'] ?? null) !== $event->slug) {
            return null;
        }

        $slot = EventAvailability::for($event)->bookable()->firstWhere('id', $draft['date'] ?? null);
        $tiers = $event->tiers()->get()->keyBy('id');
        $people = [];
        $subtotal = 0;

        foreach ((array) ($draft['people'] ?? []) as $person) {
            $tier = is_array($person) ? $tiers->get((int) ($person['tier_id'] ?? 0)) : null;
            $name = is_array($person) && is_string($person['name'] ?? null) ? trim($person['name']) : '';

            if ($tier === null || $name === '') {
                return null;
            }

            $people[] = ['tier_id' => $tier->id, 'name' => $name, 'answers' => array_map('strval', (array) ($person['answers'] ?? []))];
            $subtotal += $tier->price_minor;
        }

        if (! $slot instanceof Slot || $people === []) {
            return null;
        }

        $split = EventMoney::split($subtotal, $event->depositPercent());

        return [
            'slot' => $slot,
            'people' => $people,
            'answers' => array_map('strval', (array) ($draft['answers'] ?? [])),
            'acknowledged' => (bool) ($draft['acknowledged'] ?? false),
            'subtotal' => $subtotal,
            'payNow' => $split['now'],
            'balance' => $split['balance'],
        ];
    }

    private function hash(Request $request): string
    {
        return hash_hmac('sha256', (string) json_encode($request->session()->get(EventBooking::DRAFT_KEY)), (string) config('app.key'));
    }
}
