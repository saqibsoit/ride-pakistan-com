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
