<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen antialiased">
        <main class="mx-auto flex min-h-screen max-w-2xl flex-col justify-center gap-4 px-6">
            <h1 class="text-3xl font-semibold">{{ config('app.name') }}</h1>
            <p>Weekend horse riding lessons in Islamabad. The new site is on its way.</p>
        </main>
    </body>
</html>
