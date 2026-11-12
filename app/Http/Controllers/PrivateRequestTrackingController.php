<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PrivateRequests\WithdrawPrivateRequest;
use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use App\Support\GuestAccess;
use App\Support\ManageLink;
use App\Support\PrivateRequestLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /requests/{reference} (guest checkout, decided 2026-11-12): one private
 * session request without signing in. The signed link from the request
 * emails grants this browser that one request and redirects to the plain
 * page; it shows the status, the way to pay once approved (the order's
 * manage link) and Withdraw while it waits. Anything else shows the same
 * "ask for a new link" page as the manage links.
 */
final class PrivateRequestTrackingController extends Controller
{
    public function open(Request $request, string $reference, GuestAccess $guests): RedirectResponse|Response
    {
        $privateRequest = PrivateSessionRequest::query()->where('reference', $reference)->first();

        if (! $request->hasValidSignature()
            || $privateRequest === null
            || $request->integer('v') !== $privateRequest->manage_link_version
            || ! PrivateRequestLink::isOpen($privateRequest)) {
            return $this->closed();
        }

        $guests->grantRequest($privateRequest);

        return redirect()->route('requests.show', $privateRequest->reference);
    }

    public function show(Request $request, string $reference, GuestAccess $guests): View|Response
    {
        $privateRequest = $this->find($request, $reference, $guests);

        if ($privateRequest === null) {
            return $this->closed();
        }

        $order = $privateRequest->order;

        return view('requests.show', [
            'item' => $privateRequest,
            'payUrl' => $privateRequest->status === PrivateRequestStatus::Approved && $order !== null && in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification], true)
                ? ManageLink::forOrder($order)
                : null,
            'bookingUrl' => $privateRequest->status === PrivateRequestStatus::Paid && $order !== null ? ManageLink::forOrder($order) : null,
        ]);
    }

    public function withdraw(Request $request, string $reference, GuestAccess $guests, WithdrawPrivateRequest $withdraw): RedirectResponse|Response
    {
        $privateRequest = $this->find($request, $reference, $guests);

        if ($privateRequest === null) {
            return $this->closed();
        }

        try {
            $withdraw->handle($privateRequest, $privateRequest->user);
        } catch (PrivateRequestRejected $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return redirect()->route('requests.show', $privateRequest->reference)->with('status', 'Request withdrawn.');
    }

    private function find(Request $request, string $reference, GuestAccess $guests): ?PrivateSessionRequest
    {
        $user = $request->user();

        return $guests->findRequest($user instanceof User ? $user : null, $reference);
    }

    private function closed(): Response
    {
        return response()->view('manage.link', ['closed' => true], 403);
    }
}
