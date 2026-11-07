<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nightly safety net; content changes also regenerate it straight away.
Schedule::command('sitemap:generate')->dailyAt('03:15')->withoutOverlapping();

// Keep the slot horizon filled (booking.slot_horizon_weeks). Idempotent.
Schedule::command('slots:generate')->dailyAt('02:30')->withoutOverlapping();
