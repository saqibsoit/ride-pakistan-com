<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VoucherOrder;
use App\Support\TransferProofs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a gift voucher transfer proof to an admin (milestone 10e; same
 * rules as TransferProofController, PLAN §7): short-lived signed URL,
 * signed-in admin (VoucherOrderPolicy::viewProof), sandboxed, never cached,
 * PDFs download. Each view is logged.
 */
final class VoucherTransferProofController extends Controller
{
    public function __invoke(Request $request, VoucherOrder $voucherOrder, TransferProofs $proofs): StreamedResponse
    {
        Gate::authorize('viewProof', $voucherOrder);

        $path = $voucherOrder->screenshot_path;
        $disk = $proofs->disk();

        abort_if($path === null || ! $disk->exists($path), 404);

        $pdf = TransferProofs::isPdf($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        activity('vouchers')
            ->performedOn($voucherOrder)
            ->causedBy($request->user())
            ->event('transfer_proof_viewed')
            ->log('Voucher transfer proof viewed');

        return $disk->response($path, 'transfer-'.$voucherOrder->reference.'.'.$extension, [
            'Content-Type' => match ($extension) {
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                default => 'image/webp',
            },
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $pdf ? 'attachment' : 'inline');
    }

    public static function signedUrl(VoucherOrder $order): string
    {
        return URL::temporarySignedRoute('admin.voucher-transfer-proof', now()->addMinutes(TransferProofController::URL_MINUTES), ['voucherOrder' => $order->reference]);
    }
}
