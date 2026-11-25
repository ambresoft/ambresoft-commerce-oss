<?php

return [
    'default_name' => 'Kaartbetaling (Stripe)',
    'default_description' => 'Betaal veilig op de betaalpagina van Stripe met een kaart of een andere methode die Stripe aanbiedt.',
    'mode' => 'Modus',
    'mode_hint' => 'Test gebruikt Stripe-testsleutels en verplaatst geen echt geld. Kies Live alleen met de live-sleutels van je account. De sleutel moet bij de modus passen.',
    'mode_test' => 'Test',
    'mode_live' => 'Live',
    'secret_key' => 'Geheime API-sleutel',
    'secret_key_hint' => 'Begint met sk_test_ of sk_live_ (een beperkte rk_-sleutel werkt ook). Versleuteld opgeslagen en nooit meer getoond. Laat leeg om de opgeslagen sleutel te behouden.',
    'webhook_secret' => 'Ondertekeningsgeheim van de webhook',
    'webhook_secret_hint' => 'Begint met whsec_. Voeg in het Stripe Dashboard het endpoint https://jouw-winkel/payments/webhooks/stripe_checkout toe voor checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed en checkout.session.expired en plak hier het ondertekeningsgeheim. Versleuteld opgeslagen.',
    'secret_key_invalid' => 'Dit is geen geheime Stripe-sleutel (verwacht sk_test_… of sk_live_…).',
    'secret_key_mode' => 'Deze sleutel past niet bij de gekozen modus (:mode). Test- en live-sleutels mogen niet worden gemengd.',
    'webhook_secret_invalid' => 'Dit is geen ondertekeningsgeheim van een Stripe-webhook (verwacht whsec_…).',
    'order_description' => 'Bestelling :number bij :store',
];
