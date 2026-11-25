<?php

return [
    'container_id' => 'Google Tag Manager container id', 'container_id_hint' => 'To get your container id (GTM-XXXXXXX) you need to log in to Google Tag Manager and create a container for your website. It loads on public storefront pages only: never on account, checkout, payment, order tracking or sign-in pages.',
    'data_layer' => 'Data Layer', 'data_layer_hint' => 'By default, the data layer is called dataLayer. If you want to use a different name for your data layer, you may enter it here. The value should be without spaces.',
    'name_hint' => 'Enter a name for this event that will be sent to the Google Tag Manager account for tracking.',
    'enable_add_to_cart' => 'Enable "Add to cart" event', 'name_add_to_cart' => 'Name for "Add to cart" event',
    'enable_remove_from_cart' => 'Enable "Remove from cart" event', 'name_remove_from_cart' => 'Name for "Remove from cart" event',
    'enable_add_to_wishlist' => 'Enable "Wish list" event', 'name_add_to_wishlist' => 'Name for "Wish list" event',
    'enable_add_to_comparison' => 'Enable "Comparison list" event', 'name_add_to_comparison' => 'Name for "Comparison list" event',
    'enable_call_request' => 'Enable "Call request" event', 'name_call_request' => 'Name for "Call request" event',
    'invalid_container' => 'Enter the container id as shown in Google Tag Manager, for example GTM-ABC1234.',
    'invalid_data_layer' => 'Use letters, digits, _ or $ only, without spaces, not starting with a digit.',
    'invalid_name' => 'Use 1–64 letters, digits or . _ : - characters.',
];
