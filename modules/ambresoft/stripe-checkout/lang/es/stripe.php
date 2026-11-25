<?php

return [
    'default_name' => 'Pago con tarjeta (Stripe)',
    'default_description' => 'Paga de forma segura en la página de pago de Stripe con tarjeta u otro método que Stripe te ofrezca.',
    'mode' => 'Modo',
    'mode_hint' => 'Prueba usa claves de prueba de Stripe y no mueve dinero real. Elige Live solo con las claves live de tu cuenta. La clave debe coincidir con el modo.',
    'mode_test' => 'Prueba',
    'mode_live' => 'Live',
    'secret_key' => 'Clave secreta de API',
    'secret_key_hint' => 'Empieza por sk_test_ o sk_live_ (una clave restringida rk_ también sirve). Se guarda cifrada y no se vuelve a mostrar. Déjala vacía para conservar la guardada.',
    'webhook_secret' => 'Secreto de firma del webhook',
    'webhook_secret_hint' => 'Empieza por whsec_. En el Dashboard de Stripe añade el endpoint https://tu-tienda/payments/webhooks/stripe_checkout para checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed y checkout.session.expired, y pega aquí su secreto de firma. Se guarda cifrado.',
    'secret_key_invalid' => 'No es una clave secreta de Stripe (se espera sk_test_… o sk_live_…).',
    'secret_key_mode' => 'La clave no coincide con el modo seleccionado (:mode). No se pueden mezclar claves de prueba y live.',
    'webhook_secret_invalid' => 'No es un secreto de firma de webhook de Stripe (se espera whsec_…).',
    'order_description' => 'Pedido :number en :store',
];
