<?php

return [
    'default_name' => 'Paiement par carte (Stripe)',
    'default_description' => 'Payez en toute sécurité sur la page de paiement de Stripe par carte ou un autre moyen proposé par Stripe.',
    'mode' => 'Mode',
    'mode_hint' => 'Le mode test utilise des clés de test Stripe et ne déplace aucun argent réel. Choisissez Live uniquement avec les clés live de votre compte. La clé doit correspondre au mode.',
    'mode_test' => 'Test',
    'mode_live' => 'Live',
    'secret_key' => 'Clé secrète API',
    'secret_key_hint' => 'Commence par sk_test_ ou sk_live_ (une clé restreinte rk_ convient aussi). Enregistrée chiffrée et jamais réaffichée. Laissez vide pour conserver la clé enregistrée.',
    'webhook_secret' => 'Secret de signature du webhook',
    'webhook_secret_hint' => 'Commence par whsec_. Dans le Dashboard Stripe, ajoutez le point de terminaison https://votre-boutique/payments/webhooks/stripe_checkout pour checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed et checkout.session.expired, puis collez ici son secret de signature. Enregistré chiffré.',
    'secret_key_invalid' => 'Ce n’est pas une clé secrète Stripe (sk_test_… ou sk_live_… attendu).',
    'secret_key_mode' => 'Cette clé ne correspond pas au mode choisi (:mode). Les clés de test et live ne peuvent pas être mélangées.',
    'webhook_secret_invalid' => 'Ce n’est pas un secret de signature de webhook Stripe (whsec_… attendu).',
    'order_description' => 'Commande :number chez :store',
];
