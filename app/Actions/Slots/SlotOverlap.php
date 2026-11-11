<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Do these times clash with another session (prompt 15)? A slot clashes
 * when it overlaps a slot that isn't cancelled, after widening it by the
 * buffer (`booking.slot_buffer_minutes` [30]) on both sides: with a
 * 30-minute buffer, 10:00–12:00 clashes with an 08:00–09:45 session but
 * not with 08:00–09:30. Closed slots count, because they can be reopened.
 *
 * Used by the bulk schedule editor and by private session request
 * approvals. Read-only; callers check again under their locks.
 */
final class SlotOverlap
{
    public function __construct(private readonly Settings $settings) {}

    public function bufferMinutes(): int
    {
        return max(0, $this->settings->int(SettingKey::SlotBufferMinutes) ?? 30);
    }

    /**
     * The first slot these times clash with, or null.
     *
     * @param  list<int>  $ignoreSlotIds  the slot being moved, or slots being created in the same run
     */
    public function firstClash(CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $ignoreSlotIds = [], bool $lock = false): ?Slot
    {
        $buffer = $this->bufferMinutes();

        return Slot::query()
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '<', $endsAt->utc()->addMinutes($buffer))
            ->where('ends_at', '>', $startsAt->utc()->subMinutes($buffer))
            ->when($ignoreSlotIds !== [], fn ($q) => $q->whereKeyNot($ignoreSlotIds))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Two time ranges clash given the buffer (for slots not saved yet).
     */
    public function clashes(CarbonImmutable $aStart, CarbonImmutable $aEnd, CarbonImmutable $bStart, CarbonImmutable $bEnd): bool
    {
        $buffer = $this->bufferMinutes();

        return $aStart->lessThan($bEnd->addMinutes($buffer)) && $aEnd->greaterThan($bStart->subMinutes($buffer));
    }
}
