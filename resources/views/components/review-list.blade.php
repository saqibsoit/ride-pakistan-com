{{--
    Approved customer reviews (milestone 10d): rating, words, first name and
    initial, the session month, and the team's reply when there is one.

    <x-review-list :reviews="$reviews" />
--}}
@props(['reviews'])

<ul {{ $attributes->class('grid gap-4 md:grid-cols-2 lg:grid-cols-3') }} role="list">
    @foreach ($reviews as $review)
        <li>
            <figure class="flex h-full flex-col gap-4 rounded-sm border border-line bg-earth-2 p-6">
                <p class="flex items-center gap-1 text-sand">
                    @for ($i = 1; $i <= 5; $i++)
                        <x-glyph name="star" class="size-4 {{ $i <= $review->rating ? 'fill-current' : 'text-sand-dim' }}" />
                    @endfor
                    <span class="sr-only">Rated {{ $review->rating }} out of 5</span>
                </p>
                <blockquote class="grow">
                    <p class="whitespace-pre-line">{{ $review->body }}</p>
                </blockquote>
                <figcaption class="text-sm text-sand-dim">
                    {{ $review->display_name }} · <time datetime="{{ $review->created_at->toDateString() }}">{{ $review->created_at->setTimezone(config('app.display_timezone'))->format('F Y') }}</time>
                </figcaption>
                @if ($review->reply)
                    <div class="border-l-3 border-line-strong pl-4 text-sm">
                        <p class="font-semibold">Reply from Ride Pakistan</p>
                        <p class="mt-1 whitespace-pre-line text-sand-dim">{{ $review->reply }}</p>
                    </div>
                @endif
            </figure>
        </li>
    @endforeach
</ul>
