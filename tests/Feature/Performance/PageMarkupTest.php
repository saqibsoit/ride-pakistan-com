<?php

declare(strict_types=1);

use App\Models\Post;
use App\Support\Turnstile;
use Carbon\CarbonImmutable;

/*
 * Front-end performance markup (milestone 11): the LCP image is preloaded
 * with high priority, both fonts are preloaded, and the /prices question
 * form doesn't download Turnstile up front. All without inline script.
 */

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

it('preloads the hero image in the head with high priority', function (): void {
    $html = (string) $this->get('/')->getContent();
    $head = (string) strstr($html, '</head>', true);

    expect($head)->toMatch('#<link rel="preload" as="image" type="image/avif" imagesrcset="[^"]*hero[^"]*" imagesizes="100vw" fetchpriority="high">#')
        ->and(substr_count($head, 'rel="preload" as="image"'))->toBe(1);

    // The hero image itself: eager and high priority, never lazy.
    expect($html)->toMatch('#<img\s+src="[^"]*hero[^"]*"[^>]*loading="eager"\s+fetchpriority="high"#');
});

it('preloads both fonts on every page', function (string $url): void {
    $head = (string) strstr((string) $this->get($url)->getContent(), '</head>', true);

    expect($head)->toMatch('#<link rel="preload" href="[^"]*big-shoulders-display[^"]*\.woff2" as="font" type="font/woff2" crossorigin>#')
        ->and($head)->toMatch('#<link rel="preload" href="[^"]*instrument-sans[^"]*\.woff2" as="font" type="font/woff2" crossorigin>#');
})->with(['/', '/prices', '/faq']);

it('gives the product and guide cover images high priority', function (): void {
    expect((string) $this->get('/sessions/single-session')->getContent())->toContain('fetchpriority="high"');

    $post = Post::query()->whereNotNull('cover_image')->firstOrFail();
    $post->forceFill(['published_at' => now()->subDay()])->save();

    expect((string) $this->get('/guides/'.$post->slug)->getContent())->toContain('fetchpriority="high"');
});

it('loads Turnstile on the lead form only when the form is used', function (): void {
    config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret']);

    $html = (string) $this->get('/prices')->getContent();

    expect($html)->not->toContain('<script src="'.Turnstile::SCRIPT_URL)
        ->and($html)->toContain('x-data="turnstile" data-src="'.Turnstile::SCRIPT_URL.'"');
});
