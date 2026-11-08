{{-- Lead timeline (LeadInfolist::timeline): newest first. Plain markup, styled by rp-timeline. --}}
@php($entries = $getState() ?? [])
@if ($entries === [])
    <p class="rp-replies__note">Nothing recorded yet.</p>
@else
    <ol class="rp-timeline">
        @foreach ($entries as $entry)
            <li class="rp-timeline__item">
                <span class="rp-timeline__what">{{ $entry['what'] }}</span>
                <span class="rp-timeline__meta">{{ $entry['at'] }} · {{ $entry['who'] }}</span>
            </li>
        @endforeach
    </ol>
@endif
