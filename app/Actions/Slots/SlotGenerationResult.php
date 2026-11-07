<?php

declare(strict_types=1);

namespace App\Actions\Slots;

/** What one GenerateSlots run changed. */
final class SlotGenerationResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $deleted = 0;

    /** Slots left alone because they are in use, closed or cancelled. */
    public int $kept = 0;

    public function changed(): bool
    {
        return $this->created + $this->updated + $this->deleted > 0;
    }

    public function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d removed, %d kept as they are',
            $this->created,
            $this->updated,
            $this->deleted,
            $this->kept,
        );
    }
}
