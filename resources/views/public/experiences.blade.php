{{-- /experiences: catalog with filter chips (inspiration 03). --}}
@php
    use App\Enums\ProductCategory;
    use App\Http\Controllers\Public\BookController;
    use App\Http\Controllers\Public\ExperienceController;
    $settings = app(\App\Support\Settings::class);
    $from = $facts->fromPriceMinor();
    $answer = 'Ride with us in '.$facts->location().' '.$facts->scheduleText().': a single session'
        .($from !== null ? ' from '.\App\Support\Money::format($from).' per rider' : '')
        .', a private session for your own group, or an 8 or 16-class package. When you’re ready for more, our expeditions ride the mountains of northern Pakistan.';
@endphp

<x-layouts.app :seo="$seo">
    <x-page-header
        title="Ways to ride."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'Experiences']]"
        :answer="$answer"
    />

    <section class="wrap pb-section" aria-labelledby="catalog-title">
        <h2 id="catalog-title" class="sr-only">All experiences</h2>
        <x-filter-chips label="Filter experiences" class="mb-10">
            <x-filter-chip :href="route('experiences')" :active="$active === null">All</x-filter-chip>
            @foreach (ProductCategory::cases() as $category)
                <x-filter-chip :href="route('experiences', ['category' => $category->value])" :active="$active === $category->value">{{ $category->label() }}</x-filter-chip>
            @endforeach
            <x-filter-chip :href="route('experiences', ['category' => ExperienceController::EXPEDITIONS])" :active="$active === ExperienceController::EXPEDITIONS">Expeditions</x-filter-chip>
        </x-filter-chips>

        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                <li class="flex">
                    <x-experience-card
                        class="w-full"
                        :title="$product->name"
                        :href="route('sessions.show', $product)"
                        :book-href="BookController::urlFor($product)"
                        :image="$product->cover_image ?? 'ride.jpg'"
                        :image-alt="(string) $product->cover_image_alt"
                        :category="$product->category->label()"
                        :description="$product->short_description"
                        :price-minor="$product->price_minor"
                        :price-unit="$product->isPackage() ? 'for '.$product->sessions_count.' sessions' : 'per rider'"
                        :featured="$product->is_featured"
                        :duration="$product->isPackage() ? $product->sessions_count.' × '.$product->durationLabel() : $product->durationLabel()"
                        :group-size="$facts->groupSize($product)"
                        :location="$facts->location()"
                        :video-href="$product->video_url"
                    />
                </li>
            @endforeach

            @if ($showExpeditions)
                <li class="flex">
                    <article class="flex w-full flex-col overflow-hidden rounded-sm border border-line bg-earth-2">
                        <x-img src="group.jpg" alt="Riders on horseback in front of a mountain" sizes="(min-width: 64rem) 24rem, (min-width: 40rem) 50vw, 100vw" class="aspect-4/3 w-full object-cover" />
                        <div class="flex flex-1 flex-col gap-3 p-6">
                            <p class="text-xs font-semibold uppercase tracking-label text-sand-dim">Expeditions</p>
                            <h3 class="text-display-sm">Riding expeditions in the north</h3>
                            <p class="line-clamp-2 text-sand-dim">Ride Pakistan expeditions through the northern valleys of Pakistan.</p>
                            <div class="mt-auto flex flex-wrap gap-3 pt-3">
                                <x-button :href="route('expeditions')" variant="ghost" size="sm">Details<span class="sr-only"> about expeditions</span></x-button>
                                <x-button :href="$settings->string(\App\Enums\SettingKey::ExpeditionsUrl)" size="sm" external>See expeditions</x-button>
                            </div>
                        </div>
                    </article>
                </li>
            @endif
        </ul>

        @if ($products->isEmpty() && ! $showExpeditions)
            <p class="text-sand-dim">Nothing in this category right now. <a href="{{ route('experiences') }}" class="underline underline-offset-4">See all experiences</a>.</p>
        @endif
    </section>

    <section class="border-y border-line py-16" aria-labelledby="why-title">
        <div class="wrap">
            <h2 id="why-title" class="mb-10 text-display-md">Why ride with us.</h2>
            <dl class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="font-display text-display-xs font-extrabold">A real trainer</dt>
                    <dd class="mt-2 text-sand-dim">{{ $facts->trainer() }} leads your session and stays with you throughout.</dd>
                </div>
                <div>
                    <dt class="font-display text-display-xs font-extrabold">Horses come first</dt>
                    <dd class="mt-2 text-sand-dim">You groom, tack up and cool down your horse. Trust is earned, not forced.</dd>
                </div>
                <div>
                    <dt class="font-display text-display-xs font-extrabold">Out on the trail</dt>
                    <dd class="mt-2 text-sand-dim">Mostly trail riding in the open, with arena work when you want it.</dd>
                </div>
                <div>
                    <dt class="font-display text-display-xs font-extrabold">Beginners welcome</dt>
                    <dd class="mt-2 text-sand-dim">No experience needed. The sessions are made for complete beginners.</dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="wrap py-section" aria-labelledby="help-title">
        <div class="flex flex-col items-start gap-6 md:flex-row md:items-end md:justify-between">
            <div>
                <h2 id="help-title" class="text-display-lg">Not sure which to choose?</h2>
                <p class="mt-4 max-w-prose text-lede text-sand-dim">Send us a DM with your experience and dates, and we’ll point you to the right session.</p>
            </div>
            <x-button :href="$settings->string(\App\Enums\SettingKey::InstagramDmUrl)" external>Message us on Instagram</x-button>
        </div>
    </section>
</x-layouts.app>
