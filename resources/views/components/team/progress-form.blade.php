{{-- Skills and the customer's progress note for one rider (milestone 10d). --}}
@if ($allowed && $skills->isNotEmpty())
    <form method="POST" action="{{ route('team.sessions.progress', [$session, $booking]) }}" class="flex flex-col gap-3">
        @csrf
        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-semibold text-sand">Skills {{ $booking->rider->name }} can do</legend>
            @foreach ($skills as $skill)
                @php($has = $reached[$skill->id] ?? null)
                <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-sm border border-line-strong px-4 py-2 has-checked:border-sand">
                    <input type="checkbox" name="skills[]" value="{{ $skill->id }}" @checked($has !== null) class="size-5 accent-sand">
                    <span>
                        {{ $skill->name }}
                        @if ($has && $has->booking_id !== $booking->id)
                            <span class="text-sm text-sand-dim">· since {{ $has->achieved_at->setTimezone(config('app.display_timezone'))->format('j M') }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </fieldset>
        <x-form.field
            type="textarea"
            name="progress_note"
            id="progress-{{ $booking->id }}"
            label="Note for the family"
            :error="''"
            :value="$booking->progress_note"
            rows="2"
            maxlength="{{ \App\Actions\Progress\RecordRiderProgress::NOTE_MAX_LENGTH }}"
            hint="The customer sees this in their account. Keep it short and encouraging."
        />
        <x-button variant="ghost" type="submit" class="w-full sm:w-auto">Save progress</x-button>
    </form>
@endif
