<?php

declare(strict_types=1);

use App\Actions\Images\GenerateResponsiveVariants;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
});

it('falls back to the original with measured dimensions when no variants exist', function (): void {
    putTestImage('site', 'photo.jpg', 640, 480);

    $this->blade('<x-img src="photo.jpg" alt="A horse" />')
        ->assertSee('alt="A horse"', false)
        ->assertSee('width="640"', false)
        ->assertSee('height="480"', false)
        ->assertSee('loading="lazy"', false)
        ->assertSee('decoding="async"', false)
        ->assertDontSee('<source', false);
});

it('renders AVIF and WebP sources from generated variants', function (): void {
    putTestImage('site', 'photo.jpg', 2000, 1000);
    app(GenerateResponsiveVariants::class)->handle('site', 'photo.jpg');

    $view = $this->blade('<x-img src="photo.jpg" alt="" sizes="50vw" class="w-full" />')
        ->assertSee('type="image/webp"', false)
        ->assertSee('_variants/photo-480.webp 480w', false)
        ->assertSee('_variants/photo-1800.webp 1800w', false)
        ->assertSee('sizes="50vw"', false)
        ->assertSee('width="2000"', false)
        ->assertSee('height="1000"', false)
        ->assertSee('class="w-full"', false);

    if (function_exists('imageavif')) {
        $view->assertSeeInOrder(['type="image/avif"', 'type="image/webp"'], false);
    }
});

it('loads priority images eagerly with high fetch priority', function (): void {
    putTestImage('site', 'hero.jpg', 800, 600);

    $this->blade('<x-img src="hero.jpg" alt="" priority />')
        ->assertSee('loading="eager"', false)
        ->assertSee('fetchpriority="high"', false)
        ->assertDontSee('loading="lazy"', false);
});

it('loads eager images without raising priority', function (): void {
    putTestImage('site', 'logo.png', 600, 355, 'png');

    $this->blade('<x-img src="logo.png" alt="" eager />')
        ->assertSee('loading="eager"', false)
        ->assertDontSee('fetchpriority', false);
});

it('renders a missing image without dimensions instead of failing', function (): void {
    $this->blade('<x-img src="missing.jpg" alt="Missing" />')
        ->assertSee('src="/images/missing.jpg"', false)
        ->assertDontSee('width=', false);
});
