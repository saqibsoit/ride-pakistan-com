{{-- /team/sessions/{slot}?print=1: the run sheet as one compact table for paper (milestone 10b). --}}
@php
    use App\Enums\RunSheetPayment;
@endphp

<x-layouts.app title="Run sheet {{ $slot->localStartsAt()->format('D j M H:i') }} (print)" robots="noindex, nofollow">
    <section class="wrap pt-8 pb-section print:p-0" aria-labelledby="print-title">
        <p class="text-sm text-sand-dim print:hidden">
            <a href="{{ route('team.sessions.show', $slot) }}" class="underline underline-offset-4 hover:text-sand">Back to the run sheet</a>
            · Use your browser's print option to print this page.
        </p>
        <h1 id="print-title" class="mt-4 text-display-md">
            Run sheet: {{ $slot->localStartsAt()->format('l j M Y') }}, {{ $slot->localStartsAt()->format('H:i') }}–{{ $slot->localEndsAt()->format('H:i') }}
        </h1>
        <p class="mt-2 text-sand-dim">Trainer: {{ $slot->trainer?->displayName() ?? 'not set' }} · {{ $bookings->count() }}/{{ $slot->capacity }} riders</p>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full border-collapse text-left text-sm">
                <caption class="sr-only">Riders in this session</caption>
                <thead>
                    <tr class="border-b border-line-strong">
                        <th scope="col" class="py-2 pr-3">Rider</th>
                        <th scope="col" class="py-2 pr-3">Age</th>
                        <th scope="col" class="py-2 pr-3">Experience</th>
                        <th scope="col" class="py-2 pr-3">Horse</th>
                        <th scope="col" class="py-2 pr-3">Emergency contact</th>
                        <th scope="col" class="py-2 pr-3">Booked by (WhatsApp)</th>
                        <th scope="col" class="py-2 pr-3">Payment</th>
                        <th scope="col" class="py-2 pr-3">Notes</th>
                        <th scope="col" class="py-2">In</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        @php $rider = $booking->rider; @endphp
                        <tr class="border-b border-line align-top">
                            <th scope="row" class="py-2 pr-3 font-semibold">{{ $rider->name }}@if ($booking->is_private) (private)@endif</th>
                            <td class="py-2 pr-3">{{ $rider->age_bracket?->label() ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $rider->experience_level?->label() ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $booking->horse?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $rider->emergency_contact_name ?: '—' }}@if ($rider->emergency_contact_phone)<br>{{ $rider->emergency_contact_phone }}@endif</td>
                            <td class="py-2 pr-3">{{ $booking->user->displayName() }}@if ($booking->contactWhatsApp())<br>{{ $booking->contactWhatsApp() }}@endif</td>
                            <td class="py-2 pr-3">{{ RunSheetPayment::for($booking)->label() }}</td>
                            <td class="py-2 pr-3">{{ $rider->notes ?: '—' }}</td>
                            <td class="py-2">{{ $booking->status->label() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-sand-dim">Nobody has booked this session.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
