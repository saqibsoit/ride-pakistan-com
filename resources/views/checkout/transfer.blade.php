{{-- /checkout/{reference}/transfer: bank details, deadline and proof upload (PLAN §3.2). Same order and reference as card payment. --}}
@php
    use App\Enums\OrderStatus;
    use App\Enums\BankTransferStatus;

    $tz = config('app.display_timezone');
    $deadline = $order->expires_at;
    $slotItems = $order->items->whereNotNull('slot_id');
    $status = $order->status;
@endphp

<x-layouts.app title="Bank transfer {{ $order->reference }}" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="transfer-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Order {{ $order->reference }}</p>
        <h1 id="transfer-title" class="mt-3 text-display-xl">Pay by bank transfer.</h1>

        <div class="mt-10 flex flex-col gap-8">
            @if (session('transfer_error'))
                <x-alert variant="error" title="We couldn't take your upload.">{{ session('transfer_error') }}</x-alert>
            @endif

            @if ($status === OrderStatus::Paid || $status === OrderStatus::PartiallyRefunded || $status === OrderStatus::Refunded)
                <x-alert variant="success" title="Transfer confirmed. You're booked.">Your sessions and tickets are in your account.</x-alert>
                <x-button :href="route('account.orders.show', $order->reference)">See your order</x-button>
            @elseif ($status === OrderStatus::Rejected)
                <x-alert variant="error" title="We couldn't confirm this transfer.">
                    {{ $transfer?->rejection_reason }} The seats have been released. <a href="{{ route('book') }}" class="underline underline-offset-4">Book again</a>.
                </x-alert>
            @elseif ($status === OrderStatus::Expired || $status === OrderStatus::Cancelled)
                <x-alert variant="warning" title="This order has {{ strtolower($status->label()) }}.">
                    No transfer proof arrived before the deadline, so the seats were released. <a href="{{ route('book') }}" class="underline underline-offset-4">Book again</a>.
                </x-alert>
            @else
                @if (session('transfer_uploaded') || $status === OrderStatus::AwaitingVerification)
                    <x-alert variant="success" title="We've received your transfer proof.">
                        We'll check it and confirm shortly. Your {{ $slotItems->isNotEmpty() ? 'seats stay held' : 'order stays open' }} until then, and we'll email you once it's confirmed.
                    </x-alert>
                @elseif ($deadline)
                    <x-alert variant="info" title="Transfer by {{ $deadline->setTimezone($tz)->format('D j M, g:i a') }} (Pakistan time).">
                        Until then your {{ $slotItems->isNotEmpty() ? 'seats are' : 'order is' }} held. Upload the proof before the deadline, or the booking expires.
                    </x-alert>
                    {{-- Outside the alert's live region: a ticking countdown must not be announced. --}}
                    <p class="-mt-4 text-sm text-sand-dim" aria-hidden="true" x-data="countdown" data-deadline="{{ $deadline->toIso8601String() }}"><span x-cloak x-text="text"></span></p>
                @endif

                <section aria-labelledby="amount-title" class="grid gap-4 rounded-sm border border-line-strong bg-earth-2 p-6 sm:grid-cols-2">
                    <h2 id="amount-title" class="sr-only">What to transfer</h2>
                    <div>
                        <p class="text-sm text-sand-dim">Amount</p>
                        <p class="font-display text-display-md font-black"><x-money :minor="$order->amount_minor" :currency="$order->currency" /></p>
                    </div>
                    <div x-data="copyButton" data-value="{{ $order->reference }}" data-label="Payment reference">
                        <p class="text-sm text-sand-dim">Payment reference (use exactly this)</p>
                        <p class="flex items-center gap-3">
                            <span class="font-display text-display-md font-black select-all">{{ $order->reference }}</span>
                            <button type="button" x-cloak x-on:click="copy" class="min-h-11 rounded-sm border border-line-strong px-3 text-sm hover:border-sand" aria-label="Copy payment reference">
                                <span x-text="label">Copy</span>
                            </button>
                        </p>
                        <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                    </div>
                    <div class="sm:col-span-2 border-t border-line pt-4">
                        <p class="text-sm text-sand-dim">For</p>
                        <p class="font-semibold">{{ $order->productName() }}@if ($slotItems->isNotEmpty()), {{ $slotItems->first()->slot?->label() }} (Pakistan time), {{ $slotItems->map(fn ($i) => $i->rider?->name)->filter()->join(', ') }}@endif</p>
                    </div>
                </section>

                <section aria-labelledby="bank-title" class="flex flex-col gap-4">
                    <h2 id="bank-title" class="text-display-xs">Bank details</h2>
                    <dl class="divide-y divide-line rounded-sm border border-line-strong">
                        @foreach ($bank as $label => $value)
                            @continue($value === '')
                            <div class="flex flex-wrap items-center justify-between gap-3 p-4" x-data="copyButton" data-value="{{ $value }}" data-label="{{ $label }}">
                                <dt class="text-sm text-sand-dim">{{ $label }}</dt>
                                <dd class="flex items-center gap-3">
                                    <span class="font-semibold select-all break-all">{{ $value }}</span>
                                    <button type="button" x-cloak x-on:click="copy" class="min-h-11 rounded-sm border border-line-strong px-3 text-sm hover:border-sand" aria-label="Copy {{ $label }}">
                                        <span x-text="label">Copy</span>
                                    </button>
                                    <span class="sr-only" aria-live="polite" x-text="announcement"></span>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="text-sm text-sand-dim">Transfer the exact amount and put <strong class="text-sand">{{ $order->reference }}</strong> in the payment reference or description so we can match it.</p>
                </section>

                @if ($canUpload)
                    <section aria-labelledby="upload-title" class="flex flex-col gap-4">
                        <h2 id="upload-title" class="text-display-xs">{{ $transfer ? 'Replace your proof' : 'Upload your proof' }}</h2>
                        <p class="text-sand-dim">
                            {{ $transfer ? 'Uploaded the wrong file? You can replace it until we\'ve reviewed it.' : 'After transferring, upload a screenshot of the confirmation from your banking app, or the PDF receipt.' }}
                        </p>

                        <form method="POST" action="{{ route('checkout.transfer.upload', $order->reference) }}" enctype="multipart/form-data" class="flex flex-col gap-5" novalidate>
                            @csrf
                            @php $proofError = $errors->first('proof'); @endphp
                            <div class="flex flex-col gap-2">
                                <label for="field-proof" class="text-sm font-semibold text-sand">Screenshot or PDF <span class="text-sand-dim">(required)</span></label>
                                <p id="field-proof-hint" class="text-sm text-sand-dim">JPG, PNG, WebP or PDF, up to 5 MB.</p>
                                <input id="field-proof" name="proof" type="file" required
                                    accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"
                                    aria-describedby="field-proof-hint{{ $proofError ? ' field-proof-error' : '' }}"
                                    @if ($proofError) aria-invalid="true" autofocus @endif
                                    class="block w-full rounded-sm border bg-earth-2 px-4 py-3 text-base text-sand {{ $proofError ? 'border-error' : 'border-line-strong hover:border-sand' }}">
                                @if ($proofError)
                                    <p id="field-proof-error" class="text-sm text-error"><span class="sr-only">Error: </span>{{ $proofError }}</p>
                                @endif
                            </div>

                            <x-form.field name="transaction_ref" label="Transaction ID" hint="From your bank's confirmation, if it shows one." :value="$transfer?->transaction_ref" maxlength="100" autocomplete="off" />

                            <div><x-button type="submit">{{ $transfer ? 'Replace proof' : 'Upload proof' }}</x-button></div>
                        </form>
                    </section>
                @endif
            @endif

            <p class="text-sm text-sand-dim"><a href="{{ route('account.orders.show', $order->reference) }}" class="underline underline-offset-4 hover:text-sand">See this order in your account</a></p>
        </div>
    </section>
</x-layouts.app>
