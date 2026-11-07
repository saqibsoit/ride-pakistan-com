<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GD driver; EXIF orientation applied and metadata stripped on encode.
        $this->app->singleton(ImageManager::class, fn (): ImageManager => ImageManager::gd(
            autoOrientation: true,
            strip: true,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
