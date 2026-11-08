{{-- /vouchers/{code} (signed): the printable gift voucher from the recipient's email. --}}
@php
    use App\Enums\VoucherStatus;
    $tz = config('app.display_timezone');
    $from = $voucher->voucherOrder?->user->name;
    $message = $voucher->voucherOrder?->message;
    $usable = $voucher->isUsable();
@endphp

<x-layouts.app title="Gift voucher" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="voucher-title">
        <article class="flex flex-col gap-8 rounded-sm border border-sand p-8 md:p-12 print:border-earth">
            <header class="flex flex-col gap-3">
                <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Ride Pakistan · Islamabad</p>
                <h1 id="voucher-title" class="text-display-xl">Gift voucher.</h1>
                @if ($voucher->recipient_name)
                    <p class="text-lede">For {{ $voucher->recipient_name }}@if ($from), from {{ $from }}@endif</p>
                @endif
            </header>

            @if ($message)
                <blockquote class="border-l-2 border-sand pl-5 text-lede text-sand-dim">{{ $message }}</blockquote>
            @endif

            <dl class="grid gap-6 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-sand-dim">Value</dt>
                    <dd class="font-display text-display-md font-black"><x-money :minor="$voucher->initial_minor" :currency="$voucher->currency" /></dd>
                    @if ($voucher->label)<dd class="text-sm text-sand-dim">{{ $voucher->label }}</dd>@endif
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Code</dt>
                    <dd class="font-display text-display-md font-black tracking-label select-all">{{ $voucher->formattedCode() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Valid until</dt>
                    <dd class="font-semibold"><time datetime="{{ $voucher->expires_at->toIso8601String() }}">{{ $voucher->expires_at->setTimezone($tz)->format('j F Y') }}</time></dd>
                </div>
                <div>
                    <dt class="text-sm text-sand-dim">Left to spend</dt>
                    <dd class="font-semibold"><x-money :minor="$voucher->balance_minor" :currency="$voucher->currency" />@unless ($usable) · {{ $voucher->status === VoucherStatus::Active ? 'Expired' : $voucher->status->label() }}@endunless</dd>
                </div>
            </dl>

            <p class="text-sm text-sand-dim">Use the code when you book any session or package at {{ preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/')) }}. Anything not spent stays on the voucher until it expires. Not exchangeable for cash.</p>
        </article>

        <div class="mt-8 flex flex-wrap gap-3 print:hidden">
            <x-button :href="route('prices')">See sessions and prices</x-button>
        </div>
    </section>
</x-layouts.app>
