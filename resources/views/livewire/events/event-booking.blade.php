{{-- Event booking form (decided 2026-11-22). One page; the server checks everything again. --}}
@php
    use App\Enums\EventQuestionType;
    use App\Support\Money;
    $tierNames = $tiers->pluck('name', 'id');
    $count = count($people);
    $min = $event->min_per_booking;
    $max = $event->maxPerBooking();
@endphp

<div class="flex flex-col gap-12">
    <div aria-live="polite" class="empty:hidden">
        @if ($error)
            <x-alert variant="error" title="That didn’t work.">{{ $error }}</x-alert>
        @endif
    </div>

    {{-- 1. Date --}}
    <section aria-labelledby="event-date" class="flex flex-col gap-5">
        <h2 id="event-date" class="text-display-md">1. Choose a date.</h2>
        @if ($dates->isEmpty())
            <p class="text-sand-dim">No dates are taking bookings right now. <a href="{{ route('events.show', $event) }}" class="underline underline-offset-4">Back to the event</a>.</p>
        @else
            <fieldset>
                <legend class="sr-only">Date (Pakistan time)</legend>
                <ul class="flex flex-col gap-3" role="list">
                    @foreach ($dates as $slot)
                        @php($full = $slot->seatsFree() === 0)
                        <li wire:key="date-{{ $slot->id }}">
                            <label @class([
                                'flex min-h-11 cursor-pointer flex-wrap items-center justify-between gap-3 rounded-sm border bg-earth-2 p-5',
                                'border-sand' => $date === $slot->id,
                                'border-line-strong hover:border-sand' => $date !== $slot->id,
                                'cursor-not-allowed opacity-60' => $full,
                            ])>
                                <span class="flex items-center gap-3">
                                    <input type="radio" name="date" value="{{ $slot->id }}" wire:click="choose({{ $slot->id }})" @checked($date === $slot->id) @disabled($full) class="size-5 accent-sand">
                                    <span class="font-semibold"><time datetime="{{ $slot->starts_at->toIso8601String() }}">{{ $slot->dateRangeLabel() }}</time></span>
                                </span>
                                <x-seats-left :seats="$slot->seatsFree()" :threshold="$threshold" />
                            </label>
                        </li>
                    @endforeach
                </ul>
            </fieldset>
        @endif
    </section>

    {{-- 2. How many --}}
    <section aria-labelledby="event-people" class="flex flex-col gap-5">
        <h2 id="event-people" class="text-display-md">2. How many people?</h2>
        <ul class="flex flex-col gap-3" role="list">
            @foreach ($tiers as $tier)
                @php($q = (int) ($quantities[$tier->id] ?? 0))
                <li wire:key="tier-{{ $tier->id }}" class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5">
                    <p class="flex flex-col">
                        <span class="font-semibold">{{ $tier->name }}</span>
                        <span class="text-sm text-sand-dim"><x-money :minor="$tier->price_minor" /> per person</span>
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="remove({{ $tier->id }})" @disabled($q === 0) class="inline-flex size-11 items-center justify-center rounded-sm border border-line-strong text-lede hover:border-sand disabled:opacity-60" aria-label="One {{ $tier->name }} fewer">−</button>
                        <span class="w-10 text-center font-semibold" aria-live="polite"><span class="sr-only">{{ $tier->name }}: </span>{{ $q }}</span>
                        <button type="button" wire:click="add({{ $tier->id }})" @disabled($count >= $max) class="inline-flex size-11 items-center justify-center rounded-sm border border-line-strong text-lede hover:border-sand disabled:opacity-60" aria-label="One {{ $tier->name }} more">+</button>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="text-sm text-sand-dim">{{ $min > 1 ? 'At least '.$min.', up to '.$max.' people per booking.' : 'Up to '.$max.' people per booking.' }}</p>
    </section>

    {{-- 3. Names and answers --}}
    @if ($count > 0)
        <section aria-labelledby="event-names" class="flex flex-col gap-5">
            <h2 id="event-names" class="text-display-md">3. Who’s coming?</h2>
            <p class="text-sm text-sand-dim">We need each person’s name for the run sheet.</p>
            @foreach ($people as $i => $person)
                <fieldset wire:key="person-{{ $i }}" class="flex flex-col gap-4 rounded-sm border border-line bg-earth-2 p-5">
                    <legend class="px-1 font-semibold">Person {{ $i + 1 }}{{ $tiers->count() > 1 ? ' ('.($tierNames[$person['tier_id']] ?? '').')' : '' }}</legend>
                    <x-form.field :name="'people.'.$i.'.name'" label="Full name" required maxlength="120" autocomplete="off" wire:model.blur="people.{{ $i }}.name" />
                    @foreach ($personQuestions as $question)
                        @include('livewire.events.question', ['question' => $question, 'model' => 'people.'.$i.'.answers.'.$question['key'], 'id' => 'q-'.$i.'-'.$question['key']])
                    @endforeach
                </fieldset>
            @endforeach
        </section>
    @endif

    @if ($bookingQuestions !== [] || $event->acknowledgement_text)
        <section aria-labelledby="event-more" class="flex flex-col gap-5">
            <h2 id="event-more" class="text-display-md">4. A few more things.</h2>
            @foreach ($bookingQuestions as $question)
                @include('livewire.events.question', ['question' => $question, 'model' => 'answers.'.$question['key'], 'id' => 'q-booking-'.$question['key']])
            @endforeach
            @if ($event->acknowledgement_text)
                <label class="flex items-start gap-3 rounded-sm border border-line-strong bg-earth-2 p-5">
                    <input type="checkbox" wire:model="acknowledged" required class="mt-1 size-5 shrink-0 accent-sand">
                    <span><span class="font-semibold">{{ $event->acknowledgement_text }}</span> <span class="text-sand-dim">(required)</span></span>
                </label>
            @endif
        </section>
    @endif

    {{-- Summary --}}
    <section aria-labelledby="event-summary" class="flex flex-col gap-5 rounded-sm border border-line-strong bg-earth-2 p-7">
        <h2 id="event-summary" class="text-display-md">Your booking.</h2>
        <dl class="flex flex-col gap-2">
            <div class="flex justify-between gap-4"><dt>{{ $count }} {{ $count === 1 ? 'person' : 'people' }}</dt><dd class="font-semibold">{{ Money::format($subtotal) }}</dd></div>
            @if ($balance > 0)
                <div class="flex justify-between gap-4"><dt>Deposit to pay now (non-refundable)</dt><dd class="font-semibold">{{ Money::format($payNow) }}</dd></div>
                <div class="flex justify-between gap-4 text-sand-dim"><dt>Balance, {{ $event->balance_due === \App\Enums\BalanceDue::OnTheDay ? 'on the day or online before' : 'online before the deadline' }}</dt><dd>{{ Money::format($balance) }}</dd></div>
            @endif
        </dl>
        <ul class="flex list-disc flex-col gap-1 ps-5 text-sm text-sand-dim">
            @foreach ($terms as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <p class="text-sm text-sand-dim">Gift vouchers and promo codes can be added at checkout.</p>

        @if ($user)
            <fieldset class="flex flex-col gap-4">
                <legend class="mb-2 font-semibold">How we reach you</legend>
                <p class="text-sm text-sand-dim">Email: <span class="font-semibold text-sand">{{ $user->email }}</span>.</p>
                @if ($user->phone)
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="whatsappIsPhone" class="mt-1 size-5 shrink-0 accent-sand">
                        <span>This is also my WhatsApp: <span class="font-semibold">{{ $user->phone }}</span></span>
                    </label>
                @endif
                @unless ($user->phone && $whatsappIsPhone)
                    <x-form.field name="whatsapp" label="WhatsApp number" type="tel" required autocomplete="tel" maxlength="32" wire:model="whatsapp"
                        hint="Pakistani numbers like 0334 4554500, others with the country code (+44 …)." />
                @endunless
            </fieldset>
            <div class="flex flex-col gap-3">
                <div><x-button wire:click="book" wire:loading.attr="disabled">Continue to payment</x-button></div>
                <p class="text-sm text-sand-dim">We hold your places for {{ $holdMinutes }} minutes while you pay.</p>
            </div>
        @else
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap gap-3">
                    <x-button wire:click="continueAsGuest" wire:loading.attr="disabled">Continue as guest</x-button>
                    <x-button variant="ghost" wire:click="signInToContinue">Sign in instead</x-button>
                </div>
                <p class="text-sm text-sand-dim">No account needed: next you give your name, email and WhatsApp number, and we email your tickets with a link to manage the booking.</p>
            </div>
        @endif
    </section>
</div>
