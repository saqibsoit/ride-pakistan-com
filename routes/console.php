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
