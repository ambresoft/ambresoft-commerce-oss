<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\CategoryAdministrationInterface;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FeatureDemoSeeder
{
    /** Explicit opt-in only. A marker is never matched by a translated name or a merchant SKU. */
    public function run(string $actor): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Feature demo seeding is restricted to local and testing environments.');
        }

        return DB::transaction(function () use ($actor): int {
            app(CategoryHierarchy::class)->lock();
            $locales = app(LocaleCatalog::class)->active()->pluck('code')->all();
            if (array_diff($locales, ['en', 'el']) !== []) {
                throw new RuntimeException('This curated demo has English and Greek content only. Use a separate local installation with those languages enabled; merchant language settings are never changed.');
            }
            $root = $this->category($actor, 'catalog', null, 'Demo technology & travel', 'Επίδειξη τεχνολογίας και ταξιδιού');
            $audio = $this->category($actor, 'audio', $root, 'Audio', 'Ήχος');
            $categories = [];
            foreach (['headphones' => [$audio, 'Headphones', 'Ακουστικά'], 'speakers' => [$audio, 'Speakers', 'Ηχεία'], 'computing' => [$root, 'Computer accessories', 'Αξεσουάρ υπολογιστή'], 'travel' => [$root, 'Travel accessories', 'Αξεσουάρ ταξιδιού']] as $key => [$parent, $en, $el]) {
                $categories[$key] = $this->category($actor, $key, $parent, $en, $el);
            }
            $features = app(FeatureAdministration::class);
            $general = (string) DB::table('feature_groups')->where('system_key', 'general')->value('id');
            $groups = ['general' => $general];
            foreach (['specifications' => ['Specifications', 'Προδιαγραφές'], 'connectivity' => ['Connectivity', 'Συνδεσιμότητα'], 'physical' => ['Physical details', 'Φυσικά χαρακτηριστικά']] as $key => [$en, $el]) {
                $groups[$key] = $this->record('group.'.$key, 'feature_groups', fn (): string => $features->group($actor, null, new SaveFeatureData(['action' => 'save', 'translations' => $this->texts(['name' => $en, 'description' => $en], ['name' => $el, 'description' => $el])])));
            }
            $definitions = [
                'brand' => ['single', 'general', 'Brand', 'Μάρκα', '', true, [$root]],
                'color' => ['multiple', 'physical', 'Available colors', 'Διαθέσιμα χρώματα', '', true, [$root]],
                'weight' => ['number', 'physical', 'Weight', 'Βάρος', 'g', true, [$root]],
                'wireless' => ['boolean', 'connectivity', 'Wireless connectivity', 'Ασύρματη σύνδεση', '', true, [$root]],
                'notes' => ['text', 'specifications', 'Compatibility notes', 'Σημειώσεις συμβατότητας', '', true, [$root]],
                'battery' => ['number', 'specifications', 'Battery life', 'Διάρκεια μπαταρίας', 'h', false, [$audio]],
                'connection' => ['multiple', 'connectivity', 'Connections', 'Συνδέσεις', '', false, [$audio, $categories['computing']]],
                'material' => ['single', 'physical', 'Outer material', 'Εξωτερικό υλικό', '', false, [$root]],
            ];
            $ids = [];
            foreach ($definitions as $key => [$type, $group, $en, $el, $unit, $required, $scope]) {
                $ids[$key] = $this->record('feature.'.$key, 'features', fn (): string => $features->save($actor, null, new SaveFeatureData(['type' => $type, 'group_id' => $groups[$group], 'required' => $required, 'filterable' => $type !== 'text', 'comparison' => true, 'unit' => $unit, 'categories' => $scope, 'translations' => $this->texts(['name' => $en, 'description' => $en], ['name' => $el, 'description' => $el])])));
            }
            $options = [];
            $optionData = ['brand' => ['aster' => ['Aster', 'Aster'], 'nimbus' => ['Nimbus', 'Nimbus'], 'trail' => ['Trailcraft', 'Trailcraft']], 'color' => ['black' => ['Black', 'Μαύρο'], 'silver' => ['Silver', 'Ασημί'], 'navy' => ['Navy blue', 'Μπλε σκούρο']], 'connection' => ['bluetooth' => ['Bluetooth 5.3', 'Bluetooth 5.3'], 'usb' => ['USB-C', 'USB-C'], 'hdmi' => ['HDMI 2.0', 'HDMI 2.0'], 'radio' => ['2.4 GHz receiver', 'Δέκτης 2,4 GHz']], 'material' => ['plastic' => ['Recycled plastic', 'Ανακυκλωμένο πλαστικό'], 'aluminum' => ['Aluminum', 'Αλουμίνιο'], 'polyester' => ['Recycled polyester', 'Ανακυκλωμένος πολυεστέρας']]];
            foreach ($optionData as $feature => $values) {
                foreach ($values as $key => [$en, $el]) {
                    $options[$feature][$key] = $this->record('option.'.$feature.'.'.$key, 'feature_options', fn (): string => $features->option($actor, $ids[$feature], null, new SaveFeatureData(['feature_version' => DB::table('features')->where('id', $ids[$feature])->value('lock_version'), 'action' => 'save', 'translations' => $this->texts(['label' => $en], ['label' => $el])])));
                }
            }
            $products = app(ProductAdministration::class);
            $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
            $created = 0;
            foreach ($this->products() as $index => $row) {
                [$key, $en, $el, $category, $price, $weight, $wireless, $colors, $connections, $brand, $material, $battery, $notesEn, $notesEl] = $row;
                $this->record('product.'.$key, 'products', function () use ($actor, $products, $ids, $options, $categories, $scale, &$created, $index, $en, $el, $category, $price, $weight, $wireless, $colors, $connections, $brand, $material, $battery, $notesEn, $notesEl): string {
                    $id = $products->draft($actor);
                    $values = [
                        $ids['brand'] => ['version' => 0, 'options' => [$options['brand'][$brand]]],
                        $ids['color'] => ['version' => 0, 'options' => array_map(fn ($color) => $options['color'][$color], $colors)],
                        $ids['material'] => ['version' => 0, 'options' => [$options['material'][$material]]],
                        $ids['weight'] => ['version' => 0, 'number' => $weight],
                        $ids['wireless'] => ['version' => 0, 'boolean' => $wireless ? '1' : '0'],
                        $ids['notes'] => ['version' => 0, 'texts' => array_map(fn ($v) => $v['value'], $this->texts(['value' => $notesEn], ['value' => $notesEl]))],
                    ];
                    if ($battery !== null) {
                        $values[$ids['battery']] = ['version' => 0, 'number' => $battery];
                    }
                    if ($connections !== []) {
                        $values[$ids['connection']] = ['version' => 0, 'options' => array_map(fn ($c) => $options['connection'][$c], $connections)];
                    }
                    $products->save($actor, $id, new SaveProductData(1, ['sku' => 'DEMO-P5-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'type' => 'physical', 'status' => 'active', 'price' => Decimal::round($price, $scale), 'category_id' => $categories[$category], 'translations' => $this->texts(['name' => $en, 'description' => $notesEn], ['name' => $el, 'description' => $notesEl]), 'features' => $values, 'feature_catalog_version' => app(FeatureQueries::class)->revision()]));
                    app(ProductFeatures::class)->assertRequired(ProductRecord::findOrFail($id));
                    $created++;

                    return $id;
                });
            }

            return $created;
        });
    }

    /** @param callable(): string $create */
    private function record(string $key, string $table, callable $create): string
    {
        $key = 'phase5.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== $table || ! DB::table($table)->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo dependency was removed: '.$key.'. No data was changed.');
            }

            return (string) $existing->entity_id;
        }
        $id = $create();
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => $table, 'entity_id' => $id]);

        return $id;
    }

    /** @param array<string, string> $en
     * @param  array<string, string>  $el
     * @return array<string, array<string, string>>
     */
    private function texts(array $en, array $el): array
    {
        return array_intersect_key(['en' => $en, 'el' => $el], array_flip(app(LocaleCatalog::class)->active()->pluck('code')->all()));
    }

    private function category(string $actor, string $key, ?string $parent, string $en, string $el): string
    {
        return $this->record('category.'.$key, 'categories', function () use ($actor, $key, $parent, $en, $el): string {
            $text = fn (string $name): array => ['name' => $name, 'description' => $name, 'seo_title' => $name, 'seo_description' => $name, 'slug' => 'demo-p5-'.$key, 'image_alt' => $name, 'meta_keywords' => $name, 'age_warning_message' => $name];

            return app(CategoryAdministrationInterface::class)->save($actor, null, new SaveCategoryData($parent, (int) DB::table('categories')->where('parent_id', $parent)->max('position') + 1, 'active', $this->texts($text($en), $text($el))));
        });
    }

    /** @return list<array<mixed>> */
    private function products(): array
    {
        return [
            ['headphones', 'Aster QuietWave wireless headphones', 'Ασύρματα ακουστικά Aster QuietWave', 'headphones', '129', '245', true, ['black', 'navy'], ['bluetooth', 'usb'], 'aster', 'plastic', '40', 'Over-ear headphones with USB-C charging; compatible with Bluetooth phones and computers.', 'Ακουστικά κεφαλής με φόρτιση USB-C· συμβατά με κινητά και υπολογιστές Bluetooth.'],
            ['speaker', 'Aster PocketSound Bluetooth speaker', 'Ηχείο Bluetooth Aster PocketSound', 'speakers', '59', '480', true, ['black', 'navy'], ['bluetooth', 'usb'], 'aster', 'plastic', '12', 'Portable stereo speaker with splash-resistant enclosure and USB-C charging.', 'Φορητό στερεοφωνικό ηχείο με προστασία από πιτσιλιές και φόρτιση USB-C.'],
            ['keyboard', 'Nimbus K75 mechanical keyboard', 'Μηχανικό πληκτρολόγιο Nimbus K75', 'computing', '89', '820', false, ['black', 'silver'], ['usb'], 'nimbus', 'aluminum', null, '75% layout, hot-swappable tactile switches; Windows, macOS and Linux.', 'Διάταξη 75%, αφαιρούμενοι απτικοί διακόπτες· Windows, macOS και Linux.'],
            ['mouse', 'Nimbus M2 wireless mouse', 'Ασύρματο ποντίκι Nimbus M2', 'computing', '35', '82', true, ['black'], ['bluetooth', 'radio', 'usb'], 'nimbus', 'plastic', null, 'Six buttons and adjustable 800–3200 DPI sensor; USB receiver included.', 'Έξι κουμπιά και ρυθμιζόμενος αισθητήρας 800–3200 DPI· περιλαμβάνεται δέκτης USB.'],
            ['monitor', 'Nimbus View27 QHD monitor', 'Οθόνη QHD Nimbus View27', 'computing', '249', '4600', false, ['black'], ['hdmi', 'usb'], 'nimbus', 'plastic', null, '27-inch IPS display, 2560 × 1440 resolution, 100 Hz refresh rate and VESA 100 mount.', 'Οθόνη IPS 27 ιντσών, ανάλυση 2560 × 1440, ρυθμός ανανέωσης 100 Hz και βάση VESA 100.'],
            ['dock', 'Nimbus Connect8 USB-C docking station', 'Σταθμός σύνδεσης USB-C Nimbus Connect8', 'computing', '79', '180', false, ['silver'], ['usb', 'hdmi'], 'nimbus', 'aluminum', null, 'Eight ports, 4K HDMI and 100 W pass-through charging; requires USB-C DisplayPort Alt Mode.', 'Οκτώ θύρες, HDMI 4K και φόρτιση διέλευσης 100 W· απαιτεί USB-C DisplayPort Alt Mode.'],
            ['backpack', 'Trailcraft Metro 22 laptop backpack', 'Σακίδιο φορητού υπολογιστή Trailcraft Metro 22', 'travel', '69', '740', false, ['black', 'navy'], [], 'trail', 'polyester', null, '22 L capacity with padded compartment for laptops up to 15.6 inches; water-resistant fabric.', 'Χωρητικότητα 22 L με επενδεδυμένη θήκη για φορητούς υπολογιστές έως 15,6 ίντσες· αδιάβροχο ύφασμα.'],
            ['ssd', 'Nimbus Swift 1 TB external SSD', 'Εξωτερικός SSD Nimbus Swift 1 TB', 'computing', '99', '58', false, ['silver'], ['usb'], 'nimbus', 'aluminum', null, '1 TB storage, USB 3.2 Gen 2 up to 1050 MB/s; USB-C cable included.', 'Αποθήκευση 1 TB, USB 3.2 Gen 2 έως 1050 MB/s· περιλαμβάνεται καλώδιο USB-C.'],
            ['webcam', 'Aster Focus 2K webcam', 'Κάμερα υπολογιστή Aster Focus 2K', 'computing', '65', '125', false, ['black'], ['usb'], 'aster', 'plastic', null, '2560 × 1440 at 30 fps with privacy shutter; UVC compatible without additional drivers.', '2560 × 1440 στα 30 fps με κάλυμμα ιδιωτικότητας· συμβατή με UVC χωρίς πρόσθετους οδηγούς.'],
            ['charger', 'Aster GaN 65 W USB-C charger', 'Φορτιστής USB-C Aster GaN 65 W', 'computing', '39', '110', false, ['black'], ['usb'], 'aster', 'plastic', null, 'Two USB-C ports with USB Power Delivery, 65 W total output and EU plug.', 'Δύο θύρες USB-C με USB Power Delivery, συνολική ισχύ 65 W και ευρωπαϊκό βύσμα.'],
        ];
    }
}
