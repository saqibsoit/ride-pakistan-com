<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\ExportAccountData;
use App\Actions\Account\RequestAccountDeletion;
use App\Enums\SettingKey;
use App\Exceptions\AccountActionBlocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /account/privacy: download my data, sign out everywhere, delete my account. */
final class PrivacyController extends Controller
{
    public function show(Request $request, RequestAccountDeletion $deletion, Settings $settings): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.privacy', [
            'blockers' => $deletion->blockers($user),
            'unusedCredits' => $deletion->unusedCredits($user),
            'graceDays' => $settings->int(SettingKey::DeletionGraceDays) ?? 30,
            'retentionYears' => $settings->int(SettingKey::OrderRetentionYears) ?? 6,
            'canDelete' => $user->can('deleteAccount', $user),
        ]);
    }

    public function export(Request $request, ExportAccountData $export): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('exportData', $user);

        $json = json_encode($export->handle($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            'ride-pakistan-my-data-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function destroy(DeleteAccountRequest $request, RequestAccountDeletion $deletion): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('deleteAccount', $user);

        try {
            $deletion->handle($user, $request->boolean('forfeit_credits'));
        } catch (AccountActionBlocked $e) {
            return redirect()->route('account.privacy')->withErrors(['delete' => $e->reasons]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'account-deleted');
    }
}
