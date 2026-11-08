<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/** /account/riders: hosts the Livewire riders manager. */
final class RiderController extends Controller
{
    public function __invoke(): View
    {
        return view('account.riders');
    }
}
