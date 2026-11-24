<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two local sample hero banners (Greek and English texts) in a sample group. Backgrounds are the bundled CC0 demo
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

        $this->record('banner.hero1', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, $this->hero1($group), $this->background('banner-devices')));
        $this->record('banner.hero2', 'banners', $created, 'banners', fn (): string => $this->banners->save($actor, null, null, $this->hero2($group), $this->background('banner-shipping')));

        return $created;
    }

    /** @return array<string, mixed> New devices: dark scheme, content on the left, gradient mask, phone overrides. */
    private function hero1(string $group): array
    {
        return [
            ...$this->base($group, 0, 'dark'),
            'names' => $this->texts('Hero 1 – New devices (sample)', 'Hero 1 – Νέες συσκευές (δείγμα)'),
            'title_weight' => 'bold', 'title_tag' => 'h2', 'button_style' => 'standard', 'url' => '/laptops',
            'phone_custom' => true,
            'layouts' => [
                'pc' => [...$this->layout(), 'halign' => 'left', 'paddings' => '40px 60px 40px 60px', 'title_size' => 46, 'description_size' => 18, 'title_shadow' => true,
                    'button_enabled' => true, 'button_color' => '#f5d7a1', 'button_text_color' => '#3b2412', 'mask_type' => 'gradient', 'mask_opacity' => 35],
                'tablet' => [],
                'phone' => ['halign' => 'center', 'paddings' => '24px', 'full_width' => true, 'title_size' => 30, 'description_size' => 15, 'mask_type' => 'semi', 'mask_opacity' => 45],
            ],
            'contents' => $this->contents(
                ['<span class="banner-title-above">Just landed</span>Nimbus Air laptops<br><i>with Aster phones &amp; tablets</i>', "All-day battery, featherweight design.\nUp to 15% off this week only.", 'Shop laptops'],
                ['<span class="banner-title-above">Μόλις ήρθαν</span>Laptops Nimbus Air<br><i>με κινητά &amp; tablets Aster</i>', "Μπαταρία για όλη τη μέρα, πανάλαφρος σχεδιασμός.\nΈως 15% έκπτωση μόνο αυτή την εβδομάδα.", 'Δείτε τα laptops'],
            ),
        ];
    }

    /** @return array<string, mixed> Free shipping: light scheme, centred content, coloured word in the title. */
    private function hero2(string $group): array
    {
        return [
            ...$this->base($group, 1, 'light'),
            'names' => $this->texts('Hero 2 – Free shipping (sample)', 'Hero 2 – Δωρεάν αποστολή (δείγμα)'),
            'title_weight' => 'heavy', 'title_tag' => 'div', 'button_style' => 'outline', 'url' => '/products',
            'layouts' => [
                'pc' => [...$this->layout(), 'halign' => 'center', 'full_width' => true, 'paddings' => '40px 80px', 'title_size' => 50, 'description_size' => 18,
                    'button_enabled' => true, 'button_color' => '#14532d'],
            ],
            'contents' => $this->contents(
                ['<span style="color: #15803d">Free</span> shipping over €50', 'Orders placed before 14:00 ship the same day. Free 30-day returns on every device.', 'Shop now'],
                ['<span style="color: #15803d">Δωρεάν</span> αποστολή άνω των 50€', 'Οι παραγγελίες έως τις 14:00 αποστέλλονται αυθημερόν. Δωρεάν επιστροφές 30 ημερών για κάθε συσκευή.', 'Αγορά τώρα'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function base(string $group, int $position, string $scheme): array
    {
        return [
            'group_id' => $group, 'position' => $position, 'status' => 'active', 'created_on' => now()->toDateString(),
            'color_scheme' => $scheme, 'object_type' => 'image', 'products_template' => 'grid', 'products_columns' => 4, 'products_rows' => 1,
            'object_valign' => 'center', 'object_halign' => 'center', 'background_halign' => 'center',
            'new_window' => false, 'tablet_custom' => false, 'phone_custom' => false,
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

        return ['pc' => $pc];
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
     * The same bundled demo photo as background for every active language.
     *
     * @return array<string, array<string, array<string, UploadedFile>>>
     */
    private function background(string $name): array
    {
        $files = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            $path = tempnam(sys_get_temp_dir(), 'banner') ?: throw new RuntimeException('Unable to create a temporary file.');
            copy(database_path('demo/images/'.$name.'.jpg'), $path);
            $files['pc'][$code]['background_image'] = new UploadedFile($path, $name.'.jpg', 'image/jpeg', null, true);
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
