{{--
    Cookie choices for non-essential tracking (Meta Pixel, GA4). Rendered by
    the layout only when one of them is configured. Hidden until Alpine
    (resources/js/components/consent-banner.js) finds no answer yet, so a
    cached page shows it to new visitors only. Accept and Decline are equal.
--}}
@php($privacyPublished = \App\Models\Page::query()->published()->where('slug', 'privacy')->exists())

<div
    x-data="consentBanner"
    x-show="open"
    x-cloak
    role="region"
    aria-labelledby="consent-title"
    class="fixed inset-x-0 bottom-0 z-40 border-t border-line-strong bg-earth-2 print:hidden"
>
    <div class="wrap flex flex-col gap-4 py-5 md:flex-row md:items-center md:justify-between">
        <div class="max-w-prose">
            <p id="consent-title" class="font-semibold text-sand">Cookies for measuring our ads</p>
            <p class="mt-1 text-sm text-sand-dim">
                With your OK we use Meta and Google cookies to see which ads and posts bring riders to us.
                The site works the same either way.
                @if ($privacyPublished)
                    <a href="{{ route('legal.privacy') }}" class="underline underline-offset-4">Privacy policy</a>
                @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-3">
            <x-button size="sm" x-ref="accept" x-on:click="accept">Accept</x-button>
            <x-button size="sm" variant="ghost" x-on:click="decline">Decline</x-button>
        </div>
    </div>
</div>
