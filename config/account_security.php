<?php

return [
    // A local GeoLite2-City/GeoIP2-City database; no IPs are sent to a third party.
    'geoip_database' => env('SECURITY_GEOIP_DATABASE', storage_path('app/private/GeoLite2-City.mmdb')),
    'failure_threshold' => 5,
    'failure_window_minutes' => 15,
    // Failed admin sign-ins of one staff account within the window that notify account-security staff.
    'admin_alert_threshold' => 3,
];
