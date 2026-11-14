<?php

declare(strict_types=1);

use App\Actions\Seo\GenerateSitemap;
use App\Actions\Settings\UpdateSettings;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use Carbon\CarbonImmutable;

/*
 * Acceptance criteria for milestone 2: each public page has a unique title
 * and description, one h1, a canonical, valid JSON-LD and is in the sitemap.
 */

beforeEach(function (): void {
    // A Wednesday, so "this weekend" and the schedule have upcoming sessions.
    // Frozen before seeding: the seeder builds slots from "now".
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
    $this->seed();
    config(['site.indexable' => true]);
});

/** Schema.org types the site is allowed to emit (all valid schema.org types). */
const ALLOWED_TYPES = [
    'Organization', 'WebSite', 'SportsActivityLocation', 'Person', 'Product', 'Offer',
    'FAQPage', 'Question', 'Answer', 'BreadcrumbList', 'ListItem', 'Article',
    'City', 'PostalAddress', 'OpeningHoursSpecification',
    // Product pages with at least 5 approved reviews (milestone 10d).
    'AggregateRating', 'Review', 'Rating',
];

dataset('indexable pages', [
    'home' => ['/', 'SportsActivityLocation'],
    'experiences' => ['/experiences', 'BreadcrumbList'],
    'single session' => ['/sessions/single-session', 'Product'],
    'package' => ['/sessions/8-class-package', 'Product'],
    'schedule' => ['/schedule', 'BreadcrumbList'],
    'prices' => ['/prices', 'Product'],
    'faq' => ['/faq', 'FAQPage'],
    'trainer' => ['/trainer/omar-saleem', 'Person'],
    'expeditions' => ['/expeditions', 'BreadcrumbList'],
    'contact' => ['/contact', 'BreadcrumbList'],
]);

it('renders each public page with one h1, canonical, meta tags and valid JSON-LD', function (string $url, string $expectedType): void {
    $html = $this->get($url)->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<link rel="canonical" href="'.url(parse_url($url, PHP_URL_PATH) ?: '/').'">')
        ->and(metaContent($html, 'description'))->not->toBeEmpty()
        ->and(metaContent($html, 'robots'))->toBe('index, follow, max-image-preview:large')
        ->and(metaContent($html, 'og:title'))->not->toBeEmpty()
        ->and(metaContent($html, 'og:image'))->toStartWith('http')
        ->and(metaContent($html, 'twitter:card'))->toBe('summary_large_image');

    $graph = jsonLdGraph($html);
    $types = jsonLdTypes($graph);

    expect($types)->toContain('Organization', 'WebSite', $expectedType)
        ->and(array_diff($types, ALLOWED_TYPES))->toBe([]);
})->with('indexable pages');

it('gives every public page a unique title and description', function (): void {
    $titles = [];
    $descriptions = [];

    foreach (['/', '/experiences', '/sessions/single-session', '/sessions/private-session', '/sessions/8-class-package', '/sessions/16-class-package', '/schedule', '/prices', '/faq', '/trainer/omar-saleem', '/expeditions', '/contact', '/guides'] as $url) {
        $html = $this->get($url)->getContent();
        preg_match('#<title>(.*?)</title>#', $html, $m);
        $titles[$url] = $m[1] ?? '';
        $descriptions[$url] = metaContent($html, 'description');
    }

    expect(array_unique($titles))->toHaveCount(count($titles))
        ->and(array_unique($descriptions))->toHaveCount(count($descriptions));
});

it('lists every indexable public page in the sitemap', function (): void {
    $xml = app(GenerateSitemap::class)->handle()->render();

    foreach (['/', '/experiences', '/schedule', '/prices', '/faq', '/contact', '/trainer/omar-saleem', '/expeditions'] as $path) {
        expect($xml)->toContain('<loc>'.url($path).'</loc>');
    }

    foreach (Product::all() as $product) {
        expect($xml)->toContain('<loc>'.route('sessions.show', $product).'</loc>');
    }
});

it('opens key pages with a factual answer built from live data', function (): void {
    $this->get('/')->assertSeeInOrder(['Islamabad', 'every Saturday and Sunday, 8–10 am or 3–5 pm', 'Omar Saleem', 'PKR 10,000 per rider']);
    $this->get('/prices')->assertSee('start at PKR 10,000 per rider');
    $this->get('/sessions/8-class-package')->assertSee('PKR 60,000 for 8 sessions (PKR 7,500 per session), valid 6 weeks from confirmation');
});

it('shows prices, packs and validity from products', function (): void {
    $this->get('/prices')
        ->assertSeeInOrder(['Single session', 'PKR 10,000', 'Private session', 'PKR 15,000'])
        ->assertSee('PKR 60,000')
        ->assertSee('PKR 100,000')
        ->assertSee('PKR 6,250 per session')
        ->assertSee('16 sessions, valid 10 weeks from confirmation.')
        ->assertSee('Riding rules.')
        ->assertSee('Cancel or reschedule up to 48 hours before your slot.');
});

it('reflects price changes everywhere at once', function (): void {
    Product::query()->where('slug', 'single-session')->firstOrFail()->update(['price_minor' => 1_200_000]);

    $this->get('/prices')->assertSee('PKR 12,000')->assertDontSee('PKR 10,000');
    $this->get('/faq')->assertSee('A single 2-hour session is PKR 12,000 per rider.');
    $this->get('/llms.txt')->assertSee('PKR 12,000 per rider');
});

it('emits a PKR offer for each product', function (): void {
    $graph = jsonLdGraph($this->get('/sessions/16-class-package')->getContent());
    $product = collect($graph)->firstWhere('@type', 'Product');

    expect($product['name'])->toBe('16-class package')
        ->and($product['offers']['@type'])->toBe('Offer')
        ->and($product['offers']['price'])->toBe('100000.00')
        ->and($product['offers']['priceCurrency'])->toBe('PKR')
        ->and($product['offers']['availability'])->toBe('https://schema.org/InStock');
});

it('publishes opening hours from schedule rules and no street address', function (): void {
    $graph = jsonLdGraph($this->get('/')->getContent());
    $location = collect($graph)->firstWhere('@type', 'SportsActivityLocation');

    expect($location['areaServed']['name'])->toBe('Islamabad')
        ->and($location['address'])->toBe(['@type' => 'PostalAddress', 'addressLocality' => 'Islamabad', 'addressCountry' => 'PK'])
        ->and($location['openingHoursSpecification'])->toHaveCount(2)
        ->and($location['openingHoursSpecification'][0]['dayOfWeek'])->toBe(['https://schema.org/Saturday', 'https://schema.org/Sunday'])
        ->and($location['openingHoursSpecification'][0]['opens'])->toBe('08:00')
        ->and($location['openingHoursSpecification'][0]['closes'])->toBe('10:00');
});

it('builds FAQPage data with tokens filled in', function (): void {
    $graph = jsonLdGraph($this->get('/faq')->getContent());
    $faq = collect($graph)->firstWhere('@type', 'FAQPage');
    $answers = collect($faq['mainEntity'])->pluck('acceptedAnswer.text')->implode(' ');

    expect($faq['mainEntity'])->not->toBeEmpty()
        ->and($answers)->toContain('PKR 15,000 per rider')
        ->and($answers)->not->toContain('{');
});

it('marks guides up as Articles by the trainer', function (): void {
    $post = Post::factory()->create(['title' => 'What to wear riding', 'slug' => 'what-to-wear']);

    $html = $this->get('/guides/what-to-wear')->assertOk()->getContent();
    $article = collect(jsonLdGraph($html))->firstWhere('@type', 'Article');

    expect($article['headline'])->toBe('What to wear riding')
        ->and($article['author']['name'])->toBe('Omar Saleem')
        ->and($article['datePublished'])->toBe($post->published_at?->format(DATE_ATOM));
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('hides draft guides and keeps an empty guides index out of search', function (): void {
    expect(Post::query()->whereNull('published_at')->count())->toBe(3);

    $this->get('/guides/what-to-wear-first-horse-riding-lesson')->assertNotFound();

    $html = $this->get('/guides')->assertOk()->getContent();
    expect(metaContent($html, 'robots'))->toBe('noindex, follow');
});

it('returns 404 for unpublished legal pages and inactive products', function (): void {
    Page::query()->whereIn('slug', ['privacy', 'terms', 'refund-policy'])->update(['is_published' => false]);

    $this->get('/privacy')->assertNotFound();
    $this->get('/terms')->assertNotFound();
    $this->get('/refund-policy')->assertNotFound();

    Product::query()->where('slug', 'private-session')->update(['is_active' => false]);
    $this->get('/sessions/private-session')->assertNotFound();
});

it('serves the published legal pages (seeded from database/data/legal)', function (): void {
    $this->get('/privacy')->assertOk()->assertSee('Privacy Policy');
    $this->get('/terms')->assertOk()->assertSee('Terms and Conditions');
    $this->get('/refund-policy')->assertOk()->assertSee('Cancellation and Refund Policy');
});

it('filters the catalog with chips that work without JavaScript', function (): void {
    $this->get('/experiences?category=packages')
        ->assertOk()
        ->assertSee('8-class package')
        ->assertDontSee('Private session</h3>', false)
        ->assertDontSee('Riding expeditions in the north');

    $this->get('/experiences?category=expeditions')
        ->assertSee('Riding expeditions in the north')
        ->assertDontSee('8-class package</h3>', false);

    $this->get('/experiences')->assertSee('aria-current="true"', false);
});

it('shows this weekend and the week calendar from live slots', function (): void {
    $this->get('/')
        ->assertSee('This weekend.')
        ->assertSee('Saturday 14 Nov')
        ->assertSee('6 spots left')
        ->assertDontSee('Seats shown when online booking opens');

    $this->get('/schedule')
        ->assertOk()
        ->assertSee('This week')
        ->assertSee('8:00 am – 10:00 am')
        ->assertSee('3:00 pm – 5:00 pm');
});

it('pages through a bounded range of schedule weeks', function (): void {
    $this->get('/schedule?week=2026-11-16')->assertOk()->assertSee('Week of');
    $this->get('/schedule?week=2020-01-06')->assertRedirect('/schedule');
    $this->get('/schedule?week=2027-06-07')->assertRedirect('/schedule');
    $this->get('/schedule?week=2026-11-17')->assertRedirect('/schedule'); // not a Monday
    $this->get('/schedule?week=not-a-date')->assertRedirect('/schedule');
    $this->get('/schedule?week[]=x')->assertRedirect('/schedule');
});

it('serves the booking flow without indexing it, starting at the chosen product', function (): void {
    $html = $this->get('/book?product=private-session')->assertOk()->getContent();

    expect(metaContent($html, 'robots'))->toBe('noindex, follow')
        ->and($html)->toContain('Showing slots nobody has booked yet');
});

it('never shows bank details on public pages', function (): void {
    app(UpdateSettings::class)->handle(['bank.iban' => 'PK00TEST0000000000000000']);

    foreach (['/', '/prices', '/sessions/single-session', '/contact', '/book', '/llms.txt'] as $url) {
        $this->get($url)->assertDontSee('PK00TEST0000000000000000');
    }
});

it('makes no unconfirmed safety or policy claims (docs/OPEN-QUESTIONS.md)', function (string $url): void {
    $html = strtolower(strip_tags((string) $this->get($url)->getContent()));

    // Helmets were decided on 2026-11-10 (prompt 14: riders bring their own). Weather, minors and
    // photos are decided by the published legal texts (2026-11-11; the refund policy is on /prices).
    foreach (['transport', 'safe for beginners', 'within a day', '15 minutes', 'most popular', 'multi-day'] as $claim) {
        expect($html)->not->toContain($claim);
    }
})->with(['/', '/experiences', '/sessions/single-session', '/sessions/private-session', '/sessions/8-class-package', '/sessions/16-class-package', '/schedule', '/prices', '/faq', '/contact', '/expeditions', '/book', '/llms.txt']);
