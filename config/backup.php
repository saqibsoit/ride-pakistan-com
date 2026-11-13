<?php

declare(strict_types=1);

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| Backups (milestone 12, docs/DEPLOY.md)
|--------------------------------------------------------------------------
|
| Daily at 01:30 UTC (routes/console.php): the database plus the files that
| exist only on the server (transfer proofs, private files, images uploaded
| in the admin panel). Code, vendor and built assets are in the release and
| are never backed up. The zip is AES-256 encrypted with
| BACKUP_ARCHIVE_PASSWORD and kept 30 days on the "backups" disk, which is
| outside the web root. Decided 2026-11-08: no off-site copy yet; download
| one by hand every week (DEPLOY.md) until an S3-compatible bucket is added.
|
*/

$name = env('BACKUP_NAME', env('APP_NAME', 'ride-pakistan'));

return [

    'backup' => [
        'name' => $name,

        'source' => [
            'files' => [
                // Paths are resolved with realpath(), so the shared folders
                // that releases link to (storage, uploads) are read directly.
                'include' => [
                    storage_path('app/transfers'),
                    storage_path('app/private'),
                    storage_path('app/public'),
                    public_path('images/uploads'),
                ],

                'exclude' => [
                    storage_path('app/private/backups'),
                    storage_path('app/backups'),
                    storage_path('app/backup-temp'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => false,

                // Full paths inside the zip: the included folders have no
                // common parent once they are shared between releases.
                'relative_path' => null,
            ],

            'databases' => [
                env('DB_CONNECTION', 'mariadb'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => [
                'backups',
            ],
            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // Required in staging and production (DEPLOY.md). Lose it and the
        // backups can't be opened: keep it in the password manager.
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default', // AES-256 when a password is set

        'verify_backup' => true,
        'tries' => 2,
        'retry_delay' => 60,
    ],

    /*
     * Failures and the weekly health report only; a successful nightly run
     * sends nothing. The package looks up every notification it sends in
     * this list and crashes on a missing key, so the successful ones stay
     * listed with no channels rather than being left out.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            // An empty value (as in .env.example) falls back too: the package
            // refuses to boot with an invalid address.
            'to' => env('BACKUP_NOTIFICATION_EMAIL') ?: env('MAIL_FROM_ADDRESS') ?: 'hello@example.com',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Ride Pakistan'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    /*
     * backup:monitor (weekly): unhealthy when the newest backup is older
     * than a day or the backups use more than 5 GB.
     */
    'monitor_backups' => [
        [
            'name' => $name,
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    /*
     * 30-day retention: every backup is kept for 30 days, then deleted.
     */
    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 30,
            'keep_daily_backups_for_days' => 0,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];
