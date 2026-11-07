<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Images\GenerateResponsiveVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateImageVariants extends Command
{
    protected $signature = 'images:responsive
        {path? : One image path on the disk; omit to process every image}
        {--disk= : Filesystem disk (defaults to images.default_disk)}
        {--force : Regenerate even when the source has not changed}';

    protected $description = 'Generate AVIF and WebP responsive variants for public images';

    public function handle(GenerateResponsiveVariants $generate): int
    {
        $disk = (string) ($this->option('disk') ?: config('images.default_disk'));
        $force = (bool) $this->option('force');
        $path = $this->argument('path');

        $paths = is_string($path) && $path !== '' ? [$path] : $this->discover($disk);

        if ($paths === []) {
            $this->components->info("No images found on disk [{$disk}].");

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($paths as $source) {
            try {
                $manifest = $generate->handle($disk, $source, $force);
                $count = array_sum(array_map('count', $manifest['variants']));
                $this->components->twoColumnDetail($source, "<fg=green>{$count} variants</>");
            } catch (Throwable $e) {
                $failed++;
                $this->components->twoColumnDetail($source, '<fg=red>'.$e->getMessage().'</>');
            }
        }

        if ($failed > 0) {
            $this->components->error("{$failed} image(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function discover(string $disk): array
    {
        /** @var list<string> $extensions */
        $extensions = config('images.extensions');
        $variantsDir = (string) config('images.variants_dir');

        return array_values(array_filter(
            Storage::disk($disk)->allFiles(),
            fn (string $file): bool => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)
                && ! in_array($variantsDir, explode('/', $file), true),
        ));
    }
}
