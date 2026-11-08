<?php

declare(strict_types=1);

namespace App\Actions\Exports;

/** A finished export on local disk; the caller streams it and deletes it. */
final readonly class ExportFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
        public int $rows,
    ) {}
}
