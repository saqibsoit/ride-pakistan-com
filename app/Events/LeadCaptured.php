<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Lead;
use App\Support\Tracking\VisitorContext;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead arrived from a site form. `$visitor` is set only when the
 * visitor had granted tracking consent (for Meta CAPI).
 */
final class LeadCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?VisitorContext $visitor = null,
    ) {}
}
