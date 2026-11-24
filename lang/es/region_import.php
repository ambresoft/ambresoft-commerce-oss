<?php

return [
    ...(require __DIR__.'/category_import.php'),
    'title' => 'Importar regiones',
    'intro' => 'Crear o actualizar regiones de países de envío desde un CSV. Revisa los cambios antes de guardar.',
    'created' => 'Nuevas regiones',
    'run' => 'Importar regiones ahora',
    'preview' => 'Vista previa: ninguna región modificada',
    'failed' => 'Error: no se guardaron cambios de regiones',
    'identifier' => 'Región / país y código',
    'invalid_id' => 'region_id debe identificar una región existente.',
    'duplicate' => 'La misma región o combinación de país y código aparece más de una vez en el archivo.',
    'shipping_required' => 'Elige un país ISO válido que ya esté habilitado para envíos.',
    'country_immutable' => 'Una región no puede trasladarse a otro país. Crea una nueva región.',
    'stale' => 'El archivo, las regiones, los países de envío, los idiomas o los permisos cambiaron. Sube de nuevo para otra vista previa.',
    'help' => 'Usa region_id para actualizar o country_code + code para buscar o crear una región. Los países deben estar habilitados para envíos. country_code no puede cambiarse. Solo se aceptan nombres en idiomas activos; el idioma predeterminado es obligatorio. Los nombres omitidos no cambian; un nombre vacío no predeterminado elimina esa traducción. code/status vacíos conservan los valores existentes. Las nuevas regiones están desactivadas. Máximo 20 MiB / 10.000 filas. Las vistas previas caducan en una hora.',
    'headers' => 'Los encabezados deben ser nombres de columna canónicos y únicos. Se rechazan columnas no admitidas, de idiomas inactivos y del sistema.',
    'options_invalid' => 'Comprueba el tamaño del archivo y elige un delimitador CSV compatible.',
];
