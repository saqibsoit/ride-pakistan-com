<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Component styleguide: local development only.
if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
