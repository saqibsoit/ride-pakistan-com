<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the full application. Unit tests that need the
| container (config, actions) opt in with TestCase as well.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');
