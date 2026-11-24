<?php

return [
    'default_name' => 'Πληρωμή με κάρτα (Stripe)',
    'default_description' => 'Πληρώστε με ασφάλεια στη σελίδα πληρωμής της Stripe με κάρτα ή άλλη μέθοδο που σας προσφέρει η Stripe.',
    'mode' => 'Λειτουργία',
    'mode_hint' => 'Η δοκιμαστική χρησιμοποιεί δοκιμαστικά κλειδιά Stripe και δεν μετακινεί πραγματικά χρήματα. Επιλέξτε Live μόνο με τα live κλειδιά του λογαριασμού σας. Το κλειδί πρέπει να ταιριάζει με τη λειτουργία.',
    'mode_test' => 'Δοκιμαστική',
    'mode_live' => 'Live',
    'secret_key' => 'Μυστικό κλειδί API',
    'secret_key_hint' => 'Ξεκινά με sk_test_ ή sk_live_ (δουλεύει και περιορισμένο κλειδί rk_). Αποθηκεύεται κρυπτογραφημένο και δεν εμφανίζεται ξανά. Αφήστε κενό για να κρατήσετε το αποθηκευμένο.',
    'webhook_secret' => 'Μυστικό υπογραφής webhook',
    'webhook_secret_hint' => 'Ξεκινά με whsec_. Στο Stripe Dashboard προσθέστε το endpoint https://το-κατάστημά-σας/payments/webhooks/stripe_checkout για τα checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed και checkout.session.expired και επικολλήστε εδώ το μυστικό υπογραφής του. Αποθηκεύεται κρυπτογραφημένο.',
    'secret_key_invalid' => 'Δεν είναι μυστικό κλειδί Stripe (αναμένεται sk_test_… ή sk_live_…).',
    'secret_key_mode' => 'Το κλειδί δεν ταιριάζει με τη λειτουργία (:mode). Δεν επιτρέπεται η ανάμειξη δοκιμαστικών και live κλειδιών.',
    'webhook_secret_invalid' => 'Δεν είναι μυστικό υπογραφής webhook Stripe (αναμένεται whsec_…).',
    'order_description' => 'Παραγγελία :number στο :store',
];
