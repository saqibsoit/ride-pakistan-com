<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;

/*
 * Files the app writes during tests must not be shared between parallel
 * workers. The sitemap was: QueryBudgetTest failed on Windows with
 * "file_put_contents(...sitemap.xml): Permission denied" when another
 * worker held the same file.
 */

it('gives each parallel worker its own sitemap file', function (): void {
    $before = $_SERVER['TEST_TOKEN'] ?? null;

    try {
        $_SERVER['TEST_TOKEN'] = '3';
        $worker3 = (string) $this->createApplication()->make('config')->get('site.sitemap_path');

        $_SERVER['TEST_TOKEN'] = '4';
        $worker4 = (string) $this->createApplication()->make('config')->get('site.sitemap_path');

        unset($_SERVER['TEST_TOKEN']);
        $serial = (string) $this->createApplication()->make('config')->get('site.sitemap_path');
    } finally {
        // Booting an app points the facades at it; give them back.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        if ($before === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $before;
        }
    }

    expect($serial)->toEndWith('sitemap.xml')
        ->and($worker3)->toBe(substr($serial, 0, -4).'-3.xml')
        ->and($worker4)->toBe(substr($serial, 0, -4).'-4.xml');
});
