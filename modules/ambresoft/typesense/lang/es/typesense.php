<?php

return [
    'typo_tolerance' => 'Tolerancia a errores',
    'typo_tolerance_hint' => 'Cuántos errores de escritura puede tener una palabra y seguir coincidiendo con nombres de productos. Los códigos (SKU, MPN, EAN) siempre coinciden exactamente.',
    'typos_normal' => 'Normal (hasta 2 por palabra)',
    'typos_low' => 'Baja (1 por palabra)',
    'typos_off' => 'Desactivada (solo palabras exactas)',
    'synonyms' => 'Sinónimos',
    'synonyms_hint' => 'Un grupo por línea. «portátil, notebook» hace equivalentes las palabras; «móvil => smartphone, teléfono» también encuentra smartphones y teléfonos al buscar móvil. Los sinónimos guardados se aplican al instante.',
    'synonyms_invalid' => 'Escribe un grupo por línea: al menos dos palabras separadas por comas, o «palabra => otras, palabras». Palabras de hasta 60 caracteres, hasta 200 líneas.',
    'not_configured' => 'Typesense no está configurado: define TYPESENSE_HOST (o TYPESENSE_NODES) y TYPESENSE_API_KEY en el entorno del servidor.',
    'no_index' => 'Typesense responde pero aún no tiene índice de productos. Crea el índice.',
    'min_query_length' => 'Caracteres mínimos',
    'min_query_length_hint' => 'Cuántos caracteres necesita una búsqueda antes de mostrar resultados o sugerencias. Las búsquedas más cortas muestran un mensaje. Déjalo vacío o en 0 para no exigir mínimo.',
];
