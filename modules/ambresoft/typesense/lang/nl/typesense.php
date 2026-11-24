<?php

return [
    'typo_tolerance' => 'Tolerantie voor typefouten',
    'typo_tolerance_hint' => 'Hoeveel typefouten een woord mag bevatten en toch productnamen vinden. Codes (SKU, MPN, EAN) moeten altijd exact overeenkomen.',
    'typos_normal' => 'Normaal (tot 2 per woord)',
    'typos_low' => 'Laag (1 per woord)',
    'typos_off' => 'Uit (alleen exacte woorden)',
    'synonyms' => 'Synoniemen',
    'synonyms_hint' => 'Eén groep per regel. "laptop, notebook" maakt de woorden gelijkwaardig; "mobiel => smartphone, telefoon" vindt ook smartphones en telefoons bij zoeken op mobiel. Opgeslagen synoniemen gelden direct.',
    'synonyms_invalid' => 'Schrijf één groep per regel: minstens twee woorden gescheiden door komma\'s, of "woord => andere, woorden". Woorden van maximaal 60 tekens, maximaal 200 regels.',
    'not_configured' => 'Typesense is niet geconfigureerd: stel TYPESENSE_HOST (of TYPESENSE_NODES) en TYPESENSE_API_KEY in de serveromgeving in.',
    'no_index' => 'Typesense is bereikbaar maar heeft nog geen productindex. Bouw de index.',
    'min_query_length' => 'Minimaal aantal tekens',
    'min_query_length_hint' => 'Hoeveel tekens een zoekopdracht nodig heeft voordat resultaten of suggesties verschijnen. Kortere zoekopdrachten tonen een melding. Laat leeg of zet op 0 voor geen minimum.',
];
