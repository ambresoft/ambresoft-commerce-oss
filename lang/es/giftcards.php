<?php

// The admin screens use the English UI fallback for this locale; only the customer-facing emails are translated.
return array_replace(require __DIR__.'/../en/giftcards.php', [
    'mail_subject' => ':sender te ha enviado una tarjeta regalo para :store',
    'mail_title' => 'Una tarjeta regalo para ti, :name',
    'mail_intro' => ':sender te ha enviado una tarjeta regalo para :store.',
    'mail_value' => 'Valor',
    'mail_code' => 'Tu código',
    'mail_how_to' => 'Introduce este código al pagar un pedido en :store. Si la tarjeta no cubre todo el pedido, puedes pagar el resto de otra forma. Lo que quede seguirá en la tarjeta para la próxima vez.',
    'mail_cancelled_subject' => 'Tu tarjeta regalo de :store ha sido cancelada',
    'mail_cancelled_title' => 'Tu tarjeta regalo ha sido cancelada',
    'mail_cancelled_text' => 'La tarjeta regalo :code por valor de :amount de :store ha sido cancelada y ya no se puede usar.',
    'mail_cancelled_balance' => 'Saldo en el momento de la cancelación: :balance.',
    'mail_questions' => 'Si tienes preguntas, ponte en contacto con :store.',
]);
