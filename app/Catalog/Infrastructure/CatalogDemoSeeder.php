<?php

namespace App\Catalog\Infrastructure;

use App\Shared\Infrastructure\DemoPicture;
use Illuminate\Support\Facades\DB;

/** The demo electronics shop: phones, tablets, laptops, audio, wearables and accessories (English and Greek), with the
 * bundled CC0 photos in database/demo/images. Brands and product names are fictional. */
final class CatalogDemoSeeder
{
    /** @var array<string,array{?string,string,string}> key => parent key, English name, Greek name (the key is the slug) */
    public const CATEGORIES = [
        'phones' => [null, 'Phones', 'Κινητά τηλέφωνα'],
        'smartphones' => ['phones', 'Smartphones', 'Smartphones'],
        'cases-protection' => ['phones', 'Cases & protection', 'Θήκες & προστασία'],
        'chargers-cables' => ['phones', 'Chargers & cables', 'Φορτιστές & καλώδια'],
        'tablets' => [null, 'Tablets', 'Tablets'],
        'everyday-tablets' => ['tablets', 'Everyday tablets', 'Tablets για κάθε μέρα'],
        'pro-tablets' => ['tablets', 'Pro tablets & pens', 'Pro tablets & γραφίδες'],
        'e-readers' => ['tablets', 'E-readers', 'Ηλεκτρονικοί αναγνώστες'],
        'laptops' => [null, 'Laptops', 'Laptops'],
        'ultrabooks' => ['laptops', 'Ultrabooks', 'Ultrabooks'],
        'gaming-laptops' => ['laptops', 'Gaming laptops', 'Gaming laptops'],
        'business-laptops' => ['laptops', 'Business laptops', 'Laptops για επαγγελματίες'],
        'audio' => [null, 'Audio', 'Ήχος'],
        'headphones' => ['audio', 'Headphones', 'Ακουστικά'],
        'earbuds' => ['audio', 'Earbuds', 'Earbuds'],
        'speakers' => ['audio', 'Speakers', 'Ηχεία'],
        'wearables' => [null, 'Wearables', 'Φορητές συσκευές'],
        'smartwatches' => ['wearables', 'Smartwatches', 'Smartwatches'],
        'fitness-trackers' => ['wearables', 'Fitness trackers', 'Fitness trackers'],
        'accessories' => [null, 'Accessories', 'Αξεσουάρ'],
        'keyboards-mice' => ['accessories', 'Keyboards & mice', 'Πληκτρολόγια & ποντίκια'],
        'laptop-bags' => ['accessories', 'Laptop bags', 'Τσάντες laptop'],
    ];

    /** @var array<string,array{string,string}> short English and Greek description of each top-level category */
    private const INTROS = [
        'phones' => ['The latest smartphones and everything that keeps them charged and protected.', 'Τα νεότερα smartphones και ό,τι χρειάζεστε για φόρτιση και προστασία.'],
        'tablets' => ['Tablets for streaming, study and creative work, plus e-readers for every book lover.', 'Tablets για ψυχαγωγία, μελέτη και δημιουργία, καθώς και e-readers για κάθε βιβλιόφιλο.'],
        'laptops' => ['Featherweight ultrabooks, powerful gaming machines and secure business laptops.', 'Πανάλαφρα ultrabooks, ισχυρά gaming μηχανήματα και ασφαλή laptops για επαγγελματίες.'],
        'audio' => ['Noise-cancelling headphones, true wireless earbuds and portable speakers.', 'Ακουστικά με ακύρωση θορύβου, true wireless earbuds και φορητά ηχεία.'],
        'wearables' => ['Smartwatches and fitness trackers that keep you moving.', 'Smartwatches και fitness trackers που σας κρατούν σε κίνηση.'],
        'accessories' => ['Keyboards, mice and bags that complete your setup.', 'Πληκτρολόγια, ποντίκια και τσάντες που ολοκληρώνουν τον εξοπλισμό σας.'],
    ];

    /** @var list<array{string,string,string,string,string,?string,int,?int,string,string}> category key, English name, Greek name, slug (also the photo), price, list price, stock, sale percentage, English and Greek description */
    private const PRODUCTS = [
        ['smartphones', 'Aster X5 128GB', 'Aster X5 128GB', 'aster-x5', '699', '749', 40, 10, '6.1" OLED display, dual 50 MP camera and a battery that lasts all day.', 'Οθόνη OLED 6,1", διπλή κάμερα 50 MP και μπαταρία που κρατά όλη τη μέρα.'],
        ['smartphones', 'Aster X5 Pro 256GB', 'Aster X5 Pro 256GB', 'aster-x5-pro', '949', null, 25, null, '6.7" 120 Hz display, triple camera with 5× zoom and 45 W fast charging.', 'Οθόνη 6,7" 120 Hz, τριπλή κάμερα με 5× zoom και γρήγορη φόρτιση 45 W.'],
        ['smartphones', 'Aster Neo 5G 128GB', 'Aster Neo 5G 128GB', 'aster-neo-5g', '399', '449', 60, null, 'Fast 5G, a bright 6.4" screen and two days of battery at a friendly price.', 'Γρήγορο 5G, φωτεινή οθόνη 6,4" και μπαταρία δύο ημερών σε φιλική τιμή.'],
        ['smartphones', 'Aster Lite 64GB', 'Aster Lite 64GB', 'aster-lite', '229', null, 80, 15, 'Everything you need in a compact phone: calls, photos, maps and messages.', 'Ό,τι χρειάζεστε σε ένα compact κινητό: κλήσεις, φωτογραφίες, χάρτες και μηνύματα.'],
        ['cases-protection', 'Kova Slim case (2-pack)', 'Θήκη Kova Slim (2 τεμ.)', 'kova-slim-case', '24.90', null, 150, null, 'Two printed slim cases with raised edges that protect screen and camera.', 'Δύο λεπτές θήκες με σχέδιο και υπερυψωμένα άκρα που προστατεύουν οθόνη και κάμερα.'],
        ['cases-protection', 'Kova Prism case', 'Θήκη Kova Prism', 'kova-prism-case', '19.90', '24.90', 120, null, 'Colourful shock-absorbing case with a soft-touch finish.', 'Πολύχρωμη θήκη που απορροφά τους κραδασμούς, με απαλό φινίρισμα.'],
        ['cases-protection', 'Kova Folio leather case', 'Θήκη Kova Folio δερμάτινη', 'kova-folio-case', '39', null, 70, null, 'Genuine leather folio with card slots and a magnetic closure.', 'Δερμάτινη θήκη-πορτοφόλι με θέσεις για κάρτες και μαγνητικό κλείσιμο.'],
        ['chargers-cables', 'Kova 30W USB-C charger', 'Φορτιστής Kova USB-C 30W', 'kova-30w-charger', '29', null, 200, null, 'Compact 30 W wall charger that fast-charges phones, tablets and earbuds.', 'Compact φορτιστής τοίχου 30 W για γρήγορη φόρτιση κινητών, tablets και earbuds.'],
        ['chargers-cables', 'Kova USB-C cable 2 m', 'Καλώδιο Kova USB-C 2 μ.', 'kova-usb-c-cable', '14.90', null, 300, null, 'Braided 2 m USB-C cable rated for 100 W charging and fast data.', 'Πλεκτό καλώδιο USB-C 2 μ. για φόρτιση έως 100 W και γρήγορη μεταφορά δεδομένων.'],
        ['chargers-cables', 'Kova 6-port charging hub', 'Σταθμός φόρτισης Kova 6 θυρών', 'kova-charging-hub', '49', '59', 45, null, 'Charge six devices at once from one outlet, with smart power sharing.', 'Φορτίστε έξι συσκευές ταυτόχρονα από μία πρίζα, με έξυπνο διαμοιρασμό ισχύος.'],
        ['everyday-tablets', 'Aster Tab 11 128GB', 'Aster Tab 11 128GB', 'aster-tab-11', '349', '399', 35, null, '11" display, quad speakers and a battery for a full day of streaming.', 'Οθόνη 11", τέσσερα ηχεία και μπαταρία για μια ολόκληρη μέρα streaming.'],
        ['everyday-tablets', 'Aster Tab 8 mini 64GB', 'Aster Tab 8 mini 64GB', 'aster-tab-8-mini', '249', null, 40, 10, 'Light 8" tablet that fits in a coat pocket — perfect for reading and travel.', 'Ελαφρύ tablet 8" που χωράει στην τσέπη — ιδανικό για διάβασμα και ταξίδια.'],
        ['pro-tablets', 'Aster Tab Pro 13 256GB', 'Aster Tab Pro 13 256GB', 'aster-tab-pro-13', '999', '1099', 15, null, '13" 120 Hz display and desktop-class performance for drawing and editing.', 'Οθόνη 13" 120 Hz και επιδόσεις υπολογιστή για σχέδιο και επεξεργασία.'],
        ['pro-tablets', 'Aster Pen 2', 'Γραφίδα Aster Pen 2', 'aster-pen-2', '129', null, 60, null, 'Pressure-sensitive pen with tilt support that charges magnetically.', 'Γραφίδα με ευαισθησία πίεσης και κλίσης που φορτίζει μαγνητικά.'],
        ['e-readers', 'Lumo Read 6', 'Lumo Read 6', 'lumo-read-6', '129', null, 50, null, 'Glare-free 6" e-ink screen and weeks of battery on a single charge.', 'Οθόνη e-ink 6" χωρίς αντανακλάσεις και μπαταρία για εβδομάδες.'],
        ['e-readers', 'Lumo Read Paper 7', 'Lumo Read Paper 7', 'lumo-read-paper-7', '179', '199', 30, null, 'Waterproof 7" e-reader with warm light for reading at night.', 'Αδιάβροχος e-reader 7" με θερμό φωτισμό για διάβασμα τη νύχτα.'],
        ['ultrabooks', 'Nimbus Air 13', 'Nimbus Air 13', 'nimbus-air-13', '1099', '1199', 20, null, '1.2 kg aluminium ultrabook with an 18-hour battery and a silent, fanless design.', 'Ultrabook αλουμινίου 1,2 κιλών με μπαταρία 18 ωρών και αθόρυβο σχεδιασμό χωρίς ανεμιστήρα.'],
        ['ultrabooks', 'Nimbus Air 15', 'Nimbus Air 15', 'nimbus-air-15', '1299', null, 18, 15, 'A big 15" screen in a thin, light body with six speakers.', 'Μεγάλη οθόνη 15" σε λεπτό, ελαφρύ σώμα με έξι ηχεία.'],
        ['gaming-laptops', 'Nimbus Strike 16', 'Nimbus Strike 16', 'nimbus-strike-16', '1799', '1899', 8, null, '16" 240 Hz display and a dedicated graphics card for smooth gaming.', 'Οθόνη 16" 240 Hz και αποκλειστική κάρτα γραφικών για ομαλό gaming.'],
        ['business-laptops', 'Nimbus Pro 14', 'Nimbus Pro 14', 'nimbus-pro-14', '1499', null, 12, null, 'Durable 14" business laptop with a fingerprint reader and a privacy screen.', 'Ανθεκτικό επαγγελματικό laptop 14" με αναγνώστη δακτυλικού αποτυπώματος και οθόνη απορρήτου.'],
        ['headphones', 'Volta Studio ANC', 'Volta Studio ANC', 'volta-studio-anc', '249', '299', 45, null, 'Over-ear headphones with adaptive noise cancelling and 40 hours of playback.', 'Ακουστικά over-ear με προσαρμοζόμενη ακύρωση θορύβου και 40 ώρες αναπαραγωγής.'],
        ['headphones', 'Volta Street on-ear', 'Volta Street on-ear', 'volta-street', '89', null, 70, 20, 'Light, foldable on-ear headphones with a built-in microphone.', 'Ελαφριά, αναδιπλούμενα ακουστικά on-ear με ενσωματωμένο μικρόφωνο.'],
        ['earbuds', 'Volta Buds Pro', 'Volta Buds Pro', 'volta-buds-pro', '179', null, 90, null, 'True wireless earbuds with noise cancelling and spatial audio.', 'True wireless earbuds με ακύρωση θορύβου και χωρικό ήχο.'],
        ['earbuds', 'Volta Buds', 'Volta Buds', 'volta-buds', '99', '119', 120, null, 'Comfortable everyday earbuds with a pocket-sized charging case.', 'Άνετα earbuds για κάθε μέρα με θήκη φόρτισης τσέπης.'],
        ['speakers', 'Volta Cube speaker', 'Ηχείο Volta Cube', 'volta-cube', '59', null, 100, 15, 'Pocket Bluetooth speaker with big sound and 12 hours of battery.', 'Ηχείο Bluetooth τσέπης με δυνατό ήχο και μπαταρία 12 ωρών.'],
        ['smartwatches', 'Orbit Watch 3', 'Orbit Watch 3', 'orbit-watch-3', '299', '329', 35, null, 'Always-on display, heart-rate and sleep tracking, and contactless payments.', 'Οθόνη always-on, μέτρηση παλμών και ύπνου, και ανέπαφες πληρωμές.'],
        ['smartwatches', 'Orbit Watch 3 Sport', 'Orbit Watch 3 Sport', 'orbit-watch-3-sport', '279', null, 30, null, 'The Orbit Watch 3 with a breathable sport band and swim tracking.', 'Το Orbit Watch 3 με αθλητικό λουράκι και καταγραφή κολύμβησης.'],
        ['smartwatches', 'Orbit Trail GPS', 'Orbit Trail GPS', 'orbit-trail-gps', '349', null, 15, 10, 'Rugged outdoor watch with dual-band GPS, maps and a two-week battery.', 'Ανθεκτικό ρολόι εξωτερικού χώρου με GPS διπλής ζώνης, χάρτες και μπαταρία δύο εβδομάδων.'],
        ['fitness-trackers', 'Orbit Band 5 Lime', 'Orbit Band 5 Lime', 'orbit-band-5-lime', '49', null, 140, null, 'Slim fitness band that counts steps, heart rate and sleep.', 'Λεπτό fitness band που μετρά βήματα, παλμούς και ύπνο.'],
        ['fitness-trackers', 'Orbit Band 5 Midnight', 'Orbit Band 5 Midnight', 'orbit-band-5-midnight', '49', '59', 110, null, 'The Orbit Band 5 in midnight blue, water resistant to 50 m.', 'Το Orbit Band 5 σε μπλε midnight, αδιάβροχο έως 50 μ.'],
        ['keyboards-mice', 'Kova wireless keyboard & mouse set', 'Σετ ασύρματο πληκτρολόγιο & ποντίκι Kova', 'kova-keyboard-mouse-set', '79', null, 60, null, 'Quiet, slim keyboard and mouse that connect to three devices.', 'Αθόρυβο, λεπτό πληκτρολόγιο και ποντίκι που συνδέονται σε τρεις συσκευές.'],
        ['keyboards-mice', 'Kova ergonomic mouse', 'Εργονομικό ποντίκι Kova', 'kova-ergonomic-mouse', '39', null, 90, null, 'Sculpted mouse that supports your hand during long working days.', 'Ποντίκι με εργονομικό σχήμα που στηρίζει το χέρι σε μεγάλες μέρες δουλειάς.'],
        ['keyboards-mice', 'Kova travel mouse', 'Ποντίκι ταξιδιού Kova', 'kova-travel-mouse', '24.90', '29.90', 130, null, 'Compact wireless mouse with a silent click for work on the go.', 'Compact ασύρματο ποντίκι με αθόρυβο κλικ για δουλειά εν κινήσει.'],
        ['laptop-bags', 'Kova commuter backpack 15"', 'Σακίδιο Kova commuter 15"', 'kova-commuter-backpack', '69', null, 55, null, 'Water-repellent backpack with a padded 15" laptop sleeve.', 'Αδιάβροχο σακίδιο με ενισχυμένη θήκη για laptop 15".'],
    ];

    /** @return array{categories:int,products:int,ids:array<string,int>}|null null when the demo catalog already exists */
    public function run(): ?array
    {
        return DB::transaction(fn () => $this->seed());
    }

    /** @return array{categories:int,products:int,ids:array<string,int>}|null */
    private function seed(): ?array
    {
        $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code')->all();
        if (DB::table('products')->where('sku', 'like', 'TECH-%')->exists()) {
            return null;
        }
        $pictures = app(DemoPicture::class);
        $ids = [];
        $created = 0;
        $positions = [];
        foreach (self::CATEGORIES as $key => [$parent, $en, $el]) {
            // A category with the same name already exists: reuse it instead of opening a duplicate.
            if ($existing = $this->existing($en)) {
                $ids[$key] = $existing;

                continue;
            }
            $created++;
            $parentId = $parent === null ? null : $ids[$parent];
            $positionKey = $parent ?? '';
            $positions[$positionKey] = ($positions[$positionKey] ?? 0) + 1;
            $id = (int) DB::table('categories')->insertGetId(['parent_id' => $parentId, 'position' => $positions[$positionKey] + 100, 'status' => 'active', 'image_media_id' => $pictures->photo(self::photo($key)), 'created_at' => now(), 'updated_at' => now()]);
            $ids[$key] = $id;
            foreach ($locales as $code => $locale) {
                $name = $code === 'el' ? $el : $en;
                $intro = self::INTROS[$key][$code === 'el' ? 1 : 0] ?? '';
                // Complete SEO texts: the strict category editor refuses any save of a category missing them in an active language.
                DB::table('category_translations')->insert(['category_id' => $id, 'locale_id' => $locale, 'name' => $name, 'slug' => $this->free('category_translations', $locale, $key), 'description' => $intro === '' ? '' : '<p>'.$intro.'</p>', 'age_warning_message' => '', 'image_alt' => $name,
                    'seo_title' => $name, 'seo_description' => $intro === '' ? $name : $intro, 'meta_keywords' => mb_strtolower($name), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (self::PRODUCTS as $n => [$category, $en, $el, $slug, $price, $list, $stock, $sale, $descriptionEn, $descriptionEl]) {
            // Spread over the last days so "New arrivals" lists them newest first.
            $product = (int) DB::table('products')->insertGetId(['sku' => 'TECH-'.strtoupper(substr(md5($slug), 0, 8)), 'status' => 'active', 'price' => $price, 'list_price' => $list, 'category_id' => $ids[$category], 'quantity' => $stock, 'created_at' => now()->subHours(4 * $n), 'updated_at' => now()]);
            foreach ($locales as $code => $locale) {
                DB::table('product_translations')->insert(['product_id' => $product, 'locale_id' => $locale, 'name' => $code === 'el' ? $el : $en, 'slug' => $this->free('product_translations', $locale, $slug), 'description' => '<p>'.($code === 'el' ? $descriptionEl : $descriptionEn).'</p>', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('product_images')->insert(['product_id' => $product, 'media_file_id' => $pictures->photo($slug), 'position' => 1, 'is_primary' => true]);
            if ($sale !== null) {
                DB::table('product_sales')->insert(['product_id' => $product, 'kind' => 'percentage', 'amount' => $sale, 'minimum_quantity' => 1, 'priority' => 1, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return ['categories' => $created, 'products' => count(self::PRODUCTS), 'ids' => $ids];
    }

    /** The bundled photo of a category: its own for a top-level category, its first product's for a sub-category. */
    public static function photo(string $key): string
    {
        if (self::CATEGORIES[$key][0] === null) {
            return 'category-'.$key;
        }
        foreach (self::PRODUCTS as $product) {
            if ($product[0] === $key) {
                return $product[3];
            }
        }

        return 'category-'.self::CATEGORIES[$key][0];
    }

    /** The id of the (non-system) category with this English name, if any. */
    private function existing(string $name): ?int
    {
        $id = DB::table('category_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->join('categories as c', 'c.id', '=', 't.category_id')
            ->where('l.code', 'en')->whereNull('c.system_key')->whereRaw('lower(btrim(t.name)) = ?', [mb_strtolower(trim($name))])->orderBy('c.id')->value('c.id');

        return $id === null ? null : (int) $id;
    }

    /** An unused slug for the language: an existing record with the same slug makes the sample one "-demo". */
    private function free(string $table, int $locale, string $slug): string
    {
        return DB::table($table)->where('locale_id', $locale)->where('slug', $slug)->exists() ? $slug.'-demo' : $slug;
    }

    /** @return array<string,int> category key => id, for the demo categories that exist */
    public function ids(): array
    {
        $ids = [];
        foreach (self::CATEGORIES as $key => $row) {
            if ($id = $this->existing($row[1])) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }
}
