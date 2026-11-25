<?php

// The admin screens use the English UI fallback for this locale; only the customer-facing emails are translated.
return array_replace(require __DIR__.'/../en/giftcards.php', [
    'mail_subject' => ':sender vous a envoyé une carte cadeau pour :store',
    'mail_title' => 'Une carte cadeau pour vous, :name',
    'mail_intro' => ':sender vous a envoyé une carte cadeau pour :store.',
    'mail_value' => 'Valeur',
    'mail_code' => 'Votre code',
    'mail_how_to' => 'Saisissez ce code lors du paiement d’une commande sur :store. Si la carte ne couvre pas toute la commande, vous pouvez régler le reste autrement. Ce qui reste demeure sur la carte pour la prochaine fois.',
    'mail_cancelled_subject' => 'Votre carte cadeau :store a été annulée',
    'mail_cancelled_title' => 'Votre carte cadeau a été annulée',
    'mail_cancelled_text' => 'La carte cadeau :code d’une valeur de :amount de :store a été annulée et ne peut plus être utilisée.',
    'mail_cancelled_balance' => 'Solde au moment de l’annulation : :balance.',
    'mail_questions' => 'Pour toute question, veuillez contacter :store.',
]);
