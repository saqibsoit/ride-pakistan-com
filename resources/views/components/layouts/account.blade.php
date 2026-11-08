{{--
    Account area layout: the page's h1, account navigation and sign-out.
    Never indexed or cached (routes send Cache-Control: no-store, private).

    <x-layouts.account title="Your bookings"> ... </x-layouts.account>
--}}
@props(['title', 'intro' => null])

@php
    $links = [
        ['label' => 'Overview', 'route' => 'account.dashboard', 'match' => 'account.dashboard'],
        ['label' => 'Bookings', 'route' => 'account.bookings', 'match' => 'account.bookings'],
        ['label' => 'Tickets', 'route' => 'account.tickets', 'match' => 'account.tickets*'],
        ['label' => 'Credits', 'route' => 'account.credits', 'match' => 'account.credits'],
        ['label' => 'Orders', 'route' => 'account.orders', 'match' => 'account.orders*'],
        ['label' => 'Riders', 'route' => 'account.riders', 'match' => 'account.riders'],
        ['label' => 'Profile', 'route' => 'account.profile', 'match' => 'account.profile'],
        ['label' => 'Privacy and security', 'route' => 'account.privacy', 'match' => 'account.privacy'],
    ];
@endphp

<x-layouts.app :title="$title" robots="noindex, nofollow">
    <div class="wrap grid gap-10 pt-12 pb-section md:pt-16 lg:grid-cols-4">
        <nav aria-label="Account" class="print:hidden lg:col-span-1">
            <ul class="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
                @foreach ($links as $link)
                    <li>
                        <a
                            href="{{ route($link['route']) }}"
                            @if (request()->routeIs($link['match'])) aria-current="page" @endif
                            class="block rounded-sm border border-line px-3 py-2 text-sm text-sand-dim no-underline hover:border-line-strong hover:text-sand current:border-sand current:text-sand lg:border-transparent"
                        >{{ $link['label'] }}</a>
                    </li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('logout') }}" class="mt-6">
                @csrf
                <x-button type="submit" variant="ghost" size="sm">Sign out</x-button>
            </form>
        </nav>

        <div class="flex min-w-0 flex-col gap-8 lg:col-span-3">
            <header class="flex flex-col gap-4">
                <h1 class="text-display-lg">{{ $title }}</h1>
                @if ($intro)
                    <p class="max-w-prose text-lede text-sand-dim">{{ $intro }}</p>
                @endif
            </header>

            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
