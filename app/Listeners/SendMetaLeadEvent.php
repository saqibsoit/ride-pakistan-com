<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\LeadCaptured;
use App\Support\Tracking\BrowserEvents;
use App\Support\Tracking\MetaConversions;
use App\Support\Tracking\MetaEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Server-side Meta "Lead" for a site enquiry, only when the visitor had
 * granted tracking consent (the event then carries their context) and
 * CAPI is configured. Same event id as the browser Pixel's Lead.
 */
final class SendMetaLeadEvent implements ShouldQueue
{
    public function __construct(private readonly MetaConversions $meta) {}

    public function shouldQueue(LeadCaptured $event): bool
    {
        return $event->visitor !== null && $event->lead->consent_tracking && $this->meta->enabled();
    }

    public function handle(LeadCaptured $event): void
    {
        $lead = $event->lead;

        if ($event->visitor === null || $lead->tracking_event_id === null) {
            return;
        }

        $this->meta->send(new MetaEvent(
            name: BrowserEvents::LEAD,
            eventId: $lead->tracking_event_id,
            time: $lead->created_at,
            visitor: $event->visitor,
            email: $lead->email,
            phone: $lead->phone,
        ));
    }
}
