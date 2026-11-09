<?php

namespace App\Enum;

enum TripStatus: string
{
    case Published = 'published';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
