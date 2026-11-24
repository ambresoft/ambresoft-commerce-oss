<?php

// The admin screens use the English UI fallback for this locale; only the customer-facing emails are translated.
return array_replace(require __DIR__.'/../en/giftcards.php', [
    'mail_subject' => ':sender ti ha inviato una carta regalo per :store',
    'mail_title' => 'Una carta regalo per te, :name',
    'mail_intro' => ':sender ti ha inviato una carta regalo per :store.',
    'mail_value' => 'Valore',
    'mail_code' => 'Il tuo codice',
    'mail_how_to' => 'Inserisci questo codice quando paghi un ordine su :store. Se la carta non copre l’intero ordine, puoi pagare il resto in un altro modo. Ciò che resta rimane sulla carta per la prossima volta.',
    'mail_cancelled_subject' => 'La tua carta regalo :store è stata annullata',
    'mail_cancelled_title' => 'La tua carta regalo è stata annullata',
    'mail_cancelled_text' => 'La carta regalo :code del valore di :amount di :store è stata annullata e non può più essere utilizzata.',
    'mail_cancelled_balance' => 'Saldo al momento dell’annullamento: :balance.',
    'mail_questions' => 'Per domande, contatta :store.',
]);
