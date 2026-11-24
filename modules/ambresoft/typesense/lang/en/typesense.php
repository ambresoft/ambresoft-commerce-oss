<?php

return [
    'typo_tolerance' => 'Typo tolerance',
    'typo_tolerance_hint' => 'How many typing mistakes a word may have and still match product names. Codes (SKU, MPN, EAN) always match exactly.',
    'typos_normal' => 'Normal (up to 2 per word)',
    'typos_low' => 'Low (1 per word)',
    'typos_off' => 'Off (exact words only)',
    'synonyms' => 'Synonyms',
    'synonyms_hint' => 'One group per line. "laptop, notebook" makes the words equivalent; "phone => smartphone, mobile" also finds smartphones and mobiles when someone searches for phone. Saved synonyms apply to new searches at once.',
    'synonyms_invalid' => 'Write one group per line: at least two words separated by commas, or "word => other, words". Words of up to 60 characters, up to 200 lines.',
    'not_configured' => 'Typesense is not configured: set TYPESENSE_HOST (or TYPESENSE_NODES) and TYPESENSE_API_KEY in the server environment.',
    'no_index' => 'Typesense is reachable but has no product index yet. Build the index.',
    'min_query_length' => 'Minimum characters',
    'min_query_length_hint' => 'How many characters a search needs before results or suggestions appear. Shorter searches show a message instead. Leave empty or set 0 for no minimum.',
];
