<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Stripe\StripeGateway;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\ParallelTesting;
use RuntimeException;
use Tests\Support\FakeStripeGateway;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the app, then refuse to continue unless the default connection
     * points at a *_testing database. This runs before RefreshDatabase, so a
     * misconfigured run (cached config, wrong env) can never wipe dev data.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $config = $app->make('config');
        $connection = (string) $config->get('database.default');
        $database = (string) $config->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: database "%s" on connection "%s" does not end with "_testing".',
                $database,
                $connection,
            ));
        }

        // Never talk to the real Stripe from tests (even with keys in .env).
        $app->instance(StripeGateway::class, new FakeStripeGateway);

        // Always render built assets: point Vite at a hot file that never
        // exists, so a running `npm run dev` (public/hot) can't change what
        // the tests see.
        $app->make(Vite::class)->useHotFile(storage_path('framework/testing/hot'));

        // In a parallel run, one sitemap file per worker. Seeding dispatches
        // RegenerateSitemap (sync in tests) and SeoFilesTest deletes the
        // file, so workers sharing one path raced; on Windows a write while
        // another worker held the file failed with "Permission denied".
        $token = $app->make(ParallelTesting::class)->token();

        if ($token !== false) {
            $path = (string) $config->get('site.sitemap_path');
            $config->set('site.sitemap_path', preg_replace('/(\.xml)?$/', "-{$token}$1", $path, 1));
        }

        return $app;
    }

    protected function stripe(): FakeStripeGateway
    {
        $gateway = $this->app?->make(StripeGateway::class);

        if (! $gateway instanceof FakeStripeGateway) {
            throw new RuntimeException('The fake Stripe gateway is not bound.');
        }

        return $gateway;
    }
}
