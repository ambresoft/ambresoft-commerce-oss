<?php

return [
    'measurement_id' => 'Αριθμός λογαριασμού Google Analytics',
    'measurement_id_hint' => 'Το GA4 measurement ID (G-XXXXXXXXXX), στο Google Analytics → Διαχείριση → Ροές δεδομένων. Φορτώνεται μόνο στις δημόσιες σελίδες του καταστήματος: ποτέ σε σελίδες λογαριασμού, ολοκλήρωσης αγοράς, πληρωμής, παρακολούθησης παραγγελίας ή σύνδεσης.',
    'track_ecommerce' => 'Παρακολούθηση των συναλλαγών ηλεκτρονικού εμπορίου',
    'track_ecommerce_hint' => 'Στέλνει προσθήκη και αφαίρεση από το καλάθι και προσθήκη στη λίστα επιθυμιών από το κατάστημα, και κάθε παραγγελία ως αγορά.',
    'api_secret' => 'Measurement Protocol API secret',
    'api_secret_hint' => 'Απαιτείται για τις αγορές, που τις στέλνει ο server ώστε να μην εκτελείται script στις σελίδες ολοκλήρωσης αγοράς. Δημιουργήστε το στο Google Analytics → Διαχείριση → Ροές δεδομένων → η ροή σας → Measurement Protocol API secrets.',
    'universal_retired' => 'Τα IDs του Universal Analytics (UA-) σταμάτησαν να συλλέγουν δεδομένα το 2023. Χρησιμοποιήστε το GA4 measurement ID (G-…).',
    'invalid_measurement_id' => 'Πληκτρολογήστε το measurement ID όπως εμφανίζεται στο Google Analytics, π.χ. G-ABC123XYZ9.',
    'invalid_api_secret' => 'Επικολλήστε το secret ακριβώς όπως εμφανίζεται στο Google Analytics.',
];
