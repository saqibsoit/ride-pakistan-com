<?php

declare(strict_types=1);

use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
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

    expect($notifications)->toHaveKey(BackupHasFailedNotification::class)
        ->toHaveKey(UnhealthyBackupWasFoundNotification::class)
        ->toHaveKey(HealthyBackupWasFoundNotification::class)
        ->not->toHaveKey(BackupWasSuccessfulNotification::class)
        ->and(config('backup.monitor_backups'))->toHaveCount(1)
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups']);
});

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
