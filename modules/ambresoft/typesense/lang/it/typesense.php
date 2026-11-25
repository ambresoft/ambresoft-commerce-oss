<?php

return [
    'typo_tolerance' => 'Tolleranza agli errori',
    'typo_tolerance_hint' => 'Quanti errori di battitura può avere una parola continuando a corrispondere ai nomi dei prodotti. I codici (SKU, MPN, EAN) corrispondono sempre esattamente.',
    'typos_normal' => 'Normale (fino a 2 per parola)',
    'typos_low' => 'Bassa (1 per parola)',
    'typos_off' => 'Disattivata (solo parole esatte)',
    'synonyms' => 'Sinonimi',
    'synonyms_hint' => 'Un gruppo per riga. «portatile, notebook» rende equivalenti le parole; «cellulare => smartphone, telefono» trova anche smartphone e telefoni quando si cerca cellulare. I sinonimi salvati valgono subito.',
    'synonyms_invalid' => 'Scrivi un gruppo per riga: almeno due parole separate da virgole, oppure «parola => altre, parole». Parole fino a 60 caratteri, fino a 200 righe.',
    'not_configured' => 'Typesense non è configurato: imposta TYPESENSE_HOST (o TYPESENSE_NODES) e TYPESENSE_API_KEY nell’ambiente del server.',
    'no_index' => 'Typesense risponde ma non ha ancora un indice dei prodotti. Crea l’indice.',
    'min_query_length' => 'Caratteri minimi',
    'min_query_length_hint' => 'Quanti caratteri servono a una ricerca prima che compaiano risultati o suggerimenti. Le ricerche più brevi mostrano un messaggio. Lascia vuoto o 0 per nessun minimo.',
];
