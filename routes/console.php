<?php

declare(strict_types=1);

use App\Models\LoginCode;
use Illuminate\Support\Facades\Schedule;

// Nightly safety net; content changes also regenerate it straight away.
Schedule::command('sitemap:generate')->dailyAt('03:15')->withoutOverlapping();

// Keep the slot horizon filled (booking.slot_horizon_weeks). Idempotent.
Schedule::command('slots:generate')->dailyAt('02:30')->withoutOverlapping();

// Deleted accounts past the grace and retention periods (PLAN §7).
Schedule::command('accounts:anonymise')->dailyAt('03:45')->withoutOverlapping();

// Expired one-time sign-in codes.
Schedule::command('model:prune', ['--model' => [LoginCode::class]])->dailyAt('04:00');

// Card seat holds that ran out (PLAN §3.1). Cheap when there is nothing to do.
Schedule::command('holds:release')->everyMinute()->withoutOverlapping();

// Private session requests nobody answered in time (prompt 15B). Unpaid
// approved requests lapse through holds:release above.
Schedule::command('private-requests:expire')->everyMinute()->withoutOverlapping();

// Waitlist offers for freed seats, and offers that ran out (milestone 10d).
Schedule::command('waitlist:process')->everyMinute()->withoutOverlapping();

// Bank transfer screenshots 90 days after review; records are kept (PLAN §3.2).
Schedule::command('transfers:prune-proofs')->dailyAt('04:15')->withoutOverlapping();

// Customer emails (milestone 8), each sent once (notification_log). Times
// are Pakistan time.
Schedule::command('notifications:send reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notifications:send thanks')->hourly()->withoutOverlapping();
Schedule::command('notifications:send credits')->dailyAt('10:00')->timezone('Asia/Karachi')->withoutOverlapping();

// Leads due for follow-up, to admins and staff (milestone 10c).
Schedule::command('leads:digest')->dailyAt('09:00')->timezone('Asia/Karachi')->withoutOverlapping();

// Weather alerts (milestone 10f): fetches the forecast at 06:00 and 18:00
// Pakistan time (hourly retries after a failure) and emails level changes.
Schedule::command('weather:check')->hourly()->withoutOverlapping();

// Gift vouchers (milestone 10e): unpaid purchases past their deadline.
Schedule::command('vouchers:release')->hourly()->withoutOverlapping();
// Scheduled vouchers go out at 09:00 Pakistan time on their day.
Schedule::command('vouchers:deliver')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('vouchers:expire')->dailyAt('00:30')->withoutOverlapping();

// Credit packs past their expiry (PLAN §2).
Schedule::command('credits:expire')->dailyAt('00:15')->withoutOverlapping();

// Milestone 12: shared hosting has no Supervisor, so the cron scheduler
// drains the database queue every minute and stops before the next run.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

// Alert admins when jobs wait over 5 minutes or transfers over 12 hours.
Schedule::command('monitor:operations')->everyFiveMinutes()->withoutOverlapping();

// Encrypted backups (database + private uploads) kept 30 days; a health
// report goes to BACKUP_NOTIFICATION_EMAIL every Monday.
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:monitor')->weeklyOn(1, '08:00')->timezone('Asia/Karachi');

// Log pruning: failed jobs after 30 days (file logs rotate daily, LOG_DAILY_DAYS).
// The audit log and notification_log are kept for accounting.
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('04:30');
