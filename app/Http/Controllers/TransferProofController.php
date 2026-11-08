<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BankTransfer;
use App\Support\TransferProofs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a bank transfer proof to an admin (PLAN §7: never public). Needs
 * a signed URL that expires after a few minutes, a signed-in session and
 * the admin-only BankTransferPolicy::viewProof. The file is sandboxed:
 * no sniffing, no caching, no scripts; PDFs download instead of opening.
 */
final class TransferProofController extends Controller
{
    public const URL_MINUTES = 5;

    public function __invoke(Request $request, BankTransfer $transfer, TransferProofs $proofs): StreamedResponse
    {
        Gate::authorize('viewProof', $transfer);

        $path = $transfer->screenshot_path;
        $disk = $proofs->disk();

        // Deleted after the retention period (the record itself is kept).
        abort_if($path === null || ! $disk->exists($path), 404);

        $pdf = TransferProofs::isPdf($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'transfer-'.$transfer->order()->value('reference').'.'.$extension;

        activity('payments')
            ->performedOn($transfer)
            ->causedBy($request->user())
            ->event('transfer_proof_viewed')
            ->log('Transfer proof viewed');

        return $disk->response($path, $name, [
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

    public static function signedUrl(BankTransfer $transfer): string
    {
        return URL::temporarySignedRoute('admin.transfer-proof', now()->addMinutes(self::URL_MINUTES), ['transfer' => $transfer->id]);
    }
}
