<div class="flex flex-col gap-8">
    <p class="text-sand-dim">{{ $pack->creditsRemaining() }} {{ $pack->creditsRemaining() === 1 ? 'credit' : 'credits' }} left, valid until {{ $pack->expires_at->setTimezone(config('app.display_timezone'))->format('j M Y') }}. One credit per rider per session.</p>

    @if ($error)
        <x-alert variant="error" title="That didn’t work.">{{ $error }}</x-alert>
    @endif

    <form wire:submit="book" class="flex flex-col gap-8">
        <fieldset class="flex flex-col gap-3">
            <legend class="mb-2 font-semibold">Session (Pakistan time)</legend>
            @error('slotId')
                <x-alert variant="error">{{ $message }}</x-alert>
            @enderror
            @forelse ($sessions as $slot)
                <label class="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                    <span class="flex items-center gap-3">
                        <input type="radio" name="slot" value="{{ $slot->id }}" wire:model="slotId" class="size-5 accent-sand">
                        <time datetime="{{ $slot->starts_at->toIso8601String() }}" class="font-semibold">{{ $slot->label() }}</time>
                    </span>
                    <x-seats-left :seats="$slot->seatsFree()" />
                </label>
            @empty
                <p class="text-sand-dim">No group sessions are open before your package expires. <a href="{{ route('contact') }}" class="underline underline-offset-4">Message us</a> and we’ll help.</p>
            @endforelse
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="mb-2 font-semibold">Who’s riding?</legend>
            @error('riderIds')
                <x-alert variant="error">{{ $message }}</x-alert>
            @enderror
            @foreach ($riders as $rider)
                <label class="flex items-center gap-3 rounded-sm border border-line-strong p-4 has-checked:border-sand">
                    <input type="checkbox" value="{{ $rider->id }}" wire:model="riderIds" class="size-5 accent-sand">
                    <span class="font-semibold">{{ $rider->name }}</span>
                </label>
            @endforeach
        </fieldset>

        @if ($sessions->isNotEmpty())
            <div><x-button type="submit" wire:loading.attr="disabled">Book with my credits</x-button></div>
        @endif
    </form>

    <form wire:submit="addRider" class="flex flex-col gap-3 border-t border-line pt-6">
        <x-form.field name="newRiderName" label="Add a rider" wire:model="newRiderName" maxlength="120" autocomplete="off" />
        <div><x-button type="submit" variant="ghost">Add rider</x-button></div>
    </form>
</div>
