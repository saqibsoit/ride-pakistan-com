<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function scheduledEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));
}

it('runs the database queue from cron every minute, stopping before the next run', function (): void {
    $event = scheduledEvent('queue:work');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and((string) $event->command)->toContain('--stop-when-empty')
        ->and((string) $event->command)->toContain('--max-time=50');
});

it('schedules backups, their cleanup and the weekly health report', function (): void {
    expect(scheduledEvent('backup:clean')?->expression)->toBe('0 1 * * *')
        ->and(scheduledEvent('backup:run')?->expression)->toBe('30 1 * * *')
        ->and(scheduledEvent('backup:monitor')?->expression)->toBe('0 8 * * 1')
        ->and(scheduledEvent('backup:monitor')?->timezone)->toBe('Asia/Karachi');
});

it('keeps the jobs the deployment relies on scheduled', function (string $command, string $expression): void {
    expect(scheduledEvent($command)?->expression)->toBe($expression);
})->with([
    'card holds and transfer deadlines' => ['holds:release', '* * * * *'],
    'slots' => ['slots:generate', '30 2 * * *'],
    'reminders' => ['notifications:send reminders', '*/15 * * * *'],
    'sitemap' => ['sitemap:generate', '15 3 * * *'],
    'failed job pruning' => ['queue:prune-failed', '30 4 * * *'],
]);

it('answers /up only when the database is reachable', function (): void {
    $this->get('/up')->assertOk();

    // The test's own connection keeps its transaction; the default points
    // at a server that isn't there.
    $default = config('database.default');
    config([
        'app.debug' => false,
        'database.connections.broken' => array_merge(config("database.connections.{$default}"), ['host' => '127.0.0.1', 'port' => 1]),
        'database.default' => 'broken',
    ]);

    try {
        $this->get('/up')->assertStatus(500);
    } finally {
        config(['database.default' => $default]);
        DB::purge('broken');
    }
});
