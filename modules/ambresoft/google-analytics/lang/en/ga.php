<?php

return [
    'measurement_id' => 'Google Analytics measurement ID',
    'measurement_id_hint' => 'Your GA4 measurement ID (G-XXXXXXXXXX), under Admin → Data streams in Google Analytics. It loads on public storefront pages only: never on account, checkout, payment, order tracking or sign-in pages.',
    'track_ecommerce' => 'Track e-commerce transactions',
    'track_ecommerce_hint' => 'Sends add to cart, remove from cart and add to wishlist from the storefront, and each placed order as a purchase.',
    'api_secret' => 'Measurement Protocol API secret',
    'api_secret_hint' => 'Required for purchases, which the server sends so that no script runs on checkout pages. Create it in Google Analytics under Admin → Data streams → your stream → Measurement Protocol API secrets.',
    'universal_retired' => 'Universal Analytics (UA-) IDs stopped collecting data in 2023. Use your GA4 measurement ID (G-…).',
    'invalid_measurement_id' => 'Enter the measurement ID as shown in Google Analytics, for example G-ABC123XYZ9.',
    'invalid_api_secret' => 'Paste the secret value exactly as Google Analytics shows it.',
];
