{{--
    Lead form shared by /contact and the /prices "Ask a question" section
    (stored by App\Actions\Leads\CaptureLead; the route sets the source).

    <x-lead-form :action="route('contact.store')" :attribution="$attribution" />

    `attribution` holds hidden values the server already knows (the
    uncached /contact page). On cached pages it is empty and
    resources/js/lead-attribution.js fills the fields from the page URL.
    Turnstile renders only when both of its keys are configured.
--}}
@props([
    'action',
    'attribution' => [],
    'selectedInterest' => null,
    'turnstileSiteKey' => null,
    'submitLabel' => 'Send message',
    'idPrefix' => '',
])

@php
    $fields = \App\Support\Tracking\Attribution::FIELDS;
@endphp

<form method="POST" action="{{ $action }}" class="flex flex-col gap-6" novalidate @if ($attribution === []) data-lead-attribution @endif>
    @csrf

    <x-form.field name="name" :id="$idPrefix === '' ? null : $idPrefix.'-name'" label="Your name" required autocomplete="name" maxlength="120" />

    <div class="grid gap-6 md:grid-cols-2">
        <x-form.field name="phone" :id="$idPrefix === '' ? null : $idPrefix.'-phone'" label="Phone or WhatsApp" type="tel" autocomplete="tel" inputmode="tel" hint="Phone or email: we need one to reply." maxlength="32" />
        <x-form.field name="email" :id="$idPrefix === '' ? null : $idPrefix.'-email'" label="Email" type="email" autocomplete="email" maxlength="190" />
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <x-form.field name="interest" :id="$idPrefix === '' ? null : $idPrefix.'-interest'" label="I’m interested in" type="select">
            <option value="">Choose one (optional)</option>
            @foreach (\App\Enums\LeadInterest::cases() as $interest)
                <option value="{{ $interest->value }}" @selected(old('interest', $selectedInterest?->value) === $interest->value)>{{ $interest->label() }}</option>
            @endforeach
        </x-form.field>
        <x-form.field name="preferred_date" :id="$idPrefix === '' ? null : $idPrefix.'-preferred_date'" label="Preferred date" type="date" :min="now((string) config('app.display_timezone'))->format('Y-m-d')" hint="Optional." />
    </div>

    <x-form.field name="message" :id="$idPrefix === '' ? null : $idPrefix.'-message'" label="Message" type="textarea" rows="5" maxlength="2000" />

    {{-- Honeypot: hidden from people and assistive tech; bots fill it. --}}
    <div class="hidden" aria-hidden="true">
        <label for="{{ $idPrefix === '' ? 'website' : $idPrefix.'-website' }}">Leave this empty</label>
        <input type="text" id="{{ $idPrefix === '' ? 'website' : $idPrefix.'-website' }}" name="{{ \App\Http\Requests\StoreLeadRequest::HONEYPOT }}" tabindex="-1" autocomplete="off">
    </div>

    @foreach ($fields as $field)
        <input type="hidden" name="{{ $field }}" value="{{ $attribution[$field] ?? '' }}">
    @endforeach

    @if ($turnstileSiteKey)
        {{-- Loaded when the form is near or focused (resources/js/components/turnstile.js, milestone 11). --}}
        <div class="cf-turnstile" x-data="turnstile" data-src="{{ \App\Support\Turnstile::SCRIPT_URL }}" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-size="flexible"></div>
        @error(\App\Support\Turnstile::RESPONSE_FIELD)
            <p class="text-sm text-error" role="alert">{{ $message }}</p>
        @enderror
    @endif

    <div class="flex flex-wrap items-center gap-4">
        <x-button type="submit">{{ $submitLabel }}</x-button>
        <p class="text-sm text-sand-dim">We only use your details to reply to you.</p>
    </div>
</form>
