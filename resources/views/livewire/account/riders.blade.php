<div class="flex flex-col gap-6">
    <div aria-live="polite" class="empty:hidden">
        @if ($status)
            <x-alert variant="success">{{ $status }}</x-alert>
        @endif
    </div>

    @if ($riders->isEmpty() && ! $showForm)
        <p class="text-sand-dim">No riders yet. Add yourself first, then anyone else you book for.</p>
    @endif

    @if ($riders->isNotEmpty())
        <ul class="flex flex-col gap-3">
            @foreach ($riders as $rider)
                <li wire:key="rider-{{ $rider->id }}" class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-line bg-earth-2 p-5">
                    <div class="flex flex-col gap-1">
                        <p class="font-semibold text-sand">{{ $rider->name }}</p>
                        <p class="text-sm text-sand-dim">
                            {{ $rider->age_bracket?->label() ?? 'Age not given' }} ·
                            {{ $rider->experience_level?->label() ?? 'Experience not given' }} ·
                            {{ $rider->emergency_contact_phone ? 'Emergency contact added' : 'No emergency contact' }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        @if ($confirmingDeleteId === $rider->id)
                            <p class="text-sm text-sand" id="confirm-delete-{{ $rider->id }}">Remove {{ $rider->name }}?</p>
                            <x-button wire:click="delete({{ $rider->id }})" variant="ghost" size="sm" aria-describedby="confirm-delete-{{ $rider->id }}">Yes, remove</x-button>
                            <x-button wire:click="$set('confirmingDeleteId', null)" variant="ghost" size="sm">Keep</x-button>
                        @else
                            <x-button wire:click="edit({{ $rider->id }})" variant="ghost" size="sm">Edit<span class="sr-only"> {{ $rider->name }}</span></x-button>
                            @if ($rider->bookings_count === 0 && $rider->order_items_count === 0)
                                <x-button wire:click="confirmDelete({{ $rider->id }})" variant="ghost" size="sm">Remove<span class="sr-only"> {{ $rider->name }}</span></x-button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($showForm)
        <form wire:submit="save" class="flex flex-col gap-6 rounded-sm border border-line-strong p-6" novalidate aria-labelledby="rider-form-title">
            <h2 id="rider-form-title" class="text-display-xs">{{ $editingId ? 'Edit rider' : 'Add a rider' }}</h2>

            <x-form.field name="name" label="Name" required autocomplete="off" maxlength="120" wire:model="name" />

            <div class="grid gap-6 md:grid-cols-2">
                <x-form.field name="age_bracket" label="Age" type="select" wire:model="age_bracket">
                    <option value="">Choose one</option>
                    @foreach ($ageBrackets as $bracket)
                        <option value="{{ $bracket->value }}">{{ $bracket->label() }}</option>
                    @endforeach
                </x-form.field>

                <x-form.field name="experience_level" label="Riding experience" type="select" wire:model="experience_level">
                    <option value="">Choose one</option>
                    @foreach ($experienceLevels as $level)
                        <option value="{{ $level->value }}">{{ $level->label() }}</option>
                    @endforeach
                </x-form.field>
            </div>

            <fieldset class="flex flex-col gap-6">
                <legend class="mb-2 text-sm font-semibold text-sand">Emergency contact</legend>
                <div class="grid gap-6 md:grid-cols-2">
                    <x-form.field name="emergency_contact_name" label="Contact name" autocomplete="off" maxlength="120" wire:model="emergency_contact_name" />
                    <x-form.field name="emergency_contact_phone" label="Contact phone" type="tel" inputmode="tel" autocomplete="off" maxlength="32" hint="For example 0300 1234567 or +44 7700 900123." wire:model="emergency_contact_phone" />
                </div>
            </fieldset>

            <x-form.field name="notes" label="Anything we should know" type="textarea" rows="3" maxlength="1000" hint="Health, fears, goals. Only you and our team can see this." wire:model="notes" />

            <div class="flex flex-wrap items-center gap-4">
                <x-button type="submit">{{ $editingId ? 'Save changes' : 'Add rider' }}</x-button>
                <x-button wire:click="cancel" variant="ghost">Cancel</x-button>
            </div>
        </form>
    @else
        <div>
            <x-button wire:click="create">Add a rider</x-button>
        </div>
    @endif
</div>
