{{--
    Base layout for public pages.

    Pages pass SEO data built by App\Support\Seo\Seo (title, description,
    canonical, robots, Open Graph, Twitter, JSON-LD):
    <x-layouts.app :seo="$seo" overlay-header> ... </x-layouts.app>

    Without :seo (styleguide, simple pages) title/description/robots props
    are run through the same builder.

    Pages that render Livewire components pass `livewire`: they load
    resources/js/livewire.js (Livewire + its bundled Alpine) instead of
    app.js, so Alpine is only ever loaded once.
--}}
@props([
    'seo' => null,
    'title' => null,
    'description' => null,
    'overlayHeader' => false,
    'robots' => null,
    'livewire' => false,
])

@php
    $seo ??= app(\App\Support\Seo\Seo::class)->make(
        title: $title,
        description: $description,
        indexable: $robots === null || ! str_contains($robots, 'noindex'),
    );
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-seo.head :seo="$seo" />
    <meta name="theme-color" content="{{ config('site.theme_color') }}">

    <link rel="preload" href="{{ Vite::asset('resources/fonts/big-shoulders-display.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    @if ($livewire)
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/livewire.js'])
        @livewireScriptConfig
    @else
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    {{ $head ?? '' }}

    @php
        $tracking = app(\App\Support\Tracking\TrackingConfig::class);
        $trackingConfig = $tracking->anyEnabled() ? [
            'metaPixelId' => $tracking->metaPixelId(),
            'ga4Id' => $tracking->ga4Id(),
            'events' => app(\App\Support\Tracking\BrowserEvents::class)->all(),
        ] : null;
    @endphp
    @if ($trackingConfig !== null)
        {{-- Data only (not executed). resources/js/tracking.js loads the
             vendors with this nonce after the visitor accepts. --}}
        <script type="application/json" id="rp-tracking" @nonce>@json($trackingConfig)</script>
    @endif
</head>
<body class="flex min-h-svh flex-col">
    <a href="#main" class="sr-only z-50 rounded-sm bg-sand px-4 py-3 font-semibold text-earth focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Skip to content</a>

    <x-site-header :overlay="$overlayHeader" />

    <main id="main" tabindex="-1" class="flex-1 outline-none">
        {{ $slot }}
    </main>

    <x-site-footer :cookie-settings="$tracking->anyEnabled()" />

    @if ($tracking->anyEnabled())
        <x-consent-banner />
    @endif
</body>
</html>
