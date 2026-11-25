<?php

return [
    ...(require __DIR__.'/category_import.php'),
    'title' => 'Regio’s importeren',
    'intro' => 'Verzendregio’s maken of bijwerken vanuit een CSV. Controleer de wijzigingen voordat je opslaat.',
    'created' => 'Nieuwe regio’s',
    'run' => 'Regio’s nu importeren',
    'preview' => 'Voorbeeld: geen regio’s gewijzigd',
    'failed' => 'Mislukt: geen regiowijzigingen opgeslagen',
    'identifier' => 'Regio / land en code',
    'invalid_id' => 'region_id moet een bestaande regio identificeren.',
    'duplicate' => 'Dezelfde regio of combinatie van land en code staat meer dan één keer in het bestand.',
    'shipping_required' => 'Kies een geldig ISO-land dat al voor verzending is ingeschakeld.',
    'country_immutable' => 'Een regio kan niet naar een ander land worden verplaatst. Maak een nieuwe regio.',
    'stale' => 'Het bestand, de regio’s, verzendlanden, talen of rechten zijn gewijzigd. Upload opnieuw voor een nieuw voorbeeld.',
    'help' => 'Gebruik region_id om bij te werken of country_code + code om een regio te zoeken of te maken. Landen moeten voor verzending zijn ingeschakeld. country_code kan niet worden gewijzigd. Alleen namen in actieve talen zijn toegestaan; de standaardtaal is verplicht. Weggelaten namen blijven ongewijzigd; een lege naam buiten de standaardtaal verwijdert die vertaling. Lege code/status behoudt bestaande waarden. Nieuwe regio’s staan uit. Maximaal 20 MiB / 10.000 rijen. Voorbeelden verlopen na een uur.',
    'headers' => 'Kopteksten moeten unieke canonieke kolomnamen zijn. Niet-ondersteunde kolommen, inactieve talen en systeemkolommen worden geweigerd.',
    'options_invalid' => 'Controleer de bestandsgrootte en kies een ondersteund CSV-scheidingsteken.',
];
