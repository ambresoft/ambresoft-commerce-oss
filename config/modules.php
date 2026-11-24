<?php

return [
    // First-party add-ons shipped inside the CMS source ({vendor}/{name}); discovered without any upload.
    'bundled_path' => base_path('modules'),
    // Add-ons installed from uploaded ZIP archives. Keep it outside the public web root and persistent across deployments.
    'addons_path' => env('ADDONS_PATH', storage_path('addons')),
    // Uploading add-on code from the administration. Disable on installations where code arrives only through deployment.
    'uploads' => (bool) env('ADDON_UPLOADS', true),
    'upload_max_kb' => (int) env('ADDON_UPLOAD_MAX_KB', 20480),
];
