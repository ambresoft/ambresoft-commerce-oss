<?php

return [
    'default_name' => 'Pagamento con carta (Stripe)',
    'default_description' => 'Paga in sicurezza sulla pagina di pagamento di Stripe con carta o un altro metodo offerto da Stripe.',
    'mode' => 'Modalità',
    'mode_hint' => 'La modalità test usa chiavi di test Stripe e non sposta denaro reale. Scegli Live solo con le chiavi live del tuo account. La chiave deve corrispondere alla modalità.',
    'mode_test' => 'Test',
    'mode_live' => 'Live',
    'secret_key' => 'Chiave segreta API',
    'secret_key_hint' => 'Inizia con sk_test_ o sk_live_ (va bene anche una chiave limitata rk_). Salvata cifrata e non più mostrata. Lascia vuoto per mantenere quella salvata.',
    'webhook_secret' => 'Segreto di firma del webhook',
    'webhook_secret_hint' => 'Inizia con whsec_. Nella Dashboard Stripe aggiungi l’endpoint https://tuo-negozio/payments/webhooks/stripe_checkout per checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed e checkout.session.expired, quindi incolla qui il suo segreto di firma. Salvato cifrato.',
    'secret_key_invalid' => 'Non è una chiave segreta Stripe (previsto sk_test_… o sk_live_…).',
    'secret_key_mode' => 'La chiave non corrisponde alla modalità selezionata (:mode). Le chiavi di test e live non possono essere mescolate.',
    'webhook_secret_invalid' => 'Non è un segreto di firma webhook Stripe (previsto whsec_…).',
    'order_description' => 'Ordine :number presso :store',
];
