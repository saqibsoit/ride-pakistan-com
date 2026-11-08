{{-- Transfer proof in the verification modal. The URL is signed and expires after a few minutes; reopen the modal for a fresh one. --}}
<div class="flex flex-col gap-4">
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt class="font-medium">Expected</dt>
        <dd>{{ \App\Support\Money::format($order->amount_minor, $order->currency) }}, reference {{ $order->reference }}</dd>
        <dt class="font-medium">Transaction ID</dt>
        <dd>{{ $transfer?->transaction_ref ?? '—' }}</dd>
        <dt class="font-medium">Uploaded</dt>
        <dd>{{ $transfer?->submitted_at?->setTimezone(config('app.display_timezone'))->format('j M Y, H:i') }} (Pakistan time)</dd>
    </dl>

    @if ($url === null)
        <p>{{ $transfer?->proof_deleted_at ? 'The file was deleted on '.$transfer->proof_deleted_at->setTimezone(config('app.display_timezone'))->format('j M Y').' (retention period). The transfer record is kept.' : 'No proof uploaded yet.' }}</p>
    @elseif ($pdf)
        <p>The proof is a PDF. <a href="{{ $url }}" class="font-medium underline" rel="noopener noreferrer">Download it</a> (link valid for {{ \App\Http\Controllers\TransferProofController::URL_MINUTES }} minutes).</p>
    @else
        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="block">
            <img src="{{ $url }}" alt="Transfer proof for order {{ $order->reference }}" class="h-auto max-w-full rounded border" loading="eager">
        </a>
        <p class="text-xs">Opens full size in a new tab. The link expires after {{ \App\Http\Controllers\TransferProofController::URL_MINUTES }} minutes.</p>
    @endif
</div>
