<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Livewire (overrides; everything else uses the package defaults)
|--------------------------------------------------------------------------
|
| Livewire and its bundled Alpine are loaded by resources/js/livewire.js,
| only on pages that render Livewire components (<x-layouts.app livewire>).
| Automatic injection would add a second Alpine next to app.js.
|
*/

return [

    'inject_assets' => false,

];
