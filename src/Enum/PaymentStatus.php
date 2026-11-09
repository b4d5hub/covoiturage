<?php

namespace App\Enum;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::Refunded => 'Remboursé',
        };
    }
}
