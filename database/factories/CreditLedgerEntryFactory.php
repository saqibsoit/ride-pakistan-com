<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditReason;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLedgerEntry>
 */
class CreditLedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_pack_id' => CreditPack::factory(),
            'booking_id' => null,
            'delta' => 8,
            'reason' => CreditReason::Granted,
            'note' => null,
            'actor_id' => null,
        ];
    }
}
