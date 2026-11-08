<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** /account/bookings: upcoming and past sessions for the signed-in customer. */
final class BookingController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.bookings', [
            'upcoming' => $user->bookings()->upcoming()->with(['slot', 'rider'])->get(),
            'past' => $user->bookings()->past()->with(['slot', 'rider'])->limit(50)->get(),
        ]);
    }
}
