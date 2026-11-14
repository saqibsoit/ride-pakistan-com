<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Support\ResponsiveImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/**
 * Creates AVIF and WebP variants of an image at the configured widths and
 * writes a JSON sidecar that <x-img> reads to build its srcset.
 *
 * Layout for "photos/hero.jpg":
 *   photos/_variants/hero-480.avif, hero-480.webp, ... hero-1800.webp
 *   photos/_variants/hero.json
 *
 * Images are never upscaled. A source narrower than the smallest width gets
 * one variant per format at its own width. Formats the GD build can't encode
 * are skipped with a warning (WebP is still produced).
 */
final class GenerateResponsiveVariants
{
    public function __construct(private readonly ImageManager $images) {}

    /**
     * @return array{source: string, source_hash: string, width: int, height: int, variants: array<string, list<array{width: int, height: int, path: string}>>}
     */
    public function handle(string $disk, string $path, bool $force = false): array
    {
        $storage = Storage::disk($disk);
        $path = ltrim($path, '/');

        $this->guardSource($storage, $path);

        $binary = (string) $storage->get($path);
        $hash = sha1($binary);
        $sidecarPath = ResponsiveImage::sidecarPath($path);

        if (! $force && ($existing = $this->readSidecar($storage, $sidecarPath)) !== null && $existing['source_hash'] === $hash) {
            return $existing;
        }

        try {
            $image = $this->images->decodeBinary($binary);
        } catch (ImageException $e) {
            throw new InvalidArgumentException("[{$path}] is not a readable image.", 0, $e);
        }

        $sourceWidth = $image->width();
        $sourceHeight = $image->height();

        $variants = [];

        foreach ($this->formats($path) as $format) {
            foreach ($this->widthsFor($sourceWidth) as $width) {
                $variant = $this->encode($image, $format, $width);
                $variantPath = ResponsiveImage::variantPath($path, $width, $format);

                $storage->put($variantPath, $variant['binary']);

                $variants[$format][] = [
                    'width' => $variant['width'],
                    'height' => $variant['height'],
                    'path' => $variantPath,
                ];
            }
        }

        $manifest = [
            'source' => $path,
            'source_hash' => $hash,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'variants' => $variants,
        ];

        $storage->put($sidecarPath, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Cache::forget(ResponsiveImage::cacheKey($disk, $path));

        return $manifest;
    }

    private function guardSource(Filesystem $storage, string $path): void
    {
        /** @var list<string> $extensions */
        $extensions = config('images.extensions');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, $extensions, true)) {
            throw new InvalidArgumentException("[{$path}] is not a supported image type.");
        }

        if (str_contains($path, '/'.config('images.variants_dir').'/') || str_starts_with($path, config('images.variants_dir').'/')) {
            throw new InvalidArgumentException("[{$path}] is a generated variant.");
        }

        if (! $storage->exists($path)) {
            throw new InvalidArgumentException("[{$path}] does not exist.");
        }
    }

    /**
     * @return array{source: string, source_hash: string, width: int, height: int, variants: array<string, list<array{width: int, height: int, path: string}>>}|null
     */
    private function readSidecar(Filesystem $storage, string $sidecarPath): ?array
    {
        if (! $storage->exists($sidecarPath)) {
            return null;
        }

        return ResponsiveImage::parseSidecar((string) $storage->get($sidecarPath));
    }

    /**
     * @return list<string>
     */
    private function formats(string $path): array
    {
        /** @var list<string> $configured */
        $configured = config('images.formats');

        return array_values(array_filter($configured, function (string $format) use ($path): bool {
            if ($this->images->driver->supports($format)) {
                return true;
            }

            Log::warning("Image variants: {$format} encoding is not supported by this GD build; skipped for [{$path}].");

            return false;
        }));
    }

    /**
     * @return list<int>
     */
    private function widthsFor(int $sourceWidth): array
    {
        /** @var list<int> $configured */
        $configured = config('images.widths');
        sort($configured);

        $widths = array_values(array_filter($configured, fn (int $width): bool => $width <= $sourceWidth));

        return $widths === [] ? [$sourceWidth] : $widths;
    }

    /**
     * @return array{binary: string, width: int, height: int}
     */
    private function encode(ImageInterface $image, string $format, int $width): array
    {
        $resized = (clone $image)->scaleDown(width: $width);
        $quality = (int) config("images.quality.{$format}");

        $encoded = match ($format) {
            'avif' => $resized->encodeUsingFormat(Format::AVIF, quality: $quality),
            'webp' => $resized->encodeUsingFormat(Format::WEBP, quality: $quality),
            default => throw new InvalidArgumentException("Unsupported variant format [{$format}]."),
        };

        return [
            'binary' => $encoded->toString(),
            'width' => $resized->width(),
            'height' => $resized->height(),
        ];
    }
}
