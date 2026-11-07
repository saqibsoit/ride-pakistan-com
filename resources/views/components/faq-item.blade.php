{{--
    <x-faq-item question="What should I wear?">Full-length trousers and closed shoes.</x-faq-item>
    group: optional name so only one item in the group is open at a time.
--}}
@props(['question', 'group' => null, 'open' => false])

<details @if ($group) name="{{ $group }}" @endif @if ($open) open @endif {{ $attributes->class('faq-item border-t border-line last-of-type:border-b') }}>
    <summary class="relative cursor-pointer py-6.5 pr-10 font-display text-display-xs font-extrabold">{{ $question }}</summary>
    <div class="max-w-prose pb-6.5 text-sand-dim">{{ $slot }}</div>
</details>
