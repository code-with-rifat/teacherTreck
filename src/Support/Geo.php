<?php

declare(strict_types=1);

namespace Medico\Support;

final class Geo
{
    /**
     * Haversine distance in meters between two WGS84 points.
     */
    public static function distanceMeters(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earth = 6371000.0;
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);

        $a = sin($Δφ / 2) ** 2
            + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earth * $c;
    }

    public static function withinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        float $radiusM
    ): bool {
        return self::distanceMeters($lat1, $lng1, $lat2, $lng2) <= $radiusM;
    }
}
