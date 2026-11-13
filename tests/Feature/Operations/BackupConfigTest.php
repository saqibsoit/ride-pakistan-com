<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

it('backs up the database and the files that exist only on the server', function (): void {
    $include = config('backup.backup.source.files.include');

    expect($include)->toContain(storage_path('app/transfers'))
        ->toContain(storage_path('app/private'))
        ->toContain(public_path('images/uploads'))
        ->not->toContain(base_path())
        ->and(config('backup.backup.source.databases'))->toBe([config('database.default')]);

    foreach ($include as $path) {
        expect($path)->not->toStartWith(base_path('vendor'))
            ->and($path)->not->toBe(base_path('.env'));
    }
});

it('encrypts the archive and writes it outside the web root', function (): void {
    config(['backup.backup.password' => 'secret']);

    expect(config('backup.backup.encryption'))->toBe('default')
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.root'))->not->toStartWith(public_path())
        ->and(config('filesystems.disks.backups.serve'))->toBeFalse();
});

it('keeps every backup for 30 days and nothing longer', function (): void {
    expect(config('backup.cleanup.default_strategy'))->toMatchArray([
        'keep_all_backups_for_days' => 30,
        'keep_daily_backups_for_days' => 0,
        'keep_weekly_backups_for_weeks' => 0,
        'keep_monthly_backups_for_months' => 0,
        'keep_yearly_backups_for_years' => 0,
    ]);
});

it('emails failures and the weekly health report, not every successful run', function (): void {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupHasFailedNotification::class])->toBe(['mail'])
        ->and($notifications[CleanupHasFailedNotification::class])->toBe(['mail'])
        ->and($notifications[UnhealthyBackupWasFoundNotification::class])->toBe(['mail'])
        ->and($notifications[HealthyBackupWasFoundNotification::class])->toBe(['mail'])
        ->and($notifications[BackupWasSuccessfulNotification::class])->toBe([])
        ->and($notifications[CleanupWasSuccessfulNotification::class])->toBe([])
        ->and(config('backup.monitor_backups'))->toHaveCount(1)
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups']);
});

// The package reads notifications[<class>] for every notification it sends
// and crashed backup:clean every night on the missing successful entry.
it('lists every notification the package can send, so none of them crashes', function (): void {
    $map = (new ReflectionProperty(EventHandler::class, 'eventToNotificationMap'))->getValue();

    expect($map)->not->toBeEmpty();

    foreach ($map as $notification) {
        expect(config('backup.notifications.notifications'))->toHaveKey($notification);
    }
});

it('sends nothing for a successful backup or cleanup, and does not throw', function (object $event): void {
    Notification::fake();

    event($event);

    Notification::assertNothingSent();
})->with([
    'backup' => fn (): BackupWasSuccessful => new BackupWasSuccessful('backups', 'Ride Pakistan'),
    'cleanup' => fn (): CleanupWasSuccessful => new CleanupWasSuccessful('backups', 'Ride Pakistan'),
]);

it('still emails a failed backup or cleanup', function (object $event, string $notification): void {
    Notification::fake();

    event($event);

    Notification::assertSentTo(app(Notifiable::class), $notification, function (object $sent, array $channels): bool {
        return $channels === ['mail'];
    });
})->with([
    'backup' => [fn (): BackupHasFailed => new BackupHasFailed(new RuntimeException('dump failed')), BackupHasFailedNotification::class],
    'cleanup' => [fn (): CleanupHasFailed => new CleanupHasFailed(new RuntimeException('disk full')), CleanupHasFailedNotification::class],
]);

it('falls back to defaults when optional keys are left empty, as in .env.example', function (): void {
    putenv('BACKUP_NOTIFICATION_EMAIL=');
    putenv('BACKUP_DISK_ROOT=');
    putenv('TRUSTED_PROXIES=');

    try {
        $backup = require config_path('backup.php');
        $filesystems = require config_path('filesystems.php');
        $security = require config_path('security.php');
    } finally {
        putenv('BACKUP_NOTIFICATION_EMAIL');
        putenv('BACKUP_DISK_ROOT');
        putenv('TRUSTED_PROXIES');
    }

    // spatie/laravel-backup refuses to boot with an invalid address.
    expect(filter_var($backup['notifications']['mail']['to'], FILTER_VALIDATE_EMAIL))->not->toBeFalse()
        ->and($filesystems['disks']['backups']['root'])->toBe(storage_path('app/backups'))
        ->and($security['trusted_proxies'])->toContain('173.245.48.0/20')
        ->and(count($security['trusted_proxies']))->toBeGreaterThan(20);
});
