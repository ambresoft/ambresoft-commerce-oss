<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Three local sample hero banners (Greek and English texts) in a sample group. Backgrounds are the bundled CC0 demo
 * photos in database/demo/images (see CREDITS.md); nothing is fetched. Explicit opt-in, never in production; tracked by
 * stable keys so reruns create nothing and keep administrator edits.
 */
final class BannerDemoSeeder
{
    public function __construct(private BannerAdministration $banners) {}

    /** @return array{groups: int, banners: int} Records created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Banner demo seeding is restricted to local and testing environments.');
        }
        $created = ['groups' => 0, 'banners' => 0];
        // An existing group with the sample name is reused instead of failing on the unique name.
        $group = $this->record('group.hero', 'banner_groups', $created, 'groups', function () use ($actor): string {
            $existing = DB::table('banner_groups')->whereRaw('lower(name) = ?', ['homepage hero (sample)'])->value('id');

            return $existing !== null ? (string) $existing : $this->banners->createGroup($actor, 'Homepage hero (sample)');
        });

        $this->record('banner.hero1', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, $this->hero1($group), $this->artwork('hero-laptops')));
        $this->record('banner.hero2', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, $this->hero2($group), $this->artwork('hero-audio')));
        $this->record('banner.hero3', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, $this->hero3($group), $this->artwork('hero-phones')));
        $this->promotions($actor, $created);
        $this->blackFriday($actor, $created);

        return $created;
    }

    /**
     * The Black Friday landing page's hero: black and gold, text on the left. Tracked as banners.promo.blackfriday.
     *
     * @param  array<string, int>  $created
     */
    private function blackFriday(string $actor, array &$created): void
    {
        $group = $this->record('group.blackfriday', 'banner_groups', $created, 'groups', function () use ($actor): string {
            $existing = DB::table('banner_groups')->whereRaw('lower(name) = ?', ['black friday (sample)'])->value('id');

            return $existing !== null ? (string) $existing : $this->banners->createGroup($actor, 'Black Friday (sample)');
        });
        $this->record('promo.blackfriday', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, [
            ...$this->base($group, 0, 'dark'),
            'names' => $this->texts('Black Friday hero (sample)', 'Black Friday hero (δείγμα)'),
            // The landing page's main heading.
            'title_weight' => 'heavy', 'title_tag' => 'h1', 'button_style' => 'standard', 'url' => '/offers',
            'layouts' => $this->layouts('#f5c451', '#111111'),
            'contents' => $this->contents(
                ['<span class="banner-title-above" style="color: #f5c451">Up to 40% off</span>Black Friday<br><i>Real deals. No tricks.</i>', "Every discount checked against the lowest price of the last 30 days.\nUntil Cyber Monday, while stocks last.", 'Shop the deals'],
                ['<span class="banner-title-above" style="color: #f5c451">Έως 40% έκπτωση</span>Black Friday<br><i>Αληθινές προσφορές.</i>', "Κάθε έκπτωση ελέγχεται με τη χαμηλότερη τιμή των τελευταίων 30 ημερών.\nΜέχρι τη Cyber Monday, έως εξαντλήσεως.", 'Δείτε τις προσφορές'],
            ),
        ], $this->artwork('hero-blackfriday')));
    }

    /**
     * The homepage promotions grid: six side banners (wide product cards) and one tall centre banner, each with its own
     * phone artwork. Tracked as banners.promo.{name} so the homepage can place them.
     *
     * @param  array<string, int>  $created
     */
    private function promotions(string $actor, array &$created): void
    {
        $group = $this->record('group.promotions', 'banner_groups', $created, 'groups', function () use ($actor): string {
            $existing = DB::table('banner_groups')->whereRaw('lower(name) = ?', ['homepage promotions (sample)'])->value('id');

            return $existing !== null ? (string) $existing : $this->banners->createGroup($actor, 'Homepage promotions (sample)');
        });
        // name, scheme, link, button colour and text colour, English then Greek: eyebrow, title, description, button.
        $promos = [
            ['trail', 'dark', '/wearables', '#6ee7b7', '#064e3b', ['Orbit', 'Trail GPS', 'Maps, altitude and 14 days of battery.', 'Shop now'], ['Orbit', 'Trail GPS', 'Χάρτες, υψόμετρο και 14 ημέρες μπαταρία.', 'Δείτε το']],
            ['buds', 'dark', '/audio/earbuds', '#5eead4', '#042f2e', ['Volta', 'Buds Pro', 'Noise cancelling that fits in a pocket.', 'Shop now'], ['Volta', 'Buds Pro', 'Ακύρωση θορύβου που χωράει στην τσέπη.', 'Δείτε τα']],
            ['reader', 'light', '/tablets/e-readers', '#059669', '#ffffff', ['Lumo', 'Read Paper 7', 'A glare-free screen for weeks of reading.', 'Shop now'], ['Lumo', 'Read Paper 7', 'Οθόνη χωρίς αντανακλάσεις για εβδομάδες διαβάσματος.', 'Δείτε το']],
            ['watch', 'dark', '/wearables/smartwatches', '#6ee7b7', '#064e3b', ['Orbit Watch 3', 'Made to move.', 'Health, fitness and messages on your wrist.', 'Shop smartwatches'], ['Orbit Watch 3', 'Φτιαγμένο για κίνηση.', 'Υγεία, γυμναστική και μηνύματα στον καρπό σας.', 'Δείτε τα smartwatches']],
            ['charger', 'light', '/accessories', '#16a34a', '#ffffff', ['Kova', '30W USB-C', 'Charge a phone to 50% in 30 minutes.', 'Shop now'], ['Kova', '30W USB-C', 'Φόρτιση κινητού στο 50% σε 30 λεπτά.', 'Δείτε το']],
            ['backpack', 'dark', '/accessories/laptop-bags', '#6ee7b7', '#064e3b', ['Kova', 'Commuter pack', 'A padded home for a 16" laptop.', 'Shop now'], ['Kova', 'Commuter pack', 'Προστατευμένη θέση για laptop 16".', 'Δείτε το']],
            ['band', 'light', '/wearables/fitness-trackers', '#4d7c0f', '#ffffff', ['Orbit', 'Band 5', 'Steps, sleep and heart rate for a week.', 'Shop now'], ['Orbit', 'Band 5', 'Βήματα, ύπνος και παλμοί για μια εβδομάδα.', 'Δείτε το']],
        ];
        foreach ($promos as $position => [$name, $scheme, $url, $button, $buttonText, $english, $greek]) {
            $this->record('promo.'.$name, 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, [
                ...$this->base($group, $position, $scheme),
                'names' => $this->texts('Promotion – '.$english[1].' (sample)', 'Προώθηση – '.$greek[1].' (δείγμα)'),
                'title_weight' => 'heavy', 'title_tag' => 'div', 'button_style' => 'standard', 'url' => $url,
                'layouts' => $this->promotionLayouts($name === 'watch', $button, $buttonText),
                'contents' => $this->contents(
                    ['<span class="banner-title-above">'.$english[0].'</span>'.$english[1], $english[2], $english[3]],
                    ['<span class="banner-title-above">'.$greek[0].'</span>'.$greek[1], $greek[2], $greek[3]],
                ),
            ], $this->artwork('promo-'.$name)));
        }
    }

    /** @return array<string, mixed> New laptops: deep green artwork, white text on the left. */
    private function hero1(string $group): array
    {
        return [
            ...$this->base($group, 0, 'dark'),
            'names' => $this->texts('Hero 1 – New laptops (sample)', 'Hero 1 – Νέα laptops (δείγμα)'),
            'title_weight' => 'bold', 'title_tag' => 'h2', 'button_style' => 'standard', 'url' => '/laptops',
            'layouts' => $this->layouts('#6ee7b7', '#064e3b'),
            'contents' => $this->contents(
                ['<span class="banner-title-above">Just landed</span>Nimbus Air 13<br><i>1.2 kg. 18 hours.</i>', "Featherweight aluminium, silent and fanless.\nUp to 15% off this week only.", 'Shop laptops'],
                ['<span class="banner-title-above">Μόλις ήρθε</span>Nimbus Air 13<br><i>1,2 κιλά. 18 ώρες.</i>', "Πανάλαφρο αλουμίνιο, αθόρυβο χωρίς ανεμιστήρα.\nΈως 15% έκπτωση μόνο αυτή την εβδομάδα.", 'Δείτε τα laptops'],
            ),
        ];
    }

    /** @return array<string, mixed> Audio week: light mint artwork, dark text on the left. */
    private function hero2(string $group): array
    {
        return [
            ...$this->base($group, 1, 'light'),
            'names' => $this->texts('Hero 2 – Audio week (sample)', 'Hero 2 – Εβδομάδα ήχου (δείγμα)'),
            'title_weight' => 'heavy', 'title_tag' => 'div', 'button_style' => 'standard', 'url' => '/audio',
            'layouts' => $this->layouts('#14532d', '#ffffff'),
            'contents' => $this->contents(
                ['<span class="banner-title-above">Audio week</span>Hear only<br><span style="color: #15803d">what matters</span>', 'Noise-cancelling headphones and earbuds from €99.', 'Shop audio'],
                ['<span class="banner-title-above">Εβδομάδα ήχου</span>Ακούστε μόνο<br><span style="color: #15803d">ό,τι μετράει</span>', 'Ακουστικά και earbuds με ακύρωση θορύβου από 99€.', 'Δείτε τον ήχο'],
            ),
        ];
    }

    /** @return array<string, mixed> New phone: indigo artwork, white text on the left. */
    private function hero3(string $group): array
    {
        return [
            ...$this->base($group, 2, 'dark'),
            'names' => $this->texts('Hero 3 – Aster X5 Pro (sample)', 'Hero 3 – Aster X5 Pro (δείγμα)'),
            'title_weight' => 'bold', 'title_tag' => 'div', 'button_style' => 'standard', 'url' => '/phones',
            'layouts' => $this->layouts('#5eead4', '#042f2e'),
            'contents' => $this->contents(
                ['<span class="banner-title-above">New</span>Aster X5 Pro<br><i>Zoom closer. Shoot brighter.</i>', 'Triple camera with 5× zoom and a 120 Hz display.', 'Shop phones'],
                ['<span class="banner-title-above">Νέο</span>Aster X5 Pro<br><i>Πιο κοντά. Πιο φωτεινά.</i>', 'Τριπλή κάμερα με 5× zoom και οθόνη 120 Hz.', 'Δείτε τα κινητά'],
            ),
        ];
    }

    /**
     * Desktop: text on the left over the artwork's open side. Phone: its own portrait artwork, text centred above the picture.
     *
     * @return array<string, array<string, mixed>>
     */
    private function layouts(string $button, string $buttonText): array
    {
        return [
            'pc' => [...$this->layout(), 'halign' => 'left', 'paddings' => '56px 7%', 'title_size' => 50, 'description_size' => 18,
                'button_enabled' => true, 'button_color' => $button, 'button_text_color' => $buttonText],
            'tablet' => [],
            'phone' => ['valign' => 'top', 'halign' => 'center', 'paddings' => '36px 24px', 'full_width' => true, 'title_size' => 30, 'description_size' => 15],
        ];
    }

    /**
     * Side banners: text on the left over the artwork's open side; the tall centre one: centred under the picture.
     *
     * @return array<string, array<string, mixed>>
     */
    private function promotionLayouts(bool $tall, string $button, string $buttonText): array
    {
        return [
            'pc' => [...$this->layout(), 'valign' => $tall ? 'bottom' : 'center', 'halign' => $tall ? 'center' : 'left', 'paddings' => $tall ? '40px 6% 80px 6%' : '40px 48% 40px 6%',
                'title_size' => $tall ? 64 : 60, 'description_size' => $tall ? 30 : 28, 'button_enabled' => true, 'button_color' => $button, 'button_text_color' => $buttonText],
            'tablet' => [],
            'phone' => ['valign' => 'top', 'halign' => 'center', 'paddings' => '32px 24px', 'full_width' => true, 'title_size' => 30, 'description_size' => 15],
        ];
    }

    /** @return array<string, mixed> */
    private function base(string $group, int $position, string $scheme): array
    {
        return [
            'group_id' => $group, 'position' => $position, 'status' => 'active', 'created_on' => now()->toDateString(),
            'color_scheme' => $scheme, 'object_type' => 'image', 'products_template' => 'grid', 'products_columns' => 4, 'products_rows' => 1,
            'object_valign' => 'center', 'object_halign' => 'center', 'background_halign' => 'center',
            'new_window' => false, 'tablet_custom' => false, 'phone_custom' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function layout(): array
    {
        return ['valign' => 'center', 'halign' => 'center', 'paddings' => '10px 20px 20px 20px', 'full_width' => false, 'title_size' => 22, 'title_shadow' => false,
            'description_size' => 14, 'background_type' => 'image', 'background_size' => 'cover', 'background_valign' => 'center', 'button_enabled' => false,
            'mask_type' => 'none', 'mask_position' => 'banner', 'mask_opacity' => 50, 'mask_direction' => 'auto'];
    }

    /**
     * Greek texts where Greek is active, English for every other active language.
     *
     * @param  array{string, string, string}  $english  title, description, button
     * @param  array{string, string, string}  $greek
     * @return array<string, array<string, array<string, string>>>
     */
    private function contents(array $english, array $greek): array
    {
        $pc = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            [$title, $description, $button] = $code === 'el' ? $greek : $english;
            $pc[$code] = ['title' => $title, 'description' => $description, 'button_text' => $button];
        }

        // Phones repeat the texts: their own content row is what carries the portrait artwork.
        return ['pc' => $pc, 'phone' => $pc];
    }

    /** @return array<string, string> */
    private function texts(string $english, string $greek): array
    {
        $texts = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            $texts[$code] = $code === 'el' ? $greek : $english;
        }

        return $texts;
    }

    /**
     * The bundled banner artwork as background for every active language: the wide image on desktop, the portrait one on phones.
     *
     * @return array<string, array<string, array<string, UploadedFile>>>
     */
    private function artwork(string $name): array
    {
        $files = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            foreach (['pc' => $name, 'phone' => $name.'-phone'] as $device => $file) {
                $path = tempnam(sys_get_temp_dir(), 'banner') ?: throw new RuntimeException('Unable to create a temporary file.');
                copy(database_path('demo/images/'.$file.'.jpg'), $path);
                $files[$device][$code]['background_image'] = new UploadedFile($path, $file.'.jpg', 'image/jpeg', null, true);
            }
        }

        return $files;
    }

    /** @param array<string, int> $created */
    private function record(string $key, string $table, array &$created, string $counter, callable $create): string
    {
        $key = 'banners.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== $table || ! DB::table($table)->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo record was removed: '.$key.'. No data was changed.');
            }

            return (string) $existing->entity_id;
        }
        $id = $create();
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => $table, 'entity_id' => $id]);
        $created[$counter]++;

        return $id;
    }
}
