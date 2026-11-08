<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\HandleStripeEvent;
use App\Support\Stripe\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Throwable;
use UnexpectedValueException;

/**
 * POST /webhooks/stripe (PLAN §3.1). CSRF-exempt, so the Stripe signature
 * is the only authentication: anything unsigned or badly signed is a 400.
 * Fulfilment happens in HandleStripeEvent (FulfilOrder), never here.
 */
final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $stripe, HandleStripeEvent $handler): JsonResponse
    {
        $signature = (string) $request->header('Stripe-Signature', '');

        if ($signature === '') {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        try {
            $event = $stripe->constructEvent($request->getContent(), $signature);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            Log::warning('Stripe webhook with an invalid signature or payload', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if (! in_array($event->type, HandleStripeEvent::HANDLED, true)) {
            return response()->json(['received' => true, 'ignored' => $event->type]);
        }

        try {
            $processed = $handler->handle($event);
        } catch (Throwable $e) {
            Log::error('Stripe webhook '.$event->id.' failed; Stripe will retry', ['type' => $event->type, 'error' => $e->getMessage()]);
            report($e);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
