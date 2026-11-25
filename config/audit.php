<?php

return [
    'actor_email' => (bool) env('AUDIT_ACTOR_EMAIL', true),
    'ip_address' => (bool) env('AUDIT_IP_ADDRESS', true),
    'user_agent' => (bool) env('AUDIT_USER_AGENT', false),
    // Disabled by default. No scheduled deletion; explicitly run cms:audit-prune.
    'retention_days' => env('AUDIT_RETENTION_DAYS'),
];
