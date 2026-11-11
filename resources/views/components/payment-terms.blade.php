{{--
    "By paying you agree to our Terms and Refund policy." next to a payment
    button. Links appear only for published legal pages, so an unpublished
    page never becomes a dead link.
    <x-payment-terms />  ·  <x-payment-terms verb="confirming" />
--}}
@props(['verb' => 'paying'])

@php
    $published = \App\Models\Page::query()->published()->whereIn('slug', ['terms', 'refund-policy'])->pluck('slug')->all();
    $terms = in_array('terms', $published, true)
        ? '<a href="'.e(route('legal.terms')).'" class="underline underline-offset-4 hover:text-sand">Terms and Conditions</a>'
        : 'Terms and Conditions';
    $refund = in_array('refund-policy', $published, true)
        ? '<a href="'.e(route('legal.refund-policy')).'" class="underline underline-offset-4 hover:text-sand">Cancellation and Refund Policy</a>'
        : 'Cancellation and Refund Policy';
@endphp

<p data-payment-terms {{ $attributes->class('text-sm text-sand-dim') }}>By {{ $verb }} you agree to our {!! $terms !!} and {!! $refund !!}.</p>
