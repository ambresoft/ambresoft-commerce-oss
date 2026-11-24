<?php

return [
    'default_name' => 'Card payment (Stripe)',
    'default_description' => 'Pay securely on Stripe’s payment page with a card or another method Stripe offers you.',
    'mode' => 'Mode',
    'mode_hint' => 'Test uses Stripe test keys and moves no real money. Choose Live only with the live keys of your Stripe account. A key must match the mode.',
    'mode_test' => 'Test',
    'mode_live' => 'Live',
    'secret_key' => 'Secret API key',
    'secret_key_hint' => 'Starts with sk_test_ or sk_live_ (a restricted rk_ key also works). Stored encrypted and never shown again. Leave empty to keep the saved key.',
    'webhook_secret' => 'Webhook signing secret',
    'webhook_secret_hint' => 'Starts with whsec_. In the Stripe Dashboard add the endpoint https://your-store/payments/webhooks/stripe_checkout for checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired, then paste its signing secret here. Stored encrypted.',
    'secret_key_invalid' => 'This is not a Stripe secret key (expected sk_test_… or sk_live_…).',
    'secret_key_mode' => 'This key does not match the selected mode (:mode). Test and live keys cannot be mixed.',
    'webhook_secret_invalid' => 'This is not a Stripe webhook signing secret (expected whsec_…).',
    'order_description' => 'Order :number at :store',
];
