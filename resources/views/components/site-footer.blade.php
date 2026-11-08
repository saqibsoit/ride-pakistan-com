{{-- Site footer: brand, site links, contact, legal. Contact details come from settings. --}}
@php
    $settings = app(\App\Support\Settings::class);
    $siteLinks = [
        ['label' => 'Experiences', 'url' => route('experiences')],
        ['label' => 'Schedule', 'url' => route('schedule')],
        ['label' => 'Prices', 'url' => route('prices')],
        ['label' => 'Your trainer', 'url' => route('trainer')],
        ['label' => 'Guides', 'url' => route('guides.index')],
        ['label' => 'FAQ', 'url' => route('faq')],
        ['label' => 'Contact', 'url' => route('contact')],
    ];
    // Legal pages appear once they are published in the CMS.
    $legal = ['privacy' => 'Privacy', 'terms' => 'Terms', 'refund-policy' => 'Refund policy'];
    $published = \App\Models\Page::query()->published()->whereIn('slug', array_keys($legal))->pluck('slug')->all();
    $legalLinks = [];
    foreach ($legal as $slug => $label) {
        if (in_array($slug, $published, true)) {
            $legalLinks[] = ['label' => $label, 'url' => route('legal.'.$slug)];
        }
    }
    $expeditions = $settings->string(\App\Enums\SettingKey::ExpeditionsUrl);
    $email = $settings->string(\App\Enums\SettingKey::Email);
@endphp

<footer {{ $attributes->class('site-footer border-t border-line pt-14 text-sm text-sand-dim') }}>
    <div class="wrap grid gap-10 md:grid-cols-4">
        <div class="flex flex-col gap-4 md:col-span-2">
            <a href="{{ url('/') }}" class="flex items-center gap-3.5 self-start font-display text-display-xs font-extrabold text-sand no-underline">
                <x-img src="logo.png" alt="" sizes="68px" class="h-10 w-auto" />
                Ride Pakistan
            </a>
            <p class="max-w-prose">{{ $settings->string(\App\Enums\SettingKey::Tagline) }}</p>
            <p>A {{ $settings->string(\App\Enums\SettingKey::ParentCompany) }} company</p>
        </div>

        <nav aria-label="Site">
            <ul class="flex flex-col gap-2.5">
                @foreach ($siteLinks as $link)
                    <li><a href="{{ $link['url'] }}" class="no-underline hover:text-sand hover:underline">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="flex flex-col gap-2.5">
            <p class="font-semibold text-sand">Get in touch</p>
            <ul class="flex flex-col gap-2.5">
                <li><a href="{{ $settings->string(\App\Enums\SettingKey::InstagramUrl) }}" target="_blank" rel="noopener" class="no-underline hover:text-sand hover:underline">Instagram<span class="sr-only"> (opens in a new tab)</span></a></li>
                <li><a href="{{ $expeditions }}" target="_blank" rel="noopener" class="no-underline hover:text-sand hover:underline">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($expeditions, '/')) }}<span class="sr-only"> (opens in a new tab)</span></a></li>
                <li><a href="mailto:{{ $email }}" class="no-underline hover:text-sand hover:underline">{{ $email }}</a></li>
            </ul>
        </div>
    </div>

    <div class="wrap mt-12 flex flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
        <p>&copy; {{ now()->year }} Ride Pakistan</p>
        @if ($legalLinks !== [])
        <nav aria-label="Legal">
            <ul class="flex flex-wrap gap-6">
                @foreach ($legalLinks as $link)
                    <li><a href="{{ $link['url'] }}" class="no-underline hover:text-sand hover:underline">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>
        @endif
    </div>
</footer>
