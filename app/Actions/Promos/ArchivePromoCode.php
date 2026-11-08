<?php

declare(strict_types=1);

namespace App\Actions\Promos;

use App\Models\PromoCode;
use App\Models\User;

/**
 * Removes a promo code from use (milestone 10e). A code that was never
 * used is deleted; a used one is archived (inactive, hidden by default,
 * kept with its redemptions for reports). Archived codes can be restored
 * as inactive.
 */
final class ArchivePromoCode
{
    /** @return bool true when deleted, false when archived */
    public function handle(PromoCode $promo, User $admin): bool
    {
        if (! $promo->redemptions()->exists()) {
            $code = $promo->code;
            $promo->products()->detach();
            $promo->delete();

            activity('promos')->causedBy($admin)->event('deleted')->withProperties(['code' => $code])->log('Promo code deleted: '.$code);

            return true;
        }

        $promo->is_active = false;
        $promo->archived_at = now()->toImmutable();
        $promo->save();

        activity('promos')->performedOn($promo)->causedBy($admin)->event('archived')->log('Promo code archived: '.$promo->code);

        return false;
    }

    public function restore(PromoCode $promo, User $admin): PromoCode
    {
        $promo->archived_at = null;
        $promo->is_active = false;
        $promo->save();

        activity('promos')->performedOn($promo)->causedBy($admin)->event('restored')->log('Promo code restored (inactive): '.$promo->code);

        return $promo;
    }
}
