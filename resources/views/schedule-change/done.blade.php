{{-- After answering a session time change, or an expired or used link (prompt 15A). --}}
@php($status = $notice->status)
<x-layouts.app :title="$title" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="done-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Schedule change</p>
        <h1 id="done-title" class="mt-2 text-display-lg">{{ $title }}</h1>

        <div class="mt-8 flex flex-col gap-6">
            @switch($status)
                @case(\App\Enums\SlotChangeResponse::Accepted)
                    <x-alert variant="success" title="You’re keeping the new time.">
                        See you on {{ $notice->newLabel() }} (Pakistan time). Your tickets stay the same.
                    </x-alert>
                    @break
                @case(\App\Enums\SlotChangeResponse::Moved)
                    <x-alert variant="success" title="Your booking has moved.">
                        We’ve emailed you the new time and an updated calendar file.
                        <x-spam-hint />
                    </x-alert>
                    @break
                @case(\App\Enums\SlotChangeResponse::Cancelled)
                    <x-alert variant="success" title="Your booking is cancelled.">
                        Any package credit has gone back to your package. We’ve emailed you a confirmation.
                        <x-spam-hint />
                    </x-alert>
                    @break
                @case(\App\Enums\SlotChangeResponse::RefundRequested)
                    <x-alert variant="success" title="We’ve got your refund request.">
                        We’ll be in touch about your refund. Your booking stays in place until then.
                    </x-alert>
                    @break
                @default
                    <x-alert variant="info" title="This link no longer works.">
                        The session has started, or the link has run out. Your booking stays at {{ $notice->newLabel() }}. Message us if you need help.
                    </x-alert>
            @endswitch

            <p class="flex flex-wrap gap-4">
                <a href="{{ route('account.bookings') }}" class="underline underline-offset-4 hover:text-sand">Your bookings</a>
                <a href="{{ route('contact') }}" class="underline underline-offset-4 hover:text-sand">Contact us</a>
            </p>
        </div>
    </section>
</x-layouts.app>
