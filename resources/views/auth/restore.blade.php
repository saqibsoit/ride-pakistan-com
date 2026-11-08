{{-- /login/restore: a verified sign-in for an account inside its deletion grace period. --}}
<x-layouts.app title="Keep your account?" robots="noindex, nofollow">
    <section class="wrap max-w-narrow pt-12 pb-section md:pt-16" aria-labelledby="restore-title">
        <h1 id="restore-title" class="text-display-xl">Keep your account?</h1>

        <div class="mt-10 flex flex-col gap-6">
            <x-alert variant="warning" title="This account is scheduled for deletion.">
                The account for {{ $email }} will be deleted
                @if ($endsAt)
                    on <time datetime="{{ $endsAt->toIso8601String() }}">{{ $endsAt->setTimezone(config('app.display_timezone'))->format('j F Y') }}</time>
                @endif
                unless you keep it.
            </x-alert>

            <div class="flex flex-wrap items-center gap-4">
                <form method="POST" action="{{ route('account.restore.store') }}">
                    @csrf
                    <x-button type="submit">Keep my account</x-button>
                </form>
                <form method="POST" action="{{ route('account.restore.cancel') }}">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="ghost">No, sign out</x-button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.app>
