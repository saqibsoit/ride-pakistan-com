<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\ImageUploaded;
use App\Events\PublicContentChanged;
use Illuminate\Database\Eloquent\Model;

/**
 * Attached to every model that feeds public pages (#[ObservedBy]).
 * Refreshes caches, and sends newly uploaded images to the responsive
 * variant pipeline (cover images, OG images, image blocks in "body").
 */
final class PublicContentObserver
{
    private const IMAGE_ATTRIBUTES = ['cover_image', 'og_image'];

    public function saved(Model $model): void
    {
        foreach ($this->newUploads($model) as $path) {
            ImageUploaded::dispatch('site', $path);
        }

        PublicContentChanged::dispatch();
    }

    public function deleted(Model $model): void
    {
        PublicContentChanged::dispatch();
    }

    /**
     * @return list<string>
     */
    private function newUploads(Model $model): array
    {
        $paths = [];

        foreach (self::IMAGE_ATTRIBUTES as $attribute) {
            if ($model->wasChanged($attribute) || $model->wasRecentlyCreated) {
                $paths[] = $model->getAttribute($attribute);
            }
        }

        if ($model->wasChanged('body') || $model->wasRecentlyCreated) {
            $body = $model->getAttribute('body');

            foreach (is_array($body) ? $body : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'image') {
                    $paths[] = $block['data']['image'] ?? null;
                }
            }
        }

        // Only admin uploads; shipped photos already have variants from the build.
        return array_values(array_unique(array_filter(
            $paths,
            fn (mixed $path): bool => is_string($path) && str_starts_with($path, 'uploads/'),
        )));
    }
}
