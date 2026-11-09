<?php

namespace App\Enum;

enum TripStatus: string
{
    case Published = 'published';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Publié',
            self::Completed => 'Effectué',
            self::Cancelled => 'Annulé',
        };
    }
}
