<?php

// The admin screens use the English UI fallback for this locale; only the customer-facing emails are translated.
return array_replace(require __DIR__.'/../en/giftcards.php', [
    'mail_subject' => ':sender heeft je een cadeaubon gestuurd voor :store',
    'mail_title' => 'Een cadeaubon voor jou, :name',
    'mail_intro' => ':sender heeft je een cadeaubon gestuurd voor :store.',
    'mail_value' => 'Waarde',
    'mail_code' => 'Jouw code',
    'mail_how_to' => 'Voer deze code in wanneer je een bestelling betaalt bij :store. Dekt de bon niet de hele bestelling, dan kun je de rest op een andere manier betalen. Wat overblijft, blijft op de bon staan voor de volgende keer.',
    'mail_cancelled_subject' => 'Je :store-cadeaubon is geannuleerd',
    'mail_cancelled_title' => 'Je cadeaubon is geannuleerd',
    'mail_cancelled_text' => 'De cadeaubon :code ter waarde van :amount van :store is geannuleerd en kan niet meer worden gebruikt.',
    'mail_cancelled_balance' => 'Saldo op het moment van annuleren: :balance.',
    'mail_questions' => 'Heb je vragen? Neem dan contact op met :store.',
]);
