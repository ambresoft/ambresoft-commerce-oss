<?php

return [
    'typo_tolerance' => 'Tolérance aux fautes',
    'typo_tolerance_hint' => 'Nombre de fautes de frappe qu’un mot peut contenir tout en correspondant aux noms de produits. Les codes (SKU, MPN, EAN) correspondent toujours exactement.',
    'typos_normal' => 'Normale (jusqu’à 2 par mot)',
    'typos_low' => 'Faible (1 par mot)',
    'typos_off' => 'Désactivée (mots exacts uniquement)',
    'synonyms' => 'Synonymes',
    'synonyms_hint' => 'Un groupe par ligne. « portable, notebook » rend les mots équivalents ; « mobile => smartphone, téléphone » trouve aussi les smartphones et téléphones quand on cherche mobile. Les synonymes enregistrés s’appliquent immédiatement.',
    'synonyms_invalid' => 'Écrivez un groupe par ligne : au moins deux mots séparés par des virgules, ou « mot => autres, mots ». Mots de 60 caractères au plus, 200 lignes au plus.',
    'not_configured' => 'Typesense n’est pas configuré : définissez TYPESENSE_HOST (ou TYPESENSE_NODES) et TYPESENSE_API_KEY dans l’environnement du serveur.',
    'no_index' => 'Typesense répond mais n’a pas encore d’index de produits. Créez l’index.',
    'min_query_length' => 'Caractères minimum',
    'min_query_length_hint' => 'Nombre de caractères qu’une recherche doit contenir avant d’afficher résultats ou suggestions. Les recherches plus courtes affichent un message. Laissez vide ou 0 pour aucun minimum.',
];
