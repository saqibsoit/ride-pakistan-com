<?php

declare(strict_types=1);

use App\Actions\Images\GenerateResponsiveVariants;
use App\Events\ImageUploaded;
use App\Listeners\GenerateVariantsForUploadedImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
});

it('generates variants for every image on the disk, skipping existing variants', function (): void {
    putTestImage('site', 'a.jpg', 900, 600);
    putTestImage('site', 'nested/b.png', 500, 500, 'png');
    Storage::disk('site')->put('notes.txt', 'ignore me');

    $this->artisan('images:responsive')
        ->expectsOutputToContain('a.jpg')
        ->expectsOutputToContain('nested/b.png')
        ->assertSuccessful();

    Storage::disk('site')->assertExists('_variants/a-768.webp');
    Storage::disk('site')->assertExists('nested/_variants/b-480.webp');

    // A second run must not treat generated variants as sources.
    $this->artisan('images:responsive')
        ->doesntExpectOutputToContain('_variants')
        ->assertSuccessful();
});

it('processes a single path on a given disk', function (): void {
    Storage::fake('public');
    putTestImage('public', 'uploads/photo.jpg', 800, 600);

    $this->artisan('images:responsive', ['path' => 'uploads/photo.jpg', '--disk' => 'public'])
        ->assertSuccessful();

    Storage::disk('public')->assertExists('uploads/_variants/photo-480.webp');
});

it('fails when an image cannot be processed', function (): void {
    Storage::disk('site')->put('broken.jpg', 'not an image');

    $this->artisan('images:responsive')
        ->expectsOutputToContain('not a readable image')
        ->assertFailed();
});

it('reports when there is nothing to do', function (): void {
    $this->artisan('images:responsive')
        ->expectsOutputToContain('No images found')
        ->assertSuccessful();
});

it('queues variant generation when an image is uploaded', function (): void {
    Event::fake();

    ImageUploaded::dispatch('site', 'photo.jpg');

    Event::assertListening(ImageUploaded::class, GenerateVariantsForUploadedImage::class);
    expect(new GenerateVariantsForUploadedImage(app(GenerateResponsiveVariants::class)))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('generates variants through the upload hook', function (): void {
    putTestImage('site', 'upload.jpg', 800, 600);

    ImageUploaded::dispatch('site', 'upload.jpg');

    Storage::disk('site')->assertExists('_variants/upload-768.webp');
});
