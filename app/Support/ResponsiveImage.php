<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Read side of the responsive image pipeline: turns an image path into the
 * src, intrinsic size and per-format srcset strings that <x-img> renders.
 *
 * Uses the sidecar written by GenerateResponsiveVariants. Without one (not
 * generated yet) it falls back to the original file and its measured size,
 * so the page still renders with width/height and no layout shift.
 */
final class ResponsiveImage
{
    /**
     * @param  array<string, string>  $sources  MIME type => srcset
     */
    public function __construct(
        public readonly string $src,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly array $sources,
    ) {}

    public static function for(string $path, ?string $disk = null): self
    {
        $disk ??= (string) config('images.default_disk');
        $path = ltrim($path, '/');

        /** @var array{src: string, width: ?int, height: ?int, sources: array<string, string>} $data */
        $data = Cache::rememberForever(self::cacheKey($disk, $path), fn (): array => self::resolve($disk, $path));

        return new self($data['src'], $data['width'], $data['height'], $data['sources']);
    }

    public static function cacheKey(string $disk, string $path): string
    {
        return 'responsive-image:'.$disk.':'.ltrim($path, '/');
    }

    public static function variantsDirectory(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $variants = (string) config('images.variants_dir');

        return $directory === '.' || $directory === '' ? $variants : $directory.'/'.$variants;
    }

    public static function sidecarPath(string $path): string
    {
        return self::variantsDirectory($path).'/'.pathinfo($path, PATHINFO_FILENAME).'.json';
    }

    public static function variantPath(string $path, int $width, string $format): string
    {
        return self::variantsDirectory($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-'.$width.'.'.$format;
    }

    /**
     * Validate a sidecar's JSON. Returns null for anything malformed, so a
     * corrupt or hand-edited sidecar is regenerated or ignored, never trusted.
     *
     * @return array{source: string, source_hash: string, width: int, height: int, variants: array<string, list<array{width: int, height: int, path: string}>>}|null
     */
    public static function parseSidecar(string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data)
            || ! is_string($data['source'] ?? null)
            || ! is_string($data['source_hash'] ?? null)
            || ! is_int($data['width'] ?? null)
            || ! is_int($data['height'] ?? null)
            || ! is_array($data['variants'] ?? null)) {
            return null;
        }

        $variants = [];

        foreach ($data['variants'] as $format => $list) {
            if (! is_string($format) || ! is_array($list)) {
                return null;
            }

            foreach ($list as $variant) {
                if (! is_array($variant)
                    || ! is_int($variant['width'] ?? null)
                    || ! is_int($variant['height'] ?? null)
                    || ! is_string($variant['path'] ?? null)) {
                    return null;
                }

                $variants[$format][] = [
                    'width' => $variant['width'],
                    'height' => $variant['height'],
                    'path' => $variant['path'],
                ];
            }
        }

        return [
            'source' => $data['source'],
            'source_hash' => $data['source_hash'],
            'width' => $data['width'],
            'height' => $data['height'],
            'variants' => $variants,
        ];
    }

    /**
     * @return array{src: string, width: ?int, height: ?int, sources: array<string, string>}
     */
    private static function resolve(string $disk, string $path): array
    {
        $storage = Storage::disk($disk);
        $src = $storage->url($path);
        $sidecar = self::sidecarPath($path);

        $manifest = $storage->exists($sidecar) ? self::parseSidecar((string) $storage->get($sidecar)) : null;

        if ($manifest !== null) {
            $sources = [];

            foreach ($manifest['variants'] as $format => $variants) {
                $sources['image/'.$format] = implode(', ', array_map(
                    fn (array $variant): string => $storage->url($variant['path']).' '.$variant['width'].'w',
                    $variants,
                ));
            }

            return ['src' => $src, 'width' => $manifest['width'], 'height' => $manifest['height'], 'sources' => $sources];
        }

        [$width, $height] = self::measure($disk, $path);

        return ['src' => $src, 'width' => $width, 'height' => $height, 'sources' => []];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function measure(string $disk, string $path): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return [null, null];
        }

        $size = @getimagesizefromstring((string) $storage->get($path));

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
