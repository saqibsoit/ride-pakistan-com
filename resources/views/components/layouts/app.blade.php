{{--
    Base layout for public pages.

    <x-layouts.app title="Prices" description="..." overlay-header>
        <x-slot:head> canonical, Open Graph, JSON-LD </x-slot:head>
        ...
    </x-layouts.app>
--}}
@props([
    'title' => null,
    'description' => null,
    'overlayHeader' => false,
    'robots' => null,
])

@php
    $pageTitle = $title ? $title.' | Ride Pakistan' : (string) config('site.default_title');
    $pageDescription = $description ?? (string) config('site.default_description');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    @if ($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif
    <meta name="theme-color" content="{{ config('site.theme_color') }}">

    <link rel="preload" href="{{ Vite::asset('resources/fonts/big-shoulders-display.woff2') }}" as="font" type="font/woff2" crossorigin>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{ $head ?? '' }}
</head>
<body class="flex min-h-svh flex-col">
    <a href="#main" class="sr-only z-50 rounded-sm bg-sand px-4 py-3 font-semibold text-earth focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Skip to content</a>

    <x-site-header :overlay="$overlayHeader" />

    <main id="main" tabindex="-1" class="flex-1 outline-none">
        {{ $slot }}
    </main>

    <x-site-footer />
</body>
</html>
