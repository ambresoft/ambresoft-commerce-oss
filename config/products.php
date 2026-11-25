<?php

return [
    // Price input precision is the primary currency's configured decimals (Settings → Currencies).
    'dimension_unit' => 'cm',
    'private_files' => [
        'max_kilobytes' => 10240,
        // Images cover attachments such as size charts; contents are checked by signature, never trusted by extension.
        'extensions' => ['pdf', 'zip', 'txt', 'jpg', 'jpeg', 'png', 'webp'],
        'mime_types' => ['pdf' => ['application/pdf'], 'zip' => ['application/zip', 'application/x-zip-compressed'], 'txt' => ['text/plain'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']],
    ],
];
