<?php

declare(strict_types=1);

namespace App\Enums;

enum BankTransferStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
