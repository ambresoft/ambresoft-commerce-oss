<?php

namespace App\Geography\Infrastructure;

use App\Geography\Application\Contract\GeographyAdministration;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * The 51 Greek nomoi (prefectures) as used in shipping and address forms, with Greek and English names.
 * Reference data, not sample figures: it enables Greece for shipping and adds the missing regions, once.
 */
final class GreekRegions
{
    /** @var array<string, array{string, string}> code => [Greek, English] */
    public const REGIONS = [
        'AIT' => ['Αιτωλοακαρνανία', 'Aetolia-Acarnania'], 'ARG' => ['Αργολίδα', 'Argolis'], 'ARK' => ['Αρκαδία', 'Arcadia'],
        'ART' => ['Άρτα', 'Arta'], 'ATT' => ['Αττική', 'Attica'], 'AXA' => ['Αχαΐα', 'Achaea'],
        'BOI' => ['Βοιωτία', 'Boeotia'], 'GRE' => ['Γρεβενά', 'Grevena'], 'DRA' => ['Δράμα', 'Drama'],
        'DOD' => ['Δωδεκάνησα', 'Dodecanese'], 'EVR' => ['Έβρος', 'Evros'], 'EVV' => ['Εύβοια', 'Euboea'],
        'EVT' => ['Ευρυτανία', 'Evrytania'], 'ZAK' => ['Ζάκυνθος', 'Zakynthos'], 'ILI' => ['Ηλεία', 'Elis'],
        'IMA' => ['Ημαθία', 'Imathia'], 'IRA' => ['Ηράκλειο', 'Heraklion'], 'THP' => ['Θεσπρωτία', 'Thesprotia'],
        'THS' => ['Θεσσαλονίκη', 'Thessaloniki'], 'IOA' => ['Ιωάννινα', 'Ioannina'], 'KAV' => ['Καβάλα', 'Kavala'],
        'KAR' => ['Καρδίτσα', 'Karditsa'], 'KAS' => ['Καστοριά', 'Kastoria'], 'KER' => ['Κέρκυρα', 'Corfu'],
        'KEF' => ['Κεφαλονιά', 'Kefalonia'], 'KIL' => ['Κιλκίς', 'Kilkis'], 'KOZ' => ['Κοζάνη', 'Kozani'],
        'KOR' => ['Κορινθία', 'Corinthia'], 'KYK' => ['Κυκλάδες', 'Cyclades'], 'LAK' => ['Λακωνία', 'Laconia'],
        'LAR' => ['Λάρισα', 'Larissa'], 'LAS' => ['Λασίθι', 'Lasithi'], 'LES' => ['Λέσβος', 'Lesbos'],
        'LEF' => ['Λευκάδα', 'Lefkada'], 'MAG' => ['Μαγνησία', 'Magnesia'], 'MES' => ['Μεσσηνία', 'Messenia'],
        'XAN' => ['Ξάνθη', 'Xanthi'], 'PEL' => ['Πέλλα', 'Pella'], 'PIE' => ['Πιερία', 'Pieria'],
        'PRE' => ['Πρέβεζα', 'Preveza'], 'RET' => ['Ρέθυμνο', 'Rethymno'], 'ROD' => ['Ροδόπη', 'Rhodope'],
        'SAM' => ['Σάμος', 'Samos'], 'SER' => ['Σέρρες', 'Serres'], 'TRI' => ['Τρίκαλα', 'Trikala'],
        'FTH' => ['Φθιώτιδα', 'Phthiotis'], 'FLO' => ['Φλώρινα', 'Florina'], 'FOK' => ['Φωκίδα', 'Phocis'],
        'CHL' => ['Χαλκιδική', 'Chalkidiki'], 'CHN' => ['Χανιά', 'Chania'], 'CHI' => ['Χίος', 'Chios'],
    ];

    public function __construct(private GeographyAdministration $geography) {}

    /** @return array{created: int, existing: int} */
    public function run(string $actor): array
    {
        $this->geography->setShipping($actor, 'GR', true);
        $default = app(LocaleCatalog::class)->defaultCode();
        $active = app(LocaleCatalog::class)->active()->pluck('code')->all();
        $created = $existing = 0;
        foreach (self::REGIONS as $code => [$greek, $english]) {
            if (DB::table('regions')->where('country_code', 'GR')->where('code', $code)->exists()) {
                $existing++;

                continue;
            }
            // Greek where Greek is active, English elsewhere; the default language always has a name.
            $names = [];
            foreach ($active as $locale) {
                $names[$locale] = $locale === 'el' ? $greek : $english;
            }
            $names[$default] ??= $english;
            $this->geography->createRegion($actor, 'GR', $code, $names, 'active');
            $created++;
        }

        return ['created' => $created, 'existing' => $existing];
    }
}
