@php
    $labels = ['product' => 'Session', 'slot' => 'Date', 'riders' => 'Riders', 'review' => 'Review'];
    $current = array_search($step, array_keys($labels), true);
    $isPackage = $product?->type === \App\Enums\ProductType::Package;
    $isPrivate = $product?->type === \App\Enums\ProductType::Private;
@endphp

<div class="flex flex-col gap-10">
    {{-- Progress --}}
    <nav aria-label="Booking steps">
        <ol class="flex flex-wrap gap-2 text-sm">
            @foreach ($labels as $key => $label)
                @php($index = array_search($key, array_keys($labels), true))
                <li>
                    @if ($index < $current)
                        <button type="button" wire:click="goTo('{{ $key }}')" class="inline-flex min-h-11 items-center gap-2 rounded-sm border border-line-strong px-3 py-2 text-sand hover:border-sand">
                            <span class="font-semibold">{{ $index + 1 }}.</span> {{ $label }}<span class="sr-only"> (done, change)</span>
                        </button>
                    @else
                        <span @if ($index === $current) aria-current="step" @endif @class([
                            'inline-flex min-h-11 items-center gap-2 rounded-sm border px-3 py-2',
                            'border-sand bg-sand text-earth' => $index === $current,
                            'border-line text-sand-dim' => $index > $current,
                        ])>
                            <span class="font-semibold">{{ $index + 1 }}.</span> {{ $label }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <div aria-live="polite" class="empty:hidden">
        @if ($error)
            <x-alert variant="error" title="That didn’t work.">
                {{ $error }}
                @if ($waitlistSlotId)
                    <a href="{{ route('account.waitlist.create', ['slot' => $waitlistSlotId, 'riders' => $riderCount]) }}" class="underline underline-offset-4">Join the waitlist for that session</a>.
                @endif
            </x-alert>
        @endif
    </div>

    {{-- 1. Product --}}
    @if ($step === 'product')
        <section aria-labelledby="step-product" class="flex flex-col gap-6">
            <h2 id="step-product" class="text-display-md">Choose your session.</h2>
            <ul class="grid gap-4 md:grid-cols-2">
                @foreach ($products as $item)
                    <li wire:key="product-{{ $item->id }}">
                        <button type="button" wire:click="chooseProduct('{{ $item->slug }}')" class="flex h-full w-full flex-col gap-2 rounded-sm border border-line-strong bg-earth-2 p-6 text-left hover:border-sand">
                            <span class="font-display text-display-xs font-extrabold">{{ $item->name }}</span>
                            <span><x-money :minor="$item->price_minor" class="font-semibold" /> <span class="text-sm text-sand-dim">{{ $item->isPackage() ? 'for '.$item->sessions_count.' sessions, valid '.$item->validityWeeks().' weeks' : 'per rider' }}</span></span>
                            <span class="text-sm text-sand-dim">{{ $item->short_description }}</span>
                        </button>
                    </li>
                @endforeach
                @if ($packs->isNotEmpty())
                    <li>
                        <button type="button" wire:click="chooseProduct('credits')" class="flex h-full w-full flex-col gap-2 rounded-sm border border-sand bg-earth-2 p-6 text-left hover:border-sand">
                            <span class="font-display text-display-xs font-extrabold">Use my credits</span>
                            <span class="font-semibold">{{ $packs->sum(fn ($p) => $p->creditsRemaining()) }} left</span>
                            <span class="text-sm text-sand-dim">Book group sessions with your package. Use by {{ $packs->first()->expires_at->setTimezone(config('app.display_timezone'))->format('j F Y') }}.</span>
                        </button>
                    </li>
                @endif
            </ul>
        </section>
    @endif

    {{-- 2. Slot --}}
    @if ($step === 'slot')
        <section aria-labelledby="step-slot" class="flex flex-col gap-6">
            <h2 id="step-slot" class="text-display-md">{{ $isPackage ? 'Book your first session?' : 'Choose a date.' }}</h2>
            <p class="text-sand-dim">Weekend slots, Pakistan time.{{ $isPrivate ? ' Showing slots nobody has booked yet, as a private session takes the whole slot.' : '' }}</p>

            @if ($isPackage)
                <div>
                    <x-button wire:click="chooseLater" variant="ghost">I’ll choose my dates later</x-button>
                </div>
            @endif

            @forelse ($slotOptions->groupBy(fn ($s) => $s->localStartsAt()->format('Y-m-d')) as $date => $daySlots)
                <div wire:key="day-{{ $date }}" class="flex flex-col gap-3">
                    <h3 class="font-display text-display-xs font-extrabold">{{ \Carbon\CarbonImmutable::parse($date)->format('l j F') }}</h3>
                    <ul class="grid gap-3 sm:grid-cols-2">
                        @foreach ($daySlots as $item)
                            <li wire:key="slot-{{ $item->id }}">
                                <button type="button" wire:click="chooseSlot({{ $item->id }})" class="flex w-full flex-wrap items-center justify-between gap-3 rounded-sm border border-line-strong bg-earth-2 p-4 text-left hover:border-sand">
                                    <time datetime="{{ $item->starts_at->toIso8601String() }}" class="font-semibold">{{ \App\Support\BusinessFacts::timeRange($item->localStartsAt()->format('H:i'), $item->localEndsAt()->format('H:i')) }}</time>
                                    @if ($isPrivate)
                                        <span class="text-sm text-sand-dim">Whole slot free</span>
                                    @else
                                        <x-seats-left :seats="$item->seatsFree()" />
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sand-dim">No slots are open for this in the next few weeks. <a href="{{ route('contact') }}" class="underline underline-offset-4">Message us</a> and we’ll help.</p>
            @endforelse
        </section>
    @endif

    {{-- 3. Riders --}}
    @if ($step === 'riders')
        <section aria-labelledby="step-riders" class="flex flex-col gap-6">
            <h2 id="step-riders" class="text-display-md">Who’s riding?</h2>
            @if ($chosenSlot)
                <p class="text-sand-dim">{{ $chosenSlot->label() }} · up to {{ $maxRiders }} {{ $maxRiders === 1 ? 'rider' : 'riders' }}</p>
            @endif

            @if ($user)
                @error('riderIds')
                    <x-alert variant="error">{{ $message }}</x-alert>
                @enderror

                @if ($riders->isNotEmpty())
                    <fieldset class="flex flex-col gap-3">
                        <legend class="mb-2 text-sm font-semibold">Your riders</legend>
                        @foreach ($riders as $rider)
                            <label wire:key="rider-{{ $rider->id }}" class="flex cursor-pointer items-center gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                                <input type="checkbox" value="{{ $rider->id }}" wire:model="riderIds" class="size-5 accent-sand">
                                <span class="font-semibold">{{ $rider->name }}</span>
                                @if ($rider->age_bracket)<span class="text-sm text-sand-dim">{{ $rider->age_bracket->label() }}</span>@endif
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <form wire:submit="addRider" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <x-form.field name="newRiderName" label="Add a rider" autocomplete="off" maxlength="120" wire:model="newRiderName" hint="Name only for now; add details on your Riders page." />
                    </div>
                    <x-button type="submit" variant="ghost">Add</x-button>
                </form>
            @else
                <p class="text-sand-dim">You’ll sign in with your email before paying; we’ll save these riders to your account.</p>
                @foreach ($guestRiders as $i => $guest)
                    <fieldset wire:key="guest-{{ $i }}" class="flex flex-col gap-4 rounded-sm border border-line-strong p-5">
                        <legend class="px-1 text-sm font-semibold">Rider {{ $i + 1 }}</legend>
                        <x-form.field :name="'guestRiders.'.$i.'.name'" label="Name" required autocomplete="off" maxlength="120" wire:model="guestRiders.{{ $i }}.name" />
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-form.field :name="'guestRiders.'.$i.'.age_bracket'" label="Age" type="select" wire:model="guestRiders.{{ $i }}.age_bracket">
                                <option value="">Choose one</option>
                                @foreach ($ageBrackets as $bracket)
                                    <option value="{{ $bracket->value }}">{{ $bracket->label() }}</option>
                                @endforeach
                            </x-form.field>
                            <x-form.field :name="'guestRiders.'.$i.'.experience_level'" label="Riding experience" type="select" wire:model="guestRiders.{{ $i }}.experience_level">
                                <option value="">Choose one</option>
                                @foreach ($experienceLevels as $level)
                                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                                @endforeach
                            </x-form.field>
                        </div>
                        @if (count($guestRiders) > 1)
                            <div><x-button wire:click="removeGuestRider({{ $i }})" variant="ghost" size="sm">Remove rider {{ $i + 1 }}</x-button></div>
                        @endif
                    </fieldset>
                @endforeach
                @error('guestRiders')
                    <x-alert variant="error">{{ $message }}</x-alert>
                @enderror
                @if (count($guestRiders) < $maxRiders)
                    <div><x-button wire:click="addGuestRider" variant="ghost" size="sm">Add another rider</x-button></div>
                @endif
            @endif

            <div>
                <x-button wire:click="confirmRiders">Continue</x-button>
            </div>
        </section>
    @endif

    {{-- 4. Review --}}
    @if ($step === 'review')
        <section aria-labelledby="step-review" class="flex flex-col gap-6">
            <h2 id="step-review" class="text-display-md">Check and confirm.</h2>

            <dl class="grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-sand-dim">Session</dt>
                    <dd class="font-semibold">{{ $usingCredits ? 'Group session with credits' : $product?->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">When (Pakistan time)</dt>
                    <dd class="font-semibold">{{ $chosenSlot ? $chosenSlot->label() : 'You’ll choose your dates later' }}</dd>
                </div>
                @if (! $later)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-sand-dim">Riders</dt>
                        <dd class="font-semibold">
                            {{ $user ? $riders->whereIn('id', $riderIds)->pluck('name')->join(', ') : collect($guestRiders)->pluck('name')->join(', ') }}
                        </dd>
                    </div>
                @endif
                <div class="border-t border-line pt-4 sm:col-span-2">
                    <dt class="text-sm text-sand-dim">{{ $usingCredits ? 'Credits' : 'Total' }}</dt>
                    <dd class="font-display text-display-md font-black">
                        @if ($usingCredits)
                            {{ $creditsNeeded }} {{ $creditsNeeded === 1 ? 'credit' : 'credits' }}
                        @elseif ($quote)
                            <x-money :minor="$quote->totalMinor" />
                            @if ($quote->perRider && $quote->quantity > 1)
                                <span class="block text-sm font-normal text-sand-dim">{{ $quote->quantity }} × <x-money :minor="$quote->unitMinor" /></span>
                            @endif
                            @if ($isPackage && ! $later)
                                <span class="block text-sm font-normal text-sand-dim">Your first session uses {{ count($riderIds) ?: count($guestRiders) }} of the package’s {{ $product->sessions_count }} credits.</span>
                            @endif
                            <span class="block text-sm font-normal text-sand-dim">Gift vouchers, promo codes and account credit are applied at checkout.</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($user)
                <div class="flex flex-col gap-3">
                    <div>
                        <x-button wire:click="placeOrder" wire:loading.attr="disabled">{{ $usingCredits ? 'Book with my credits' : 'Continue to payment' }}</x-button>
                    </div>
                    @unless ($usingCredits)
                        <p class="text-sm text-sand-dim">{{ $chosenSlot ? 'We hold your '.($isPrivate ? 'slot' : 'seats').' for '.$holdMinutes.' minutes while you pay.' : 'Credits are added to your account once payment is confirmed.' }}</p>
                    @endunless
                </div>
            @else
                <div class="flex flex-col gap-3">
                    <div>
                        <x-button wire:click="signInToContinue">Sign in to continue</x-button>
                    </div>
                    <p class="text-sm text-sand-dim">We’ll email you a code. New here? Your account is created when you enter it, and you come straight back to this booking.</p>
                </div>
            @endif
        </section>
    @endif
</div>
