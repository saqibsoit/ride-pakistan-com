{{--
    <x-breadcrumb :items="[['label' => 'Home', 'url' => '/'], ['label' => 'Experiences', 'url' => '/experiences'], ['label' => 'Single session']]" />
    The last item is the current page (no link, aria-current="page").
--}}
@props(['items' => []])

<nav aria-label="Breadcrumb" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-sand-dim">
        @foreach ($items as $item)
            <li class="flex items-center gap-2">
                @if (! $loop->first)
                    <span aria-hidden="true">/</span>
                @endif
                @if ($loop->last || empty($item['url']))
                    <span @if ($loop->last) aria-current="page" class="text-sand" @endif>{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['url'] }}" class="underline-offset-4 hover:text-sand hover:underline">{{ $item['label'] }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
