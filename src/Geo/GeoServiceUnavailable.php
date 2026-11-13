<?php

namespace App\Geo;

/**
 * Service cartographique injoignable ou en erreur : la saisie manuelle reste possible.
 */
final class GeoServiceUnavailable extends \RuntimeException
{
}
