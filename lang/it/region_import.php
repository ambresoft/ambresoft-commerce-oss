<?php

return [
    ...(require __DIR__.'/category_import.php'),
    'title' => 'Importa regioni',
    'intro' => 'Crea o aggiorna le regioni dei paesi di spedizione da un CSV. Controlla le modifiche prima di salvare.',
    'created' => 'Nuove regioni',
    'run' => 'Importa regioni ora',
    'preview' => 'Anteprima: nessuna regione modificata',
    'failed' => 'Errore: nessuna modifica alle regioni salvata',
    'identifier' => 'Regione / paese e codice',
    'invalid_id' => 'region_id deve identificare una regione esistente.',
    'duplicate' => 'La stessa regione o coppia paese/codice compare più volte nel file.',
    'shipping_required' => 'Scegli un paese ISO valido già abilitato per la spedizione.',
    'country_immutable' => 'Una regione non può essere spostata in un altro paese. Crea una nuova regione.',
    'stale' => 'Il file, le regioni, i paesi di spedizione, le lingue o i permessi sono cambiati. Carica di nuovo per una nuova anteprima.',
    'help' => 'Usa region_id per aggiornare o country_code + code per cercare o creare una regione. I paesi devono essere abilitati per la spedizione. country_code non può essere modificato. Sono ammessi solo nomi in lingue attive; la lingua predefinita è obbligatoria. I nomi omessi restano invariati; un nome vuoto non predefinito rimuove quella traduzione. code/status vuoti mantengono i valori. Le nuove regioni sono disabilitate. Massimo 20 MiB / 10.000 righe. Le anteprime scadono dopo un’ora.',
    'headers' => 'Le intestazioni devono essere nomi di colonna canonici univoci. Le colonne non supportate, di lingue inattive e di sistema vengono rifiutate.',
    'options_invalid' => 'Controlla la dimensione del file e scegli un delimitatore CSV supportato.',
];
