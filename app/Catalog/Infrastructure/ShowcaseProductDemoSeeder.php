<?php

namespace App\Catalog\Infrastructure;

use App\Shared\Infrastructure\DemoPicture;
use Illuminate\Support\Facades\DB;

/**
 * The demo store's flagship: a "Nimbus Pro 16" family that shows a complete product page — three variants chosen by
 * colour, memory and storage, specification features, a Black Friday sticker, a timed sale, several photos, accessories
 * bought together, reviews — plus three storefront coupons. Fictional brand; idempotent by SKU.
 */
final class ShowcaseProductDemoSeeder
{
    private const SKU = 'TECH-NP16-';

    /** @var list<array{string,string,string,string,string,string,int}> sku suffix, colour, memory, storage, price, list price, stock */
    private const VARIANTS = [
        ['BLK-32-1T', 'black', '32', '1tb', '2499', '2699', 12],
        ['BLK-16-512', 'black', '16', '512', '1999', '2199', 20],
        ['SLV-16-512', 'silver', '16', '512', '1999', '2199', 18],
    ];

    /** @return array{products:int,coupons:int}|null null when the showcase already exists */
    public function run(): ?array
    {
        if (DB::table('products')->where('sku', 'like', self::SKU.'%')->exists()) {
            return null;
        }
        $category = DB::table('category_translations')->where('slug', 'business-laptops')->value('category_id');
        if ($category === null) {
            return null;
        }

        return DB::transaction(function () use ($category): array {
            $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code')->all();
            $features = $this->features($locales, (int) $category);
            $pictures = app(DemoPicture::class);
            $ids = [];
            foreach (self::VARIANTS as $n => [$suffix, $colour, $memory, $storage, $price, $list, $stock]) {
                $id = (int) DB::table('products')->insertGetId(['sku' => self::SKU.$suffix, 'status' => 'active', 'price' => $price, 'list_price' => $list, 'category_id' => $category, 'quantity' => $stock,
                    'weight' => '2.10', 'mpn' => 'NP16-'.$suffix, 'returnable' => true, 'free_shipping' => true, 'brand_id' => DB::table('brands')->where('code', 'nimbus')->value('id'), 'created_at' => now()->subMinutes(5 + $n), 'updated_at' => now()]);
                $ids[$suffix] = $id;
                $colourName = ['black' => ['Space black', 'Διαστημικό μαύρο'], 'silver' => ['Silver', 'Ασημί']][$colour];
                $storageName = ['512' => '512 GB', '1tb' => '1 TB'][$storage];
                foreach ($locales as $code => $locale) {
                    $el = $code === 'el';
                    $name = 'Nimbus Pro 16 ('.$colourName[$el ? 1 : 0].', '.$memory.' GB, '.$storageName.')';
                    DB::table('product_translations')->insert(['product_id' => $id, 'locale_id' => $locale, 'name' => $name, 'slug' => 'nimbus-pro-16-'.strtolower($suffix),
                        'short_description' => $el ? '<p>Οθόνη 16" Liquid Retina 120 Hz, επεξεργαστής Nimbus N3, έως 22 ώρες μπαταρία.</p>' : '<p>16" 120 Hz display, Nimbus N3 processor and up to 22 hours of battery.</p>',
                        'description' => $el ? $this->descriptionEl() : $this->descriptionEn(), 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($n === 0 ? ['nimbus-pro-14', 'nimbus-air-15', 'category-laptops', 'nimbus-air-13'] : ['nimbus-pro-14', 'nimbus-air-15'] as $position => $photo) {
                    DB::table('product_images')->insert(['product_id' => $id, 'media_file_id' => $pictures->photo($photo), 'position' => $position + 1, 'is_primary' => $position === 0]);
                }
                $this->assign($id, $features, ['colour' => $colour, 'memory' => $memory, 'storage' => $storage, 'chip' => $memory === '32' ? 'max' : 'pro'], ['display' => '16.2', 'weight' => '2.1', 'battery' => '22'], ['backlit' => true, 'fingerprint' => true]);
            }
            $this->variations($ids, $features);
            $main = $ids['BLK-32-1T'];
            $this->sticker($main, $locales);
            // A timed Black Friday sale on the flagship: the product page shows its countdown.
            $sale = DB::table('product_sales')->insertGetId(['product_id' => $main, 'kind' => 'percentage', 'amount' => 10, 'minimum_quantity' => 1, 'priority' => 1, 'is_enabled' => true,
                'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(12)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($locales as $code => $locale) {
                DB::table('product_sale_translations')->insert(['sale_id' => $sale, 'locale_id' => $locale, 'title' => $code === 'el' ? 'Black Friday' : 'Black Friday']);
            }
            $companions = DB::table('product_translations')->whereIn('slug', ['kova-commuter-backpack', 'kova-ergonomic-mouse', 'kova-charging-hub'])->distinct()->pluck('product_id')->values();
            foreach ($companions as $position => $companion) {
                DB::table('product_companions')->insert(['product_id' => $main, 'companion_id' => $companion, 'position' => $position + 1]);
            }

            return ['products' => count($ids), 'coupons' => $this->coupons()];
        });
    }

    /** Approved sample reviews on the flagship, written by the demo customers (run after they exist). */
    public function reviews(): int
    {
        $product = DB::table('products')->where('sku', self::SKU.'BLK-32-1T')->value('id');
        $customers = DB::table('customers')->where('status', 'active')->orderBy('id')->limit(4)->pluck('id');
        if ($product === null || $customers->isEmpty() || DB::table('product_reviews')->where('product_id', $product)->exists()) {
            return 0;
        }
        $samples = [[5, 'Fast, quiet and the screen is stunning', 'I edit video every day and it never slows down. Battery easily lasts a full working day.'],
            [5, 'Best laptop I have owned', 'Beautiful build, great keyboard and the speakers are surprisingly good.'],
            [4, 'Excellent, a bit heavy', 'Performance is fantastic. At 2.1 kg it is not the lightest to carry around.'],
            [5, 'Worth every euro', 'Arrived the next day, perfectly packed. The Black Friday price made it a no-brainer.']];
        foreach ($customers->values() as $n => $customer) {
            [$rating, $title, $body] = $samples[$n];
            DB::table('product_reviews')->insert(['product_id' => $product, 'customer_id' => $customer, 'rating' => $rating, 'title' => $title, 'body' => $body, 'status' => 'approved', 'verified_purchase' => true,
                'moderated_at' => now(), 'created_at' => now()->subDays(3 + $n * 4), 'updated_at' => now()]);
        }

        return $customers->count();
    }

    /**
     * The showcase's features: colour, memory and storage pick the variant; chip, display, weight, battery and two
     * yes/no specifications describe it. All apply to the product's category.
     *
     * @param  array<string,int>  $locales
     * @return array<string,array{id:int,type:string,options:array<int|string,int>}>
     */
    private function features(array $locales, int $category): array
    {
        $group = (int) DB::table('feature_groups')->insertGetId(['position' => 100]);
        foreach ($locales as $code => $locale) {
            DB::table('feature_group_translations')->insert(['group_id' => $group, 'locale_id' => $locale, 'name' => $code === 'el' ? 'Τεχνικά χαρακτηριστικά laptop' : 'Laptop specifications']);
        }
        $definitions = [
            'colour' => ['single', 'variations', 'color', null, ['Colour', 'Χρώμα'], ['black' => ['Space black', 'Διαστημικό μαύρο', '#1F2937'], 'silver' => ['Silver', 'Ασημί', '#D1D5DB']]],
            'memory' => ['single', 'variations', 'text', null, ['Memory', 'Μνήμη'], ['16' => ['16 GB', '16 GB', null], '32' => ['32 GB', '32 GB', null]]],
            'storage' => ['single', 'variations', 'text', null, ['Storage', 'Αποθηκευτικός χώρος'], ['512' => ['512 GB', '512 GB', null], '1tb' => ['1 TB', '1 TB', null]]],
            'chip' => ['single', 'standard', 'text', null, ['Processor', 'Επεξεργαστής'], ['pro' => ['Nimbus N3 Pro, 12 cores', 'Nimbus N3 Pro, 12 πυρήνες', null], 'max' => ['Nimbus N3 Max, 16 cores', 'Nimbus N3 Max, 16 πυρήνες', null]]],
            'display' => ['number', 'standard', 'text', '"', ['Screen size', 'Μέγεθος οθόνης'], []],
            'weight' => ['number', 'standard', 'text', 'kg', ['Weight', 'Βάρος'], []],
            'battery' => ['number', 'standard', 'text', 'h', ['Battery life', 'Διάρκεια μπαταρίας'], []],
            'backlit' => ['boolean', 'standard', 'text', null, ['Backlit keyboard', 'Φωτιζόμενο πληκτρολόγιο'], []],
            'fingerprint' => ['boolean', 'standard', 'text', null, ['Fingerprint reader', 'Αναγνώστης δακτυλικού αποτυπώματος'], []],
        ];
        $features = [];
        $position = 0;
        foreach ($definitions as $key => [$type, $purpose, $style, $unit, [$en, $el], $options]) {
            $id = (int) DB::table('features')->insertGetId(['code' => 'NP16-'.strtoupper($key), 'type' => $type, 'status' => 'active', 'group_id' => $group, 'position' => ++$position * 10, 'unit' => $unit,
                'required' => false, 'filterable' => in_array($key, ['colour', 'memory', 'storage', 'chip'], true), 'comparison' => true, 'purpose' => $purpose, 'variation_style' => $style, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($locales as $code => $locale) {
                DB::table('feature_translations')->insert(['feature_id' => $id, 'locale_id' => $locale, 'name' => $code === 'el' ? $el : $en]);
            }
            DB::table('feature_categories')->insert(['feature_id' => $id, 'category_id' => $category]);
            $ids = [];
            $n = 0;
            foreach ($options as $value => [$optionEn, $optionEl, $swatch]) {
                $option = (int) DB::table('feature_options')->insertGetId(['feature_id' => $id, 'status' => 'active', 'position' => ++$n * 10, 'swatch_color' => $swatch]);
                foreach ($locales as $code => $locale) {
                    DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $locale, 'label' => $code === 'el' ? $optionEl : $optionEn]);
                }
                $ids[(string) $value] = $option;
            }
            $features[$key] = ['id' => $id, 'type' => $type, 'options' => $ids];
        }

        return $features;
    }

    /**
     * @param  array<string,array{id:int,type:string,options:array<int|string,int>}>  $features
     * @param  array<string,string>  $choices
     * @param  array<string,string>  $numbers
     * @param  array<string,bool>  $flags
     */
    private function assign(int $product, array $features, array $choices, array $numbers, array $flags): void
    {
        foreach ($choices as $key => $value) {
            $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $product, 'feature_id' => $features[$key]['id'], 'type' => 'single', 'is_applicable' => true]);
            DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $features[$key]['id'], 'option_id' => $features[$key]['options'][$value], 'type' => 'single']);
        }
        foreach ($numbers as $key => $value) {
            DB::table('product_feature_assignments')->insert(['product_id' => $product, 'feature_id' => $features[$key]['id'], 'type' => 'number', 'is_applicable' => true, 'number_value' => $value]);
        }
        foreach ($flags as $key => $value) {
            DB::table('product_feature_assignments')->insert(['product_id' => $product, 'feature_id' => $features[$key]['id'], 'type' => 'boolean', 'is_applicable' => true, 'boolean_value' => $value]);
        }
    }

    /**
     * One variation group: the shopper switches colour, memory and storage on the product page.
     *
     * @param  array<string,int>  $products
     * @param  array<string,array{id:int,type:string,options:array<int|string,int>}>  $features
     */
    private function variations(array $products, array $features): void
    {
        $group = (int) DB::table('variation_groups')->insertGetId(['name' => 'Nimbus Pro 16', 'color_swatches' => true, 'clickable_colors' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['colour', 'memory', 'storage'] as $position => $key) {
            DB::table('variation_group_features')->insert(['group_id' => $group, 'feature_id' => $features[$key]['id'], 'position' => $position + 1, 'show_on_card' => true]);
        }
        foreach (self::VARIANTS as [$suffix, $colour, $memory, $storage]) {
            $combination = [$features['colour']['id'] => $features['colour']['options'][$colour], $features['memory']['id'] => $features['memory']['options'][$memory], $features['storage']['id'] => $features['storage']['options'][$storage]];
            /** @var array<string, string> $byFeature feature ID => option ID (PHP keeps numeric keys as integers) */
            $byFeature = array_map(strval(...), $combination);
            DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $products[$suffix], 'signature' => app(VariationGroupIntegrity::class)->signature($byFeature), 'joined_at' => now()]);
        }
    }

    /** @param array<string,int> $locales */
    private function sticker(int $product, array $locales): void
    {
        $sticker = DB::table('stickers')->insertGetId(['name' => 'Black Friday (demo)', 'type' => 'manual', 'style' => 'text', 'status' => 'active', 'placement' => 'image_top', 'show_card' => true, 'show_detail' => true,
            'background' => '#111111', 'color' => '#F5C451', 'border_color' => '#F5C451', 'border_width' => 2, 'radius' => 20, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($locales as $code => $locale) {
            DB::table('sticker_translations')->insert(['sticker_id' => $sticker, 'locale_id' => $locale, 'text' => 'Black Friday', 'short_text' => '']);
        }
        DB::table('product_stickers')->insert(['product_id' => $product, 'sticker_id' => $sticker]);
    }

    /** Three public sample coupons for the homepage coupon block. */
    private function coupons(): int
    {
        $samples = [['Welcome 10% (demo)', 'WELCOME10', 'percentage', '10', '0', false], ['€20 off over €200 (demo)', 'SAVE20', 'fixed', '20', '200', false], ['Free shipping (demo)', 'FREESHIP', 'percentage', '0', '30', true],
            ['Black Friday 15% over €100 (demo)', 'BLACKFRIDAY', 'percentage', '15', '100', false]];
        foreach ($samples as [$name, $code, $type, $amount, $minimum, $shipping]) {
            if (! DB::table('coupons')->where('code', $code)->exists()) {
                DB::table('coupons')->insert(['name' => $name, 'code' => $code, 'type' => $type, 'amount' => $amount, 'minimum' => $minimum, 'free_shipping' => $shipping, 'all_products' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return count($samples);
    }

    private function descriptionEn(): string
    {
        return '<p>The Nimbus Pro 16 is built for people who create: a bright 16.2" 120 Hz display, the Nimbus N3 processor and all-day battery life in a precision aluminium body.</p>'
            .'<h2>Performance that keeps up</h2><p>Edit 8K video, compile large projects and run demanding apps side by side. The advanced cooling stays quiet under load.</p>'
            .'<h2>A display made for detail</h2><ul><li>16.2" display, 3456 × 2234 pixels</li><li>Up to 1600 nits peak brightness</li><li>120 Hz adaptive refresh rate</li></ul>'
            .'<h2>Ready for work</h2><ul><li>Up to 22 hours of battery life</li><li>Backlit keyboard with fingerprint reader</li><li>Three USB-C ports, HDMI and an SD card slot</li></ul>';
    }

    private function descriptionEl(): string
    {
        return '<p>Το Nimbus Pro 16 είναι φτιαγμένο για όσους δημιουργούν: φωτεινή οθόνη 16,2" 120 Hz, επεξεργαστής Nimbus N3 και μπαταρία για όλη τη μέρα σε σώμα αλουμινίου.</p>'
            .'<h2>Επιδόσεις που ακολουθούν</h2><p>Επεξεργαστείτε βίντεο 8K, μεταγλωττίστε μεγάλα έργα και τρέξτε απαιτητικές εφαρμογές ταυτόχρονα. Η ψύξη μένει αθόρυβη.</p>'
            .'<h2>Οθόνη για κάθε λεπτομέρεια</h2><ul><li>Οθόνη 16,2", 3456 × 2234 pixels</li><li>Έως 1600 nits φωτεινότητα</li><li>Προσαρμοζόμενος ρυθμός ανανέωσης 120 Hz</li></ul>'
            .'<h2>Έτοιμο για δουλειά</h2><ul><li>Έως 22 ώρες μπαταρία</li><li>Φωτιζόμενο πληκτρολόγιο με αναγνώστη δακτυλικού αποτυπώματος</li><li>Τρεις θύρες USB-C, HDMI και υποδοχή κάρτας SD</li></ul>';
    }
}
