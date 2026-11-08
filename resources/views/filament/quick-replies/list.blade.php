{{--
    Quick replies filled in for one lead (or none). Copy puts the text on the
    clipboard in the browser, then tells the page so the copy is logged on
    the lead's timeline (InteractsWithQuickReplies::quickReplyCopied).
    Plain markup plus resources/css/filament/lead-board.css (rp-replies).
--}}
<div class="rp-replies" x-data="{ tracked: {{ $lead ? 'true' : 'false' }}, copied: null }">
    @if ($lead)
        <label class="rp-replies__toggle">
            <input type="checkbox" x-model="tracked" class="fi-checkbox-input">
            <span>Include the tracking reference in links</span>
        </label>
    @else
        <p class="rp-replies__note">No lead chosen: links have no tracking reference and the copy isn't logged.</p>
    @endif

    @forelse ($groups as $category => $replies)
        <section class="rp-replies__group" aria-labelledby="replies-{{ \Illuminate\Support\Str::slug($category) }}">
            <h3 id="replies-{{ \Illuminate\Support\Str::slug($category) }}" class="rp-replies__heading">{{ $category }}</h3>
            @foreach ($replies as $reply)
                <article class="rp-replies__item" wire:key="reply-{{ $reply['id'] }}-{{ $lead?->id ?? 0 }}">
                    <header class="rp-replies__title">
                        <h4>{{ $reply['title'] }}</h4>
                        <x-filament::button
                            size="sm"
                            icon="heroicon-m-clipboard-document"
                            data-tracked="{{ $reply['tracked'] }}"
                            data-plain="{{ $reply['plain'] }}"
                            x-on:click="navigator.clipboard.writeText(tracked ? $el.dataset.tracked : $el.dataset.plain).then(() => { copied = {{ $reply['id'] }}; $wire.quickReplyCopied({{ $reply['id'] }}, {{ $lead?->id ?? 'null' }}, tracked) })"
                        >
                            <span x-text="copied === {{ $reply['id'] }} ? 'Copied' : 'Copy'">Copy</span><span class="fi-sr-only"> {{ $reply['title'] }}</span>
                        </x-filament::button>
                    </header>
                    <p class="rp-replies__text" x-show="tracked" @unless ($lead) x-cloak @endunless>{{ $reply['tracked'] }}</p>
                    <p class="rp-replies__text" x-show="! tracked" @if ($lead) x-cloak @endif>{{ $reply['plain'] }}</p>
                </article>
            @endforeach
        </section>
    @empty
        <p class="rp-replies__note">No reply templates yet. An admin adds them under Leads → Reply templates.</p>
    @endforelse

    <p class="fi-sr-only" role="status" aria-live="polite" x-text="copied ? 'Copied to the clipboard' : ''"></p>
</div>
