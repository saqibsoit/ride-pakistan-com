<?php

declare(strict_types=1);

use App\Actions\Seo\GenerateSitemap;
use App\Jobs\RegenerateSitemap;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed();
    File::delete((string) config('site.sitemap_path'));
});

afterEach(function (): void {
    File::delete((string) config('site.sitemap_path'));
});

describe('robots.txt', function (): void {
    it('blocks all crawling outside production', function (): void {
        config(['site.indexable' => false]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: *\nDisallow: /\n", false)
            ->assertDontSee('Sitemap:');
    });

    it('allows search and named AI crawlers in production, with the sitemap', function (): void {
        config(['site.indexable' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /account", false)
            ->assertSee("User-agent: GPTBot\nAllow: /", false)
            ->assertSee("User-agent: ClaudeBot\nAllow: /", false)
            ->assertSee('Disallow: /checkout')
            ->assertSee('Sitemap: '.route('sitemap'))
            ->assertDontSee('Disallow: /'."\n\n", false)
            ->assertDontSee('test-admin');
    });

    it('never mentions the admin path, even when it is /admin', function (bool $indexable): void {
        config(['site.indexable' => $indexable, 'app.admin_path' => 'admin']);

        expect((string) $this->get('/robots.txt')->getContent())->not->toContain('admin');
    })->with(['production' => true, 'staging' => false]);

    it('sends X-Robots-Tag noindex on every response outside production', function (): void {
        config(['site.indexable' => false]);
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/sitemap.xml')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        config(['site.indexable' => true]);
        $this->get('/prices')->assertHeaderMissing('X-Robots-Tag');
    });
});

describe('sitemap', function (): void {
    it('is generated on first request and served as XML', function (): void {
        $response = $this->get('/sitemap.xml')->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('application/xml')
            ->and(File::exists((string) config('site.sitemap_path')))->toBeTrue()
            ->and((string) file_get_contents((string) config('site.sitemap_path')))->toContain('<urlset');
    });

    it('excludes drafts, unpublished and noindex pages, inactive products and /book', function (): void {
        Page::query()->where('slug', 'contact')->update(['is_indexable' => false]);
        Product::query()->where('slug', 'private-session')->update(['is_active' => false]);

        $xml = app(GenerateSitemap::class)->handle()->render();

        expect($xml)
            ->not->toContain(url('/book'))
            ->not->toContain(url('/privacy'))
            ->not->toContain(url('/contact').'</loc>')
            ->not->toContain('private-session')
            ->not->toContain('/guides');
    });

    it('includes published guides and the guides index', function (): void {
        $post = Post::factory()->create(['slug' => 'beginners-guide']);

        $xml = app(GenerateSitemap::class)->handle()->render();

        expect($xml)->toContain('<loc>'.route('guides.index').'</loc>')
            ->toContain('<loc>'.route('guides.show', $post).'</loc>');
    });

    it('is regenerated when content changes', function (): void {
        Queue::fake();

        Product::query()->firstOrFail()->update(['name' => 'Renamed']);

        Queue::assertPushed(RegenerateSitemap::class);
    });

    it('is regenerated nightly', function (): void {
        $this->artisan('schedule:list')->expectsOutputToContain('sitemap:generate');
        $this->artisan('sitemap:generate')->assertSuccessful();
    });
});

describe('llms.txt', function (): void {
    it('summarises the business from settings, products and schedule', function (): void {
        $text = $this->get('/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        expect($text)
            ->toStartWith('# Ride Pakistan')
            ->toContain('> Ride Pakistan runs weekend horse riding sessions in Islamabad, Pakistan, every Saturday and Sunday, 8–10 am or 3–5 pm, with trainer Omar Saleem.')
            ->toContain('[Single session]('.route('sessions.show', 'single-session').'): PKR 10,000 per rider')
            ->toContain('PKR 60,000 for 8 sessions (PKR 7,500 per session), valid 6 weeks from confirmation')
            ->toContain('PKR 100,000 for 16 sessions')
            ->toContain('[Book a session]('.route('book').')')
            ->toContain('Cancel or reschedule up to 48 hours before your slot.')
            ->toContain('hello@ridepakistan.co');
    });

    it('lists published guides only', function (): void {
        Post::factory()->create(['title' => 'A published guide']);

        $this->get('/llms.txt')
            ->assertSee('A published guide')
            ->assertDontSee('Private vs group riding lessons');
    });
});
