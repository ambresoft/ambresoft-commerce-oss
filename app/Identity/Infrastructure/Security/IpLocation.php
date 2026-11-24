<?php

namespace App\Identity\Infrastructure\Security;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;

class IpLocation
{
    /** @return array{country: ?string, city: ?string, timezone: ?string} */
    public function resolve(?string $ip): array
    {
        $unknown = ['country' => null, 'city' => null, 'timezone' => null];
        $path = (string) config('account_security.geoip_database');
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || ! is_readable($path)) {
            return $unknown;
        }
        try {
            $reader = new Reader($path);
            try {
                $city = $reader->city($ip);

                return ['country' => $city->country->isoCode, 'city' => $city->city->name === null ? null : mb_substr($city->city->name, 0, 150), 'timezone' => $city->location->timeZone];
            } finally {
                $reader->close();
            }
        } catch (AddressNotFoundException|InvalidDatabaseException|\InvalidArgumentException|\RuntimeException $exception) {
            // Geolocation must not prevent signing in; the IP remains available for investigation.
            return $unknown;
        }
    }
}
