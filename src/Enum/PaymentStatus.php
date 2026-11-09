<?php

namespace App\Enum;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
}
