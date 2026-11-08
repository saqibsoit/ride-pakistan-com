{{-- /reviews/{ticket}: review an attended session from the signed thank-you link (milestone 10d). --}}
<x-layouts.app title="Review your session" robots="noindex, nofollow">
    <section class="wrap max-w-prose pt-12 pb-section md:pt-16" aria-labelledby="review-title">
        <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">{{ $booking->slot->localStartsAt()->format('l j F Y') }}</p>
        <h1 id="review-title" class="mt-2 text-display-lg">How was the ride?</h1>

        @if ($saved)
            <div class="mt-8 flex flex-col gap-6">
                <x-alert variant="success" title="Thank you.">
                    We read every review. Once it’s checked, it may appear on our site with your first name and initial.
                </x-alert>
                @if ($googleUrl !== '')
                    <div class="flex flex-col gap-3">
                        <p class="text-sand-dim">A Google review helps other beginners in Islamabad find us too. It takes a minute.</p>
                        <div><x-button :href="$googleUrl" external>Review us on Google</x-button></div>
                    </div>
                @endif
                <p><a href="{{ route('schedule') }}" class="underline underline-offset-4 hover:text-sand">See the schedule</a></p>
            </div>
        @elseif (! $attended)
            <x-alert variant="info" class="mt-8" title="Reviews open after the session.">
                Once {{ $booking->rider->name }} has ridden with us, you can leave a review from this link.
            </x-alert>
        @elseif ($review && $review->status !== \App\Enums\ReviewStatus::Pending)
            <x-alert variant="success" class="mt-8" title="Thanks, we already have your review.">
                You reviewed this session on {{ $review->created_at->setTimezone(config('app.display_timezone'))->format('j M Y') }}.
            </x-alert>
        @else
            <p class="mt-4 text-lede text-sand-dim">{{ $booking->rider->name }} rode with us on {{ $booking->slot->label() }}. Tell other riders what it was like.</p>

            <form method="POST" action="{{ $action }}" class="mt-8 flex flex-col gap-6">
                @csrf
                <fieldset class="flex flex-col gap-3" @if ($errors->has('rating')) aria-describedby="rating-error" @endif>
                    <legend class="text-sm font-semibold text-sand">Your rating</legend>
                    <div class="flex flex-wrap gap-2">
                        @for ($i = 5; $i >= 1; $i--)
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-sm border border-line-strong px-4 py-2 has-checked:border-sand has-checked:bg-earth-2">
                                <input type="radio" name="rating" value="{{ $i }}" @checked((int) old('rating', $review?->rating ?? 0) === $i) required class="size-5 accent-sand">
                                <span>{{ $i }} {{ $i === 1 ? 'star' : 'stars' }}</span>
                            </label>
                        @endfor
                    </div>
                    @error('rating')
                        <p id="rating-error" class="text-sm text-error">{{ $message }}</p>
                    @enderror
                </fieldset>

                <x-form.field
                    name="body"
                    label="Your review"
                    type="textarea"
                    rows="5"
                    maxlength="{{ \App\Models\Review::BODY_MAX_LENGTH }}"
                    :value="$review?->body"
                    hint="What did you enjoy? Who would you recommend it to? Shown with your first name and initial."
                    required
                />

                {{-- Honeypot: hidden from people and assistive tech; bots fill it. --}}
                <div class="hidden" aria-hidden="true">
                    <label for="review-website">Leave this empty</label>
                    <input type="text" id="review-website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div>
                    <x-button type="submit">{{ $review ? 'Update my review' : 'Send my review' }}</x-button>
                </div>
            </form>
        @endif
    </section>
</x-layouts.app>
