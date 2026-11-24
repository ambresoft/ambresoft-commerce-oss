<?php

return [
    ...(require __DIR__.'/category_import.php'),
    'title' => 'Importer des régions',
    'intro' => 'Créer ou modifier les régions des pays de livraison depuis un CSV. Vérifiez les changements avant d’enregistrer.',
    'created' => 'Nouvelles régions',
    'run' => 'Importer les régions maintenant',
    'preview' => 'Aperçu : aucune région modifiée',
    'failed' => 'Échec : aucune modification de région enregistrée',
    'identifier' => 'Région / pays et code',
    'invalid_id' => 'region_id doit identifier une région existante.',
    'duplicate' => 'La même région ou paire pays/code apparaît plusieurs fois dans le fichier.',
    'shipping_required' => 'Choisissez un pays ISO valide déjà activé pour la livraison.',
    'country_immutable' => 'Une région ne peut pas être déplacée vers un autre pays. Créez une nouvelle région.',
    'stale' => 'Le fichier, les régions, les pays de livraison, les langues ou les permissions ont changé. Importez à nouveau pour un nouvel aperçu.',
    'help' => 'Utilisez region_id pour modifier ou country_code + code pour rechercher ou créer une région. Les pays doivent être activés pour la livraison. country_code ne peut pas être modifié. Seuls les noms des langues actives sont acceptés ; la langue par défaut est obligatoire. Les noms omis restent inchangés ; un nom vide hors langue par défaut supprime cette traduction. code/status vides conservent les valeurs. Les nouvelles régions sont désactivées. Maximum 20 MiB / 10 000 lignes. Les aperçus expirent après une heure.',
    'headers' => 'Les en-têtes doivent être des noms de colonnes canoniques uniques. Les colonnes non prises en charge, de langues inactives et système sont refusées.',
    'options_invalid' => 'Vérifiez la taille du fichier et choisissez un séparateur CSV pris en charge.',
];
