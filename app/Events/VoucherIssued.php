<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Voucher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gift voucher was issued (paid purchase or admin). Dispatched after
 * commit, so listeners only see vouchers that really exist.
 */
final class VoucherIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Voucher $voucher) {}
}
