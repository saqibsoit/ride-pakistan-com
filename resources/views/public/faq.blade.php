{{-- /faq: all published general FAQs, grouped by topic. --}}
<x-layouts.app :seo="$seo">
    <x-page-header
        title="Questions."
        :breadcrumbs="[['label' => 'Home', 'url' => route('home')], ['label' => 'FAQ']]"
        :answer="'Ride Pakistan runs beginner-friendly weekend horse riding sessions in '.$facts->location().', '.$facts->scheduleText().'. Below are straight answers about what to expect, what to wear, prices, packages and booking.'"
    />

    <div class="wrap max-w-narrow pb-section">
        @forelse ($groups as $topic => $faqs)
            <section class="mb-16" aria-labelledby="topic-{{ $loop->index }}">
                <h2 id="topic-{{ $loop->index }}" class="mb-6 text-display-md">{{ $topic }}</h2>
                @foreach ($faqs as $faq)
                    <x-faq-item :question="$faq->question">{{ $faq->answer }}</x-faq-item>
                @endforeach
            </section>
        @empty
            <p class="text-sand-dim">No questions yet. <a href="{{ route('contact') }}" class="underline underline-offset-4">Ask us anything</a>.</p>
        @endforelse

        <p class="text-sand-dim">Didn’t find your answer? <a href="{{ route('contact') }}" class="underline underline-offset-4">Send us a message</a>.</p>
    </div>
</x-layouts.app>
