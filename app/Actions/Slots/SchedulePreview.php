<?php

declare(strict_types=1);

namespace App\Actions\Slots;

/** Result of PreviewScheduleRule: what saving the rule would do. */
final class SchedulePreview
{
    /** @var list<SchedulePreviewItem> */
    public array $new = [];

    /** @var list<SchedulePreviewItem> */
    public array $changed = [];

    /** @var list<SchedulePreviewItem> */
    public array $removed = [];

    /** @var list<SchedulePreviewItem> */
    public array $kept = [];

    public function __construct(public readonly int $weeks) {}

    public function isEmpty(): bool
    {
        return $this->new === [] && $this->changed === [] && $this->removed === [] && $this->kept === [];
    }
}
