<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Account\SaveWhatsAppNumber;
use App\Actions\Account\UpdateProfile;
use App\Actions\Booking\PriceQuote;
use App\Actions\Guest\PlaceGuestOrder;
use App\Actions\Guest\SubmitGuestPrivateRequest;
use App\Actions\PrivateRequests\PrivateRequestRules;
use App\Enums\ProductType;
use App\Exceptions\BookingRejected;
use App\Exceptions\GuestCheckoutRejected;
use App\Exceptions\PrivateRequestRejected;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureLeadReference;
use App\Livewire\Book\BookingFlow;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Slot;
use App\Support\Availability;
use App\Support\GuestAccess;
use App\Support\Turnstile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * /book/details (guest checkout, decided 2026-11-12): the last step of
 * /book for a signed-out visitor. The booking draft is in the session
 * (BookingFlow::continueAsGuest); this page asks for the name, email
 * (twice), WhatsApp number and the Turnstile check, then places the order
 * (PlaceGuestOrder → checkout) or sends the private request.
 *
 * Every refusal about the email is the same neutral message, and the
 * per-email limit answers like the per-IP one, so the page never tells
 * whether an account exists. A draft changed in another tab since the page
 * loaded is sent back to /book to check again.
 */
final class GuestBookingController extends Controller
{
    public const EMAIL_LIMIT_PER_HOUR = 5;

    public function show(Request $request, Turnstile $turnstile, Availability $availability, PrivateRequestRules $rules): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('book', ['resume' => 1]);
        }

        $draft = $this->draft($request, $availability);

        if ($draft === null) {
            return redirect()->route('book');
        }

        return view('public.book-details', [
            ...$draft,
            'draftHash' => $this->hash($request),
            'turnstileSiteKey' => $turnstile->siteKey(),
            'requestRules' => $rules,
        ]);
    }

    public function store(Request $request, Turnstile $turnstile, Availability $availability, PlaceGuestOrder $place, SubmitGuestPrivateRequest $submit, GuestAccess $guests): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('book', ['resume' => 1]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'confirmed'],
            'whatsapp' => SaveWhatsAppNumber::rules(),
            'note' => ['nullable', 'string', 'max:'.PrivateSessionRequest::NOTE_MAX_LENGTH],
            'draft' => ['required', 'string'],
        ], [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'email.confirmed' => 'The two email addresses don’t match.',
            'whatsapp.required' => 'Enter your WhatsApp number so we can reach you about your booking.',
            'whatsapp' => SaveWhatsAppNumber::MESSAGE,
            'note.max' => 'Keep the note under '.PrivateSessionRequest::NOTE_MAX_LENGTH.' characters, please.',
        ]);

        $draft = $this->draft($request, $availability);

        if ($draft === null || ! hash_equals($this->hash($request), (string) $data['draft'])) {
            return redirect()->route('book', $draft === null ? [] : ['resume' => 1])
                ->with('booking_notice', 'Your booking changed in another tab. Please check it again.');
        }

        try {
            if (! $turnstile->verify($request->string(Turnstile::RESPONSE_FIELD)->toString(), $request->ip())) {
                throw GuestCheckoutRejected::verification();
            }

            $emailKey = 'guest-checkout-email:'.hash('sha256', Str::lower(trim((string) $data['email'])));

            if (RateLimiter::tooManyAttempts($emailKey, self::EMAIL_LIMIT_PER_HOUR)) {
                throw GuestCheckoutRejected::tooMany();
            }

            RateLimiter::hit($emailKey, 3600);

            $contact = [
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'whatsapp' => (string) UpdateProfile::toE164((string) $data['whatsapp']),
            ];

            if ($draft['requestStart'] !== null) {
                $sent = $submit->handle($contact, $draft['product'], $draft['requestStart'], $draft['riders'], is_string($data['note'] ?? null) ? $data['note'] : null);

                if ($sent !== null) {
                    $guests->grantRequest($sent);
                }

                $request->session()->forget(BookingFlow::DRAFT_KEY);

                // The same answer whether or not it was sent (an open request on the account is told by email).
                return redirect()->route('book.request-sent')->with('request_email', $contact['email']);
            }

            $leadRef = $request->session()->get(CaptureLeadReference::SESSION_KEY);
            $order = $place->handle($contact, $draft['product'], $draft['later'] ? null : $draft['slot']?->id, $draft['later'] ? [] : $draft['riders'], is_int($leadRef) ? $leadRef : null);
        } catch (GuestCheckoutRejected|PrivateRequestRejected $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput($request->except('draft'));
        } catch (BookingRejected $e) {
            return redirect()->route('book', ['resume' => 1])->with('booking_notice', $e->getMessage());
        }

        $guests->grantOrder($order);
        $request->session()->forget(BookingFlow::DRAFT_KEY);

        return redirect()->route('checkout.show', $order->reference)->with('guest_email', $order->user->email);
    }

    public function requestSent(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('request_email');

        if (! is_string($email)) {
            return redirect()->route('book');
        }

        return view('public.request-sent', ['email' => $email]);
    }

    /**
     * The guest's draft, re-checked against the database: an active
     * product, a slot that can still be booked (or a package bought for
     * later, or a private request), and the typed riders. Null if any of it
     * is missing.
     *
     * @return array{product: Product, slot: Slot|null, later: bool, riders: list<array{name: string, age_bracket: string|null, experience_level: string|null}>, requestStart: CarbonImmutable|null, requestLabel: string|null, note: string, quote: PriceQuote}|null
     */
    private function draft(Request $request, Availability $availability): ?array
    {
        $draft = $request->session()->get(BookingFlow::DRAFT_KEY);

        if (! is_array($draft) || ($draft['use'] ?? '') !== '') {
            return null;
        }

        $product = Product::query()->where('slug', (string) ($draft['product'] ?? ''))->active()->first();

        if ($product === null) {
            return null;
        }

        $riders = [];

        foreach ((array) ($draft['riders'] ?? []) as $rider) {
            $name = is_array($rider) && is_string($rider['name'] ?? null) ? trim($rider['name']) : '';

            if ($name !== '') {
                $riders[] = [
                    'name' => $name,
                    'age_bracket' => is_string($rider['age_bracket'] ?? null) && $rider['age_bracket'] !== '' ? $rider['age_bracket'] : null,
                    'experience_level' => is_string($rider['experience_level'] ?? null) && $rider['experience_level'] !== '' ? $rider['experience_level'] : null,
                ];
            }
        }

        $later = (bool) ($draft['later'] ?? false) && $product->type === ProductType::Package;
        $requestStart = null;
        $requestLabel = null;
        $note = '';
        $slot = null;
        $request_ = $draft['request'] ?? null;

        if (is_array($request_) && $product->type === ProductType::Private) {
            $date = (string) ($request_['date'] ?? '');
            $time = (string) ($request_['time'] ?? '');

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                return null;
            }

            $tz = Slot::displayTimezone();
            $requestStart = CarbonImmutable::parse($date.' '.$time, $tz)->utc();
            $end = $requestStart->addMinutes(app(PrivateRequestRules::class)->durationMinutes());
            $requestLabel = $requestStart->setTimezone($tz)->format('D j M Y, H:i').'–'.$end->setTimezone($tz)->format('H:i');
            $note = is_string($request_['note'] ?? null) ? $request_['note'] : '';
        } elseif (! $later) {
            $slot = is_int($draft['slot'] ?? null) ? $availability->find($draft['slot']) : null;

            if ($slot === null) {
                return null;
            }
        }

        if (! $later && $riders === []) {
            return null;
        }

        return [
            'product' => $product,
            'slot' => $slot,
            'later' => $later,
            'riders' => $riders,
            'requestStart' => $requestStart,
            'requestLabel' => $requestLabel,
            'note' => $note,
            'quote' => PriceQuote::for($product, max(1, count($riders))),
        ];
    }

    /** Fingerprint of the session draft, so a draft changed in another tab is noticed. */
    private function hash(Request $request): string
    {
        return hash_hmac('sha256', (string) json_encode($request->session()->get(BookingFlow::DRAFT_KEY)), (string) config('app.key'));
    }
}
