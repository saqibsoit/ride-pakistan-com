<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use Throwable;

/**
 * Bank transfer proofs on the private "transfers" disk (PLAN §7).
 *
 * Images are decoded and re-encoded as WebP (GD, metadata stripped), so
 * EXIF data (GPS, device) and anything hidden after the image data are
 * dropped; a file that doesn't decode as an image is refused. PDFs are
 * kept as they are after a content check. Files get random names; the
 * customer's filename is never stored.
 */
final class TransferProofs
{
    public const DISK = 'transfers';

    public const MAX_KILOBYTES = 5120;

    /** Long edge of stored images; enough to read a banking screenshot. */
    public const MAX_EDGE = 2400;

    public function __construct(private readonly ImageManager $images) {}

    /**
     * @return string the stored path
     *
     * @throws InvalidArgumentException the file is not a usable image or PDF
     */
    public function store(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType(); // sniffed from the content
        $contents = (string) file_get_contents((string) $file->getRealPath());

        if ($mime === 'application/pdf') {
            if (! str_starts_with($contents, '%PDF-')) {
                throw new InvalidArgumentException('That file is not a valid PDF.');
            }

            return $this->put($contents, 'pdf');
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Upload a JPG, PNG, WebP or PDF file.');
        }

        try {
            $image = $this->images->read($contents)->scaleDown(width: self::MAX_EDGE, height: self::MAX_EDGE);
            $webp = $this->images->driver()->supports('webp');
            $encoded = ($webp ? $image->toWebp(quality: 85) : $image->toJpeg(quality: 85))->toString();
        } catch (Throwable) {
            throw new InvalidArgumentException('That image could not be read. Upload a clear screenshot or photo.');
        }

        return $this->put($encoded, $webp ? 'webp' : 'jpg');
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public static function isPdf(string $path): bool
    {
        return str_ends_with($path, '.pdf');
    }

    private function put(string $contents, string $extension): string
    {
        $path = now()->format('Y/m').'/'.Str::random(40).'.'.$extension;

        $this->disk()->put($path, $contents);

        return $path;
    }
}
