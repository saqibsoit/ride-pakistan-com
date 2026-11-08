<x-layouts.account title="Privacy and security">
    <section aria-labelledby="export-title" class="flex max-w-narrow flex-col gap-4">
        <h2 id="export-title" class="text-display-xs">Download my data</h2>
        <p class="text-sand-dim">A JSON file with your profile, riders, orders, bookings, credits and messages to us.</p>
        <form method="POST" action="{{ route('account.privacy.export') }}">
            @csrf
            <x-button type="submit" variant="ghost">Download my data</x-button>
        </form>
    </section>

    <section aria-labelledby="sessions-title" class="flex max-w-narrow flex-col gap-4 border-t border-line pt-8">
        <h2 id="sessions-title" class="text-display-xs">Sign out everywhere</h2>
        <p class="text-sand-dim">Ends every session on every device, including this one. Use it if you signed in on a shared or lost device.</p>
        <form method="POST" action="{{ route('account.sign-out-everywhere') }}">
            @csrf
            <x-button type="submit" variant="ghost">Sign out everywhere</x-button>
        </form>
    </section>

    <section id="delete-account" aria-labelledby="delete-title" class="flex max-w-narrow flex-col gap-4 border-t border-line pt-8" tabindex="-1">
        <h2 id="delete-title" class="text-display-xs">Delete my account</h2>

        @if (! $canDelete)
            <p class="text-sand-dim">Team accounts are removed by an admin.</p>
        @else
            <p class="text-sand-dim">
                You’ll be signed out straight away. For {{ $graceDays }} days you can change your mind by signing in again; after that your personal details are removed.
                If you’ve paid for anything, we keep your details with your order records for {{ $retentionYears }} years after your last payment, then remove them. Nobody can sign in to the account in that time.
            </p>

            @if ($errors->has('delete'))
                <x-alert variant="error" title="Your account can’t be deleted yet.">
                    <ul class="flex flex-col gap-1">
                        @foreach ($errors->get('delete') as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            @if ($blockers !== [])
                <x-alert variant="warning" title="Before you can delete your account">
                    <ul class="flex flex-col gap-1">
                        @foreach ($blockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @else
                <form method="POST" action="{{ route('account.destroy') }}" class="flex flex-col gap-4" novalidate>
                    @csrf
                    @method('DELETE')

                    @if ($unusedCredits > 0)
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="forfeit_credits" name="forfeit_credits" value="1" class="mt-1 size-5 shrink-0 rounded-sm border-line-strong bg-earth-2 accent-sand">
                            <label for="forfeit_credits" class="text-sm text-sand">
                                I understand I give up my {{ $unusedCredits }} unused {{ $unusedCredits === 1 ? 'credit' : 'credits' }}.
                            </label>
                        </div>
                    @endif

                    <div class="flex flex-col gap-2">
                        <div class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                id="confirm"
                                name="confirm"
                                value="1"
                                required
                                @if ($errors->has('confirm')) aria-invalid="true" aria-describedby="confirm-error" @endif
                                class="mt-1 size-5 shrink-0 rounded-sm border-line-strong bg-earth-2 accent-sand"
                            >
                            <label for="confirm" class="text-sm text-sand">Yes, delete my account</label>
                        </div>
                        @error('confirm')
                            <p id="confirm-error" class="flex items-start gap-2 text-sm text-error">
                                <x-glyph name="error" class="mt-0.5 size-4" />
                                <span><span class="sr-only">Error: </span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div>
                        <x-button type="submit" variant="ghost">Delete my account</x-button>
                    </div>
                </form>
            @endif
        @endif
    </section>
</x-layouts.account>
