<?php

declare(strict_types=1);

use App\Actions\Images\GenerateResponsiveVariants;
use App\Support\ResponsiveImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
    $this->generate = app(GenerateResponsiveVariants::class);
});

it('creates webp variants at every configured width and a sidecar', function (): void {
    putTestImage('site', 'photos/wide.jpg', 2000, 1000);

    $manifest = $this->generate->handle('site', 'photos/wide.jpg');

    expect($manifest['width'])->toBe(2000)
        ->and($manifest['height'])->toBe(1000)
        ->and(array_column($manifest['variants']['webp'], 'width'))->toBe([480, 768, 1200, 1800])
        ->and(array_column($manifest['variants']['webp'], 'height'))->toBe([240, 384, 600, 900]);

    $disk = Storage::disk('site');
    foreach ([480, 768, 1200, 1800] as $width) {
        $disk->assertExists("photos/_variants/wide-{$width}.webp");
        expect(getimagesizefromstring((string) $disk->get("photos/_variants/wide-{$width}.webp"))[0])->toBe($width);
    }
    $disk->assertExists('photos/_variants/wide.json');
});

it('creates avif variants when GD supports them', function (): void {
    if (! function_exists('imageavif')) {
        $this->markTestSkipped('GD was built without AVIF support.');
    }

    putTestImage('site', 'wide.jpg', 1000, 500);

    $manifest = $this->generate->handle('site', 'wide.jpg');

    expect(array_column($manifest['variants']['avif'], 'width'))->toBe([480, 768]);
    Storage::disk('site')->assertExists('_variants/wide-768.avif');
});

it('never upscales', function (): void {
    putTestImage('site', 'small.jpg', 600, 400);

    $manifest = $this->generate->handle('site', 'small.jpg');

    expect(array_column($manifest['variants']['webp'], 'width'))->toBe([480]);
    Storage::disk('site')->assertMissing('_variants/small-768.webp');
});

it('makes one variant at the source width when the source is smaller than every width', function (): void {
    putTestImage('site', 'tiny.png', 300, 200, 'png');

    $manifest = $this->generate->handle('site', 'tiny.png');

    expect(array_column($manifest['variants']['webp'], 'width'))->toBe([300]);
});

it('skips unchanged sources unless forced', function (): void {
    putTestImage('site', 'photo.jpg', 800, 600);
    $this->generate->handle('site', 'photo.jpg');

    Storage::disk('site')->delete('_variants/photo-480.webp');

    $this->generate->handle('site', 'photo.jpg');
    Storage::disk('site')->assertMissing('_variants/photo-480.webp');

    $this->generate->handle('site', 'photo.jpg', force: true);
    Storage::disk('site')->assertExists('_variants/photo-480.webp');
});

it('regenerates when the source changes', function (): void {
    putTestImage('site', 'photo.jpg', 800, 600);
    $this->generate->handle('site', 'photo.jpg');

    putTestImage('site', 'photo.jpg', 1300, 650);
    $manifest = $this->generate->handle('site', 'photo.jpg');

    expect($manifest['width'])->toBe(1300)
        ->and(array_column($manifest['variants']['webp'], 'width'))->toBe([480, 768, 1200]);
});

it('regenerates over a corrupt sidecar', function (): void {
    putTestImage('site', 'photo.jpg', 800, 600);
    Storage::disk('site')->put('_variants/photo.json', '{"source_hash": 42}');

    $manifest = $this->generate->handle('site', 'photo.jpg');

    expect($manifest['width'])->toBe(800);
    Storage::disk('site')->assertExists('_variants/photo-768.webp');
    expect(ResponsiveImage::parseSidecar((string) Storage::disk('site')->get('_variants/photo.json')))->not->toBeNull();
});

it('ignores a corrupt sidecar when rendering', function (): void {
    putTestImage('site', 'photo.jpg', 800, 600);
    Storage::disk('site')->put('_variants/photo.json', 'not json');

    $image = ResponsiveImage::for('photo.jpg', 'site');

    expect($image->sources)->toBe([])
        ->and($image->width)->toBe(800);
});

it('clears the cached image data for the component', function (): void {
    putTestImage('site', 'photo.jpg', 800, 600);
    ResponsiveImage::for('photo.jpg', 'site');
    expect(Cache::has(ResponsiveImage::cacheKey('site', 'photo.jpg')))->toBeTrue();

    $this->generate->handle('site', 'photo.jpg');

    expect(Cache::has(ResponsiveImage::cacheKey('site', 'photo.jpg')))->toBeFalse()
        ->and(ResponsiveImage::for('photo.jpg', 'site')->sources)->toHaveKey('image/webp');
});

it('rejects unsupported, missing, generated and unreadable files', function (string $path, ?string $contents, string $message): void {
    if ($contents !== null) {
        Storage::disk('site')->put($path, $contents);
    }

    expect(fn () => $this->generate->handle('site', $path))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'pdf' => ['document.pdf', '%PDF-1.4', 'not a supported image type'],
    'missing' => ['missing.jpg', null, 'does not exist'],
    'variant' => ['_variants/photo-480.webp', 'x', 'generated variant'],
    'not an image' => ['fake.jpg', 'definitely not a jpeg', 'not a readable image'],
]);
