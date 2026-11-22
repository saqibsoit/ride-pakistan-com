{{--
    /team/sessions/{slot}: mobile run sheet (milestone 10b). One card per rider:
    level, notes, emergency contact (tap to call), horse, payment status (no amounts),
    check-in and the session note. Plain forms, no scripts. Noindex, never cached.
--}}
@php
    use App\Actions\Booking\CheckInBooking;
    use App\Enums\BookingStatus;
    use App\Enums\RunSheetPayment;

    $tz = config('app.display_timezone');
    $flash = session('runsheet');
    $checkedIn = $bookings->where('status', BookingStatus::Attended)->count();
@endphp

<x-layouts.app title="Run sheet {{ $slot->localStartsAt()->format('D j M H:i') }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-8 pb-section" aria-labelledby="runsheet-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">
            <a href="{{ route('team.sessions.index') }}" class="underline underline-offset-4 hover:text-sand">Sessions</a> · Run sheet
        </p>
        @if ($slot->rideEvent)
            <p class="mt-2 font-semibold">{{ $slot->rideEvent->title }}</p>
        @endif
        <h1 id="runsheet-title" class="mt-2 text-display-lg">{{ $slot->localStartsAt()->format('D j M') }}, {{ $slot->localStartsAt()->format('H:i') }}–{{ $slot->localEndsAt()->format('H:i') }}</h1>

        <dl class="mt-6 grid grid-cols-3 gap-4 rounded-sm border border-line-strong bg-earth-2 p-5">
            <div>
                <dt class="text-sm text-sand-dim">Riders</dt>
                <dd class="text-lg font-semibold">{{ $bookings->count() }}/{{ $slot->capacity }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Checked in</dt>
                <dd class="text-lg font-semibold">{{ $checkedIn }}</dd>
            </div>
            <div>
                <dt class="text-sm text-sand-dim">Trainer</dt>
                <dd class="font-semibold">{{ $slot->trainer?->displayName() ?? 'Not set' }}</dd>
            </div>
        </dl>

        <x-weather-badge :session="$slot" detailed class="mt-4" />

        <p class="mt-4 text-sm">
            <a href="{{ route('team.sessions.show', [$slot, 'print' => 1]) }}" class="underline underline-offset-4 hover:text-sand-dim">Printable version</a>
            @if ($slot->rideEvent && $isAdmin)
                · <a href="{{ route('team.sessions.export', $slot) }}" class="underline underline-offset-4 hover:text-sand-dim">Download CSV</a>
            @endif
        </p>

        @if ($errors->any())
            <x-alert class="mt-6" variant="error" title="Not saved">{{ $errors->first() }}</x-alert>
        @endif

        @if ($bookings->isEmpty())
            <x-alert class="mt-8" variant="info" title="No riders yet">Nobody has booked this session.</x-alert>
        @endif

        <ol class="mt-8 flex flex-col gap-6" role="list">
            @foreach ($bookings as $booking)
                @php
                    $rider = $booking->rider;
                    $payment = RunSheetPayment::for($booking);
                    $state = $states[$booking->id]['state'];
                    $canNoShow = $states[$booking->id]['noShow'];
                    $mine = is_array($flash) && ($flash['booking'] ?? null) === $booking->id;
                    $phone = $rider->emergency_contact_phone;
                @endphp
                <li id="rider-{{ $booking->id }}" class="scroll-mt-header rounded-sm border border-line-strong bg-earth-2 p-5">
                    <article aria-labelledby="rider-{{ $booking->id }}-name" class="flex flex-col gap-5">
                        <header class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 id="rider-{{ $booking->id }}-name" class="text-display-xs">{{ $rider->name }}</h2>
                                <p class="mt-1 text-sm text-sand-dim">
                                    {{ $rider->age_bracket?->label() ?? 'Age not given' }} · {{ $rider->experience_level?->label() ?? 'Experience not given' }}
                                    @if ($booking->is_private) · Private @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <x-badge :variant="$payment->badge()">{{ $payment->label() }}</x-badge>
                                <x-badge :variant="$booking->status === BookingStatus::Attended ? 'success' : ($booking->status === BookingStatus::NoShow ? 'warning' : 'neutral')">{{ $booking->status->label() }}</x-badge>
                            </div>
                        </header>

                        @if ($mine)
                            <x-alert :variant="$flash['variant']">{{ $flash['message'] }}</x-alert>
                        @endif

                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-sm text-sand-dim">Notes for the trainer</dt>
                                <dd>{{ $rider->notes ?: 'None' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-sand-dim">Emergency contact</dt>
                                <dd>
                                    {{ $rider->emergency_contact_name ?: 'Not given' }}
                                    @if ($phone)
                                        <a href="tel:{{ $phone }}" class="mt-1 flex min-h-11 items-center font-semibold underline underline-offset-4 hover:text-sand-dim">
                                            Call {{ $phone }}<span class="sr-only"> ({{ $rider->name }}'s emergency contact)</span>
                                        </a>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-sand-dim">Booked by</dt>
                                <dd>
                                    {{ $booking->user->displayName() }}
                                    @php($whatsappLink = \App\Support\WhatsAppLink::to($booking->contactWhatsApp()))
                                    @if ($whatsappLink)
                                        <a href="{{ $whatsappLink }}" target="_blank" rel="noopener" class="mt-1 flex min-h-11 items-center font-semibold underline underline-offset-4 hover:text-sand-dim">
                                            WhatsApp {{ $booking->contactWhatsApp() }}<span class="sr-only"> ({{ $booking->user->displayName() }}, opens in a new tab)</span>
                                        </a>
                                    @else
                                        <span class="block text-sm text-sand-dim">No WhatsApp number yet</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($slot->rideEvent)
                            {{-- Event dates (decided 2026-11-22): tier, answers, contact, deposit and balance. --}}
                            @php($answers = \App\Actions\Events\ExportRunSheet::answersFor($booking))
                            @php($balance = $booking->order?->balance)
                            <dl class="grid gap-4 sm:grid-cols-2">
                                @if ($booking->orderItem?->tier)
                                    <div>
                                        <dt class="text-sm text-sand-dim">Ticket type</dt>
                                        <dd>{{ $booking->orderItem->tier->name }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-sm text-sand-dim">Contact</dt>
                                    <dd>{{ $booking->order?->reference }} · {{ $booking->user->email }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-sand-dim">Payment</dt>
                                    <dd>
                                        @if ($booking->order?->deposit_percent !== null)
                                            Deposit paid{{ $isAdmin ? ' ('.\App\Support\Money::format($booking->order->amount_minor).')' : '' }}.
                                        @else
                                            Paid in full.
                                        @endif
                                        @if ($balance)
                                            {{ $balance->status->getLabel() }}{{ $isAdmin ? ': '.\App\Support\Money::format($balance->amount_minor) : '' }}{{ $balance->method ? ' ('.$balance->method->getLabel().')' : '' }}.
                                        @endif
                                    </dd>
                                </div>
                                @foreach ($answers as $row)
                                    <div>
                                        <dt class="text-sm text-sand-dim">{{ $row['label'] }}</dt>
                                        <dd>{{ $row['answer'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if ($isAdmin && $balance?->isDue())
                                <form method="POST" action="{{ route('team.sessions.balance', [$slot, $booking]) }}" class="flex flex-col gap-3 rounded-sm border border-line p-4 sm:flex-row sm:items-end">
                                    @csrf
                                    <x-form.field type="select" name="method" id="balance-method-{{ $booking->id }}" label="Balance paid by" :error="''" required>
                                        @foreach (\App\Enums\BalanceMethod::manual() as $method)
                                            <option value="{{ $method->value }}">{{ $method->getLabel() }}</option>
                                        @endforeach
                                    </x-form.field>
                                    <x-form.field name="reference" id="balance-ref-{{ $booking->id }}" label="Reference (optional)" maxlength="100" :error="''" />
                                    <x-button type="submit" variant="ghost">Mark balance paid</x-button>
                                </form>
                            @endif
                        @endif

                        <form method="POST" action="{{ route('team.sessions.horse', [$slot, $booking]) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-form.field
                                type="select"
                                name="horse_id"
                                id="horse-{{ $booking->id }}"
                                label="Horse"
                                :error="''"
                                hint="{{ $booking->horse?->temperament_notes ? $booking->horse->name.': '.$booking->horse->temperament_notes : 'Limits and rest days are checked when you save.' }}"
                            >
                                <option value="">No horse</option>
                                @foreach ($horses as $horse)
                                    <option value="{{ $horse->id }}" @selected($booking->horse_id === $horse->id)>{{ $horse->name }} ({{ $horse->suitable_levels->map->label()->implode(', ') }})</option>
                                @endforeach
                                @if ($booking->horse && ! $horses->contains('id', $booking->horse_id))
                                    <option value="{{ $booking->horse_id }}" selected>{{ $booking->horse->name }} (not working)</option>
                                @endif
                            </x-form.field>
                            @foreach ($warnings[$booking->id] ?? [] as $warning)
                                <x-alert variant="warning">{{ $warning }}</x-alert>
                            @endforeach
                            <x-button variant="ghost" type="submit" class="w-full sm:w-auto">Save horse</x-button>
                        </form>

                        @if ($state === CheckInBooking::OK)
                            <div class="flex flex-col gap-3 sm:flex-row">
                                @if ($booking->status !== BookingStatus::Attended)
                                    <form method="POST" action="{{ route('team.sessions.check-in', [$slot, $booking]) }}" class="sm:flex-1">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ BookingStatus::Attended->value }}">
                                        <button type="submit" class="min-h-14 w-full rounded-sm border border-success bg-success px-6 text-display-xs font-black text-earth focus-visible:outline-offset-4">
                                            Check in<span class="sr-only"> {{ $rider->name }}</span>
                                        </button>
                                    </form>
                                @endif
                                @if ($canNoShow && $booking->status === BookingStatus::Confirmed)
                                    <form method="POST" action="{{ route('team.sessions.check-in', [$slot, $booking]) }}" class="sm:flex-1">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ BookingStatus::NoShow->value }}">
                                        <x-button variant="ghost" type="submit" class="w-full">No-show<span class="sr-only"> {{ $rider->name }}</span></x-button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <p class="text-sm text-sand-dim" role="note">
                                @if ($state === CheckInBooking::TOO_EARLY && $opensAt)
                                    Check-in opens at {{ $opensAt->setTimezone($tz)->format('g:i a') }} on the day.
                                @else
                                    {{ CheckInBooking::message($state) }}
                                @endif
                            </p>
                        @endif

                        <form method="POST" action="{{ route('team.sessions.note', [$slot, $booking]) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-form.field
                                type="textarea"
                                name="session_note"
                                id="note-{{ $booking->id }}"
                                label="Session note"
                                :error="''"
                                :value="$booking->session_note"
                                rows="3"
                                maxlength="{{ \App\Actions\Booking\WriteSessionNote::MAX_LENGTH }}"
                                hint="How it went and what to work on next. Kept in the rider's history."
                            />
                            <x-button variant="ghost" type="submit" class="w-full sm:w-auto">Save note</x-button>
                        </form>

                        <x-team.progress-form :session="$slot" :booking="$booking" />

                        <p class="text-sm">
                            <a href="{{ route('team.riders.show', $rider) }}" class="underline underline-offset-4 hover:text-sand-dim">History for {{ $rider->name }}</a>
                        </p>
                    </article>
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts.app>
