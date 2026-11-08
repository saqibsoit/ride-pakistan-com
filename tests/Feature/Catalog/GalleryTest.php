<?php

declare(strict_types=1);

use App\Models\Product;

it('shows the product gallery with alt text, lazy-loaded', function (): void {
    $product = Product::factory()->create([
        'slug' => 'gallery-test',
        'gallery' => [
            ['image' => 'ride.jpg', 'alt' => 'Riders on the trail at sunrise'],
            ['image' => 'ride.jpg', 'alt' => 'Grooming before the ride'],
        ],
    ]);

    $html = (string) $this->get('/sessions/'.$product->slug)->assertOk()->getContent();

    expect($html)->toContain('id="gallery-title"')
        ->toContain('alt="Riders on the trail at sunrise"')
        ->toContain('alt="Grooming before the ride"');

    preg_match('#<section aria-labelledby="gallery-title".*?</section>#s', $html, $section);
    expect(substr_count($section[0], 'loading="lazy"'))->toBe(2);
});

it('leaves the gallery out when there are no photos', function (): void {
    $product = Product::factory()->create(['slug' => 'no-gallery', 'gallery' => null]);

    $this->get('/sessions/'.$product->slug)->assertOk()->assertDontSee('gallery-title', false);
});
