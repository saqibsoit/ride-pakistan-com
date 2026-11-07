<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

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

        return $app;
    }
}
