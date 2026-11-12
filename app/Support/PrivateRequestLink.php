<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PrivateSessionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The signed "Follow your request" link in the private request emails
 * (guest checkout, decided 2026-11-12): status and withdraw without
 * signing in, for that one request. It works while the request is open
 * and until DAYS_AFTER days after it closed; paying an approved request
 * uses its order's manage link (ManageLink).
 */
final class PrivateRequestLink
{
    public const DAYS_AFTER = 7;

    public static function for(PrivateSessionRequest $request): string
    {
        return URL::signedRoute('requests.open', ['reference' => $request->reference, 'v' => $request->manage_link_version]);
    }

    public static function isOpen(PrivateSessionRequest $request, ?CarbonImmutable $now = null): bool
    {
        if ($request->status->isOpen() || $request->closed_at === null) {
            return true;
        }

        return ($now ?? CarbonImmutable::now())->lessThan($request->closed_at->addDays(self::DAYS_AFTER));
    }
}
