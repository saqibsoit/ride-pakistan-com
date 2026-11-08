<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $update->handle($user, $request->profile());

        return redirect()->route('account.profile')->with('status', 'profile-saved');
    }
}
