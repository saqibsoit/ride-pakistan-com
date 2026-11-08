<?php

declare(strict_types=1);

/*
 * Production runs `php artisan optimize` (config, routes, views, events;
 * PLAN §9, docs/DEPLOY.md). Running it here would leave cache files behind
 * for the rest of the suite, so this checks the part that has broken it
 * before: config must export cleanly (no closures). The route, view and
 * event caches were built for real when this was written (docs/PERFORMANCE.md).
 */

it('has config that exports cleanly, so the config cache builds', function (): void {
    $exported = var_export(config()->all(), true);

    expect($exported)->not->toContain('Closure::__set_state')
        ->and(eval('return '.$exported.';'))->toBeArray();
});
