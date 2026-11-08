<?php

declare(strict_types=1);

it('stores dates in UTC and displays them in Asia/Karachi', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Asia/Karachi')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('uses the English locale and the Ride Pakistan name', function (): void {
    expect(config('app.locale'))->toBe('en')
        ->and(config('app.name'))->toBe('Ride Pakistan');
});

it('uses strict InnoDB utf8mb4 settings for MySQL and MariaDB', function (string $connection): void {
    $config = config("database.connections.{$connection}");

    expect($config['strict'])->toBeTrue()
        ->and($config['engine'])->toBe('InnoDB')
        ->and($config['charset'])->toBe('utf8mb4')
        ->and($config['collation'])->toBe('utf8mb4_unicode_ci');
})->with(['mysql', 'mariadb']);

it('sends session cookies HttpOnly, SameSite=Lax and Secure in production', function (): void {
    $load = function (string $env): array {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $env;

        try {
            return require config_path('session.php');
        } finally {
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $previous;
        }
    };

    $production = $load('production');
    $local = $load('local');

    expect($production['secure'])->toBeTrue()
        ->and($production['http_only'])->toBeTrue()
        ->and($production['same_site'])->toBe('lax')
        ->and($local['secure'])->toBeFalse();
});
