<?php

declare(strict_types=1);

namespace App\Support\Tracking;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta Conversions API client (server-side Lead and Purchase). Off unless
 * META_PIXEL_ID and META_CAPI_TOKEN are set. Failures are logged and
 * reported as `false`; they never break the flow that raised the event.
 */
final class MetaConversions
{
    public function __construct(private readonly TrackingConfig $config) {}

    public function enabled(): bool
    {
        return $this->config->capiEnabled();
    }

    public function send(MetaEvent $event): bool
    {
        $pixel = $this->config->metaPixelId();
        $token = $this->config->metaCapiToken();

        if ($pixel === null || $token === null) {
            return false;
        }

        $body = array_filter([
            'data' => [$event->toPayload()],
            'access_token' => $token,
            'test_event_code' => $this->config->metaCapiTestCode(),
        ], fn (mixed $value): bool => $value !== null);

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->post(sprintf('https://graph.facebook.com/%s/%s/events', $this->config->metaGraphVersion(), $pixel), $body);
        } catch (Throwable $e) {
            Log::warning('Meta CAPI request failed.', ['event' => $event->name, 'event_id' => $event->eventId, 'exception' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Meta CAPI rejected an event.', ['event' => $event->name, 'event_id' => $event->eventId, 'status' => $response->status(), 'error' => $response->json('error.message')]);

            return false;
        }

        return true;
    }
}
