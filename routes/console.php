<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nightly safety net; content changes also regenerate it straight away.
Schedule::command('sitemap:generate')->dailyAt('03:15')->withoutOverlapping();
