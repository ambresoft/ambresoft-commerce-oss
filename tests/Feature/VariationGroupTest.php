<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Command\VariationGroupData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Domain\Service\FeatureRules;
use App\Catalog\Infrastructure\FeatureImpactRequired;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductFeatures;
use App\Catalog\Infrastructure\ProductQueries;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Catalog\Infrastructure\VariationGroupIntegrity;
use App\Catalog\Infrastructure\VariationImpactRequired;
use App\Catalog\Infrastructure\VariationJoinOutcome;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Application\Command\ImageUpload;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\CategoryListing;
use App\Storefront\Infrastructure\VariationListings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class VariationGroupTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=';

    private UserRecord $actor;

    private FeatureAdministration $features;

    private ProductAdministration $products;

    private VariationGroupAdministration $groups;

    private CategoryRecord $root;

    private CategoryRecord $leaf;

    private CategoryRecord $other;

    private string $color;

    private string $size;

    private string $material;

    /** @var array<string, string> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->features = app(FeatureAdministration::class);
        $this->products = app(ProductAdministration::class);
        $this->groups = app(VariationGroupAdministration::class);
        $this->root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->leaf = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $this->root->id]);
        $this->other = CategoryRecord::create(['position' => 2, 'status' => 'active']);
        $this->color = $this->feature('Color');
        $this->size = $this->feature('Size');
        $this->material = $this->feature('Material', 'standard');
        foreach (['Red' => $this->color, 'White' => $this->color, 'Blue' => $this->color, '40' => $this->size, '43' => $this->size, 'Cotton' => $this->material, 'Leather' => $this->material] as $label => $feature) {
            $this->o[$label] = $this->option($feature, $label);
        }
    }

    private function actor(): string
    {
        return (string) $this->actor->id;
    }

    /** @param list<string> $categories */
    private function feature(string $name, string $purpose = 'variations', string $type = 'single', array $categories = []): string
    {
        return $this->features->save($this->actor(), null, new SaveFeatureData(['type' => $type, 'purpose' => $purpose, 'required' => false, 'filterable' => false, 'comparison' => false, 'categories' => $categories, 'translations' => ['en' => ['name' => $name]]]));
    }

    private function option(string $feature, string $label): string
    {
        return $this->features->option($this->actor(), $feature, null, new SaveFeatureData(['feature_version' => $this->featureData($feature)['version'], 'action' => 'save', 'translations' => ['en' => ['label' => $label]]]));
    }

    /** @return array<string, mixed> */
    private function featureData(string $id): array
    {
        $f = app(FeatureQueries::class)->definitions([$id])[0];

        return ['version' => $f['version'], 'type' => $f['type'], 'purpose' => $f['purpose'], 'variation_style' => $f['variation_style'], 'group_id' => $f['group_id'], 'required' => $f['required'], 'filterable' => $f['filterable'], 'comparison' => $f['comparison'], 'unit' => $f['unit'], 'categories' => $f['categories'], 'translations' => (array) $f['translations']];
    }

    /** @param array<string, mixed> $changes */
    private function editFeature(string $id, array $changes): void
    {
        $this->features->save($this->actor(), $id, new SaveFeatureData(array_replace($this->featureData($id), $changes)));
    }

    /** @param array<string, string> $choices feature => option */
    private function product(array $choices, string $name = 'Jordan', string $status = 'disabled', string $type = 'physical', ?CategoryRecord $category = null): ProductRecord
    {
        $id = $this->products->draft($this->actor());
        $features = [];
        foreach ($choices as $feature => $option) {
            $features[$feature] = ['version' => 0, 'options' => [$option]];
        }
        $this->products->save($this->actor(), $id, new SaveProductData(1, ['sku' => 'SKU-'.$id, 'price' => '10', 'type' => $type, 'status' => $status, 'category_id' => (string) ($category ?? $this->leaf)->id, 'translations' => ['en' => ['name' => $name.' '.$id]], 'features' => $features, 'feature_catalog_version' => app(FeatureQueries::class)->revision()]));

        return ProductRecord::findOrFail($id);
    }

    /** @param array<string, mixed> $changes */
    private function saveProduct(ProductRecord $p, array $changes): void
    {
        $data = app(ProductQueries::class)->editor($p->fresh());
        $data['translations'] = (array) $data['translations'];
        $this->products->save($this->actor(), (string) $p->id, new SaveProductData($data['version'], array_replace($data, $changes)));
    }

    /** @param array<string, string> $choices */
    private function setValues(ProductRecord $p, array $choices): void
    {
        $values = app(ProductFeatures::class)->values((string) $p->id);
        $features = [];
        foreach ($choices as $feature => $option) {
            $features[$feature] = ['version' => $values[$feature]['version'] ?? 0, 'options' => [$option]];
        }
        $this->saveProduct($p, ['features' => $features, 'feature_catalog_version' => app(FeatureQueries::class)->revision()]);
    }

    /** @param list<string> $features */
    private function group(ProductRecord $p, array $features, ?string $name = null): string
    {
        return $this->groups->create($this->actor(), new VariationGroupData(['product_id' => $p->id, 'product_version' => $p->fresh()->lock_version, 'features' => $features, 'name' => $name]));
    }

    private function version(string $group): int
    {
        return (int) DB::table('variation_groups')->where('id', $group)->value('lock_version');
    }

    /** @param list<ProductRecord> $products */
    private function add(string $group, array $products): void
    {
        $this->groups->add($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'products' => array_map(fn (ProductRecord $p): array => ['id' => $p->id, 'version' => $p->fresh()->lock_version], $products)]));
    }

    /** @return list<string> */
    private function members(string $group): array
    {
        return DB::table('variation_group_members')->where('group_id', $group)->orderBy('product_id')->pluck('product_id')->map(strval(...))->all();
    }

    /** @param callable(): void $operation
     * @return array<string, list<string>>
     */
    private function invalid(callable $operation): array
    {
        try {
            $operation();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('Expected a validation failure');
    }

    private function flat(array $errors): string
    {
        return implode(' | ', array_merge(...array_values($errors)));
    }

    public function test_grouped_color_summary_uses_saved_swatches_and_selected_member(): void
    {
        $locale = app(ProductQueries::class)->defaultId();
        $this->root->translations()->create(['locale_id' => $locale, 'name' => 'Tech', 'slug' => 'tech']);
        $this->leaf->translations()->create(['locale_id' => $locale, 'name' => 'Laptops', 'slug' => 'laptops']);
        $red = $this->product([$this->color => $this->o['Red']], 'Red', 'active');
        $blue = $this->product([$this->color => $this->o['Blue']], 'Blue', 'active');
        DB::table('product_translations')->where('product_id', $red->id)->update(['slug' => 'red-laptop']);
        DB::table('product_translations')->where('product_id', $blue->id)->update(['slug' => 'blue-laptop']);
        $group = $this->group($red, [$this->color]);
        $this->add($group, [$blue]);
        DB::table('features')->where('id', $this->color)->update(['variation_style' => 'color']);
        DB::table('feature_options')->where('id', $this->o['Red'])->update(['swatch_color' => '#FF0000']);
        DB::table('variation_group_features')->where('group_id', $group)->update(['show_on_card' => true]);
        self::assertArrayNotHasKey('swatches', app(VariationListings::class)->cardFeatures([$group], 'en', null)[$group][0]);
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'color_swatches' => true]));
        $summary = app(VariationListings::class)->cardFeatures([$group], 'en', null, [(string) $red->id]);
        self::assertSame(['label' => 'Red', 'color' => '#FF0000', 'selected' => true], $summary[$group][0]['swatches'][0]);
        self::assertSame(['label' => 'Blue', 'color' => null, 'selected' => false], $summary[$group][0]['swatches'][1]);
        DB::table('products')->where('id', $red->id)->update(['quantity' => 10]);
        DB::table('products')->where('id', $blue->id)->update(['quantity' => 0]);
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'clickable_colors' => true]));
        $linked = app(VariationListings::class)->cardFeatures([$group], 'en', null)[$group][0]['swatches'];
        self::assertNotEmpty($linked[0]['url']);
        self::assertStringContainsString('red', $linked[0]['url']);
        self::assertArrayNotHasKey('url', $linked[1]);
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'clickable_colors' => false]));
        self::assertArrayNotHasKey('url', app(VariationListings::class)->cardFeatures([$group], 'en', null)[$group][0]['swatches'][0]);

    }

    public function test_group_filters_use_real_members_and_card_settings_are_independent(): void
    {
        $cheap = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['40']], 'Cheap', 'active');
        $other = $this->product([$this->color => $this->o['Blue'], $this->size => $this->o['43']], 'Other', 'active');
        $third = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['43']], 'Third', 'active');
        $group = $this->group($cheap, [$this->color, $this->size]);
        $this->add($group, [$other, $third]);
        DB::table('features')->whereIn('id', [$this->color, $this->size])->update(['filterable' => true]);
        DB::table('products')->whereIn('id', [$cheap->id, $other->id])->update(['quantity' => 10]);
        DB::table('products')->where('id', $cheap->id)->update(['price' => '5']);
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'single_listing' => true, 'view_product' => true, 'card_features' => [$this->size]]));
        $listings = app(CategoryListing::class);
        $context = ['locale' => 'en', 'customer' => null];
        $prepare = fn ($query) => $listings->prepare([], ['category' => (string) $this->leaf->id], $query, $context);
        $all = $prepare([]);
        self::assertSame(1, $all['facets']['total']);
        $colors = collect($all['facets']['groups'])->firstWhere('key', 'f'.$this->color)['options'];
        self::assertCount(2, $colors);
        self::assertSame([1, 1], array_column($colors, 'count'));
        $filtered = $prepare(['f'.$this->color => $this->o['Blue']]);
        self::assertSame(1, $filtered['facets']['total']);
        self::assertSame(0, $prepare(['f'.$this->color => $this->o['Blue'], 'f'.$this->size => $this->o['40']])['facets']['total']);
        $settings = ['products_per_page' => 24, 'default_sort' => 'recommended', 'show_variations' => false, 'show_brand' => false, 'show_category' => false, 'products_per_row' => 3, 'show_count' => true, 'show_sorting' => true];
        $card = $listings->results($filtered, $settings, $context)['items'][0];
        self::assertSame((string) $cheap->id, $card['id']);
        self::assertTrue($card['view_product']);
        self::assertSame(['40', '43'], $card['variation_features'][0]['values']);
        self::assertCount(1, $card['variation_features']);
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'card_labels' => [$this->size => ['en' => 'Short size']]]));
        $this->groups->reorder($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'features' => [$this->size, $this->color]]));
        $custom = $listings->results($filtered, $settings, $context)['items'][0];
        self::assertSame('Short size', $custom['variation_features'][0]['name']);
        self::assertNotSame('Short size', app(FeatureQueries::class)->definitions([$this->size])[0]['name']);
        foreach ([$this->material => ['en' => 'Wrong feature'], $this->size => ['zz' => 'Inactive locale']] as $feature => $labels) {
            try {
                $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'card_labels' => [$feature => $labels]]));
                self::fail('Invalid card label keys accepted');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }

        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'card_labels' => [$this->size => ['en' => '   ']]]));
        self::assertSame($card['variation_features'][0]['name'], $listings->results($filtered, $settings, $context)['items'][0]['variation_features'][0]['name']);

        $carousel = app(BlockContent::class)->productCards([(string) $cheap->id], $context, app(PublicCatalog::class)->products(), 'carousels')['cards'][(string) $cheap->id];
        self::assertFalse($carousel['view_product']);
        self::assertSame([], $carousel['variation_features']);
        DB::table('products')->where('id', $other->id)->update(['status' => 'disabled']);
        self::assertSame(0, $prepare(['f'.$this->color => $this->o['Blue']])['facets']['total']);
        try {
            $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'card_features' => [$this->material]]));
            self::fail('Non-defining card feature accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('card_features', $e->errors());
        }
    }

    public function test_single_listing_is_opt_in_and_selects_only_a_purchasable_member(): void
    {
        $cheap = $this->product([$this->color => $this->o['Red']], 'Cheap', 'active');
        $other = $this->product([$this->color => $this->o['Blue']], 'Other', 'active');
        $group = $this->group($cheap, [$this->color]);
        $this->add($group, [$other]);
        DB::table('products')->where('id', $cheap->id)->update(['price' => '5', 'quantity' => 10]);
        DB::table('products')->where('id', $other->id)->update(['price' => '20', 'quantity' => 10]);
        $catalog = app(PublicCatalog::class);
        self::assertFalse((bool) DB::table('variation_groups')->where('id', $group)->value('single_listing'));
        self::assertCount(2, $catalog->listed()->whereIn('products.id', [$cheap->id, $other->id])->get());
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'single_listing' => true]));
        self::assertSame([(string) $cheap->id], $catalog->listed()->whereIn('products.id', [$cheap->id, $other->id])->pluck('products.id')->map(strval(...))->all());
        self::assertCount(2, $catalog->listed(null, 'carousels')->whereIn('products.id', [$cheap->id, $other->id])->get());
        self::assertCount(2, $catalog->listed(null, 'search')->whereIn('products.id', [$cheap->id, $other->id])->get());
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'single_search' => true]));
        self::assertCount(1, $catalog->listed(null, 'search')->whereIn('products.id', [$cheap->id, $other->id])->get());
        $names = app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l): array => [$l->code => ['name' => 'Public group name']])->all();
        $this->groups->rename($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'name' => 'Private internal label', 'translations' => $names]));
        $content = app(BlockContent::class);
        $context = ['locale' => 'en', 'customer' => null];
        $plain = $content->productCards([(string) $cheap->id], $context, $catalog->products(), 'carousels')['cards'][(string) $cheap->id];
        $grouped = $content->productCards([(string) $cheap->id], $context, $catalog->products(), 'lists')['cards'][(string) $cheap->id];
        self::assertFalse($plain['price_from']);
        self::assertSame('Public group name', $grouped['name']);
        self::assertTrue($grouped['price_from']);
        $explicit = $content->productCards([(string) $cheap->id], $context, $catalog->products(), 'carousels', [(string) $cheap->id])['cards'][(string) $cheap->id];
        self::assertSame($grouped['name'], $explicit['name']);
        self::assertTrue($explicit['price_from']);
        $defaults = app(StorefrontRegistry::class)->type('products')->definition()['defaults'];
        $block = ['settings' => [...$defaults, 'source' => 'manual'], 'template' => 'product_carousel', 'translations' => []];
        $blocks = $content->products([
            'exact' => [...$block, 'selections' => ['items' => [(string) $cheap->id]]],
            'group' => [...$block, 'selections' => ['variation_groups' => [$group]]],
        ], $context);
        self::assertSame($plain['name'], $blocks['exact']['items'][0]['name']);
        self::assertFalse($blocks['exact']['items'][0]['price_from']);
        self::assertSame($grouped['name'], $blocks['group']['items'][0]['name']);
        self::assertTrue($blocks['group']['items'][0]['price_from']);
        // A manual selection stays exact even while this group's automatic carousel grouping is on.
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'single_carousel' => true]));
        self::assertTrue($defaults['preserve_manual_products']);
        $blocks = $content->products([
            'exact' => [...$block, 'selections' => ['items' => [(string) $other->id]]],
            'grouped' => [...$block, 'settings' => [...$block['settings'], 'preserve_manual_products' => false], 'selections' => ['items' => [(string) $other->id]]],
            'group' => [...$block, 'selections' => ['variation_groups' => [$group]]],
            'automatic' => [...$block, 'settings' => [...$block['settings'], 'source' => 'newest'], 'selections' => []],
        ], $context);
        $exact = $blocks['exact']['items'][0];
        self::assertSame((string) $other->id, $exact['id']);
        self::assertSame($other->sku, $exact['sku']);
        self::assertSame('Other '.$other->id, $exact['name']);
        self::assertSame('20.00', $exact['price']);
        self::assertFalse($exact['price_from']);
        foreach (['grouped', 'group', 'automatic'] as $key) {
            self::assertSame((string) $cheap->id, $blocks[$key]['items'][0]['id']);
            self::assertSame('Public group name', $blocks[$key]['items'][0]['name']);
            self::assertTrue($blocks[$key]['items'][0]['price_from']);
        }

        DB::table('products')->where('id', $cheap->id)->update(['available_from' => now()->addYear()->toDateString()]);
        self::assertSame([(string) $other->id], $catalog->listed()->whereIn('products.id', [$cheap->id, $other->id])->pluck('products.id')->map(strval(...))->all());
        self::assertCount(2, $catalog->products()->whereIn('products.id', [$cheap->id, $other->id])->get());
        $this->groups->listing($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'single_listing' => false]));
        self::assertCount(2, $catalog->listed()->whereIn('products.id', [$cheap->id, $other->id])->get());
    }

    public function test_purpose_model_supports_three_exclusive_purposes_and_selector_styles(): void
    {
        self::assertSame(['standard', 'landing_pages', 'variations'], FeatureRules::PURPOSES);
        self::assertSame('variations', DB::table('features')->where('id', $this->color)->value('purpose'));
        // Text buttons is the default style; all four styles are stored on the shared feature.
        self::assertSame('text', DB::table('features')->where('id', $this->color)->value('variation_style'));
        foreach (['color', 'image', 'dropdown', 'text'] as $style) {
            $this->editFeature($this->color, ['variation_style' => $style]);
            self::assertSame($style, DB::table('features')->where('id', $this->color)->value('variation_style'));
        }
        self::assertArrayHasKey('variation_style', $this->invalid(fn () => $this->editFeature($this->color, ['variation_style' => 'radio'])));
        // Only Single choice can use the variation purpose: validation and the database both refuse it.
        foreach (['multiple', 'text', 'number', 'boolean'] as $type) {
            self::assertArrayHasKey('purpose', $this->invalid(fn () => $this->feature('Bad', 'variations', $type)));
        }
        $multiple = $this->feature('Tags', 'standard', 'multiple');
        $this->expectException(QueryException::class);
        DB::table('features')->where('id', $multiple)->update(['purpose' => 'variations']);
    }

    public function test_swatch_colors_normalize_and_swatch_images_stay_separate_from_landing_images(): void
    {
        self::assertSame('#FF0000', FeatureRules::swatchColor('#f00'));
        self::assertSame('#A1B2C3', FeatureRules::swatchColor(' #a1b2c3 '));
        foreach (['red', '#ff00', '#gg0000', 'rgb(1,2,3)', 'url(x)', '#ff0000;'] as $bad) {
            self::assertNull(FeatureRules::swatchColor($bad));
        }
        $red = $this->o['Red'];
        $save = fn (array $fields, ?UploadedFile $swatch = null) => $this->features->option($this->actor(), $this->color, $red, new SaveFeatureData(['feature_version' => $this->featureData($this->color)['version'], 'version' => DB::table('feature_options')->where('id', $red)->value('lock_version'), 'action' => 'save', 'translations' => [], ...$fields], null, $swatch === null ? null : new ImageUpload($swatch->getPathname())));
        $save(['swatch_color' => '#f00']);
        self::assertSame('#FF0000', DB::table('feature_options')->where('id', $red)->value('swatch_color'));
        self::assertArrayHasKey('swatch_color', $this->invalid(fn () => $save(['swatch_color' => 'red'])));
        self::assertSame('#FF0000', DB::table('feature_options')->where('id', $red)->value('swatch_color'));
        $save([], UploadedFile::fake()->createWithContent('red.png', base64_decode(self::PNG)));
        $swatch = DB::table('feature_options')->where('id', $red)->value('swatch_image_media_id');
        self::assertNotNull($swatch);
        self::assertNull(DB::table('feature_options')->where('id', $red)->value('landing_image_media_id'));
        // Swatch fields belong to the variation purpose; standard options cannot submit them.
        self::assertArrayHasKey('swatch_color', $this->invalid(fn () => $this->features->option($this->actor(), $this->material, $this->o['Cotton'], new SaveFeatureData(['feature_version' => $this->featureData($this->material)['version'], 'version' => 1, 'action' => 'save', 'translations' => [], 'swatch_color' => '#000000']))));
        // Changing purpose and style never clears dormant swatch data.
        $this->editFeature($this->color, ['variation_style' => 'dropdown']);
        $this->editFeature($this->color, ['purpose' => 'standard']);
        $this->editFeature($this->color, ['purpose' => 'variations', 'variation_style' => 'color']);
        $row = DB::table('feature_options')->where('id', $red)->first();
        self::assertSame('#FF0000', $row->swatch_color);
        self::assertSame($swatch, $row->swatch_image_media_id);
        self::assertSame('color', DB::table('features')->where('id', $this->color)->value('variation_style'));
        $definition = app(FeatureQueries::class)->definitions([$this->color])[0];
        self::assertSame('#FF0000', collect($definition['options'])->firstWhere('id', $red)['swatch_color']);
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $swatch));
        $this->expectException(QueryException::class);
        DB::table('feature_options')->where('id', $red)->update(['swatch_color' => '#ff0000']);
    }

    public function test_landing_data_survives_switching_to_variations_and_purpose_changes_are_blocked_while_used(): void
    {
        // Feature landing pages exist only while brand landing pages are off (A61 data, General settings).
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $brand = $this->feature('Brand', 'landing_pages');
        $nike = $this->features->option($this->actor(), $brand, null, new SaveFeatureData(['feature_version' => $this->featureData($brand)['version'], 'action' => 'save', 'translations' => ['en' => ['label' => 'Nike', 'seo_title' => 'Nike shoes']]]));
        try {
            $this->editFeature($brand, ['purpose' => 'variations']);
            self::fail('Hiding landing metadata needs confirmation');
        } catch (FeatureImpactRequired $e) {
            $this->editFeature($brand, ['purpose' => 'variations', 'confirmed' => true, 'impact_token' => $e->token]);
        }
        self::assertSame('Nike shoes', DB::table('feature_option_translations')->where('option_id', $nike)->value('seo_title'));
        $group = $this->group($this->product([$this->color => $this->o['Red']]), [$this->color]);
        $errors = $this->invalid(fn () => $this->editFeature($this->color, ['purpose' => 'standard']));
        self::assertStringContainsString('#'.$group, $this->flat($errors));
        self::assertSame('variations', DB::table('features')->where('id', $this->color)->value('purpose'));
        // Style is shared: changing it keeps membership, signatures and option order.
        $before = DB::table('variation_group_members')->get()->toArray();
        $this->editFeature($this->color, ['variation_style' => 'color']);
        self::assertEquals($before, DB::table('variation_group_members')->get()->toArray());
    }

    public function test_group_creation_is_guided_atomic_and_leaves_failed_products_untouched(): void
    {
        $shoe = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['40']], 'Nike Jordan');
        $before = $shoe->fresh()->toArray();
        // No defining feature, an ineligible (standard) feature, or a missing value: nothing is created.
        self::assertArrayHasKey('features', $this->invalid(fn () => $this->group($shoe, [])));
        self::assertNotEmpty($this->invalid(fn () => $this->group($shoe, [$this->material])));
        $bare = $this->product([$this->color => $this->o['Red']]);
        self::assertStringContainsString('Size', $this->flat($this->invalid(fn () => $this->group($bare, [$this->color, $this->size]))));
        $draft = ProductRecord::findOrFail($this->products->draft($this->actor()));
        self::assertNotEmpty($this->invalid(fn () => $this->group($draft, [$this->color])));
        self::assertSame(0, DB::table('variation_groups')->count());
        self::assertEquals($before, $shoe->fresh()->toArray());
        // Success: one member, ordered features, generated name from the default-language product name.
        $group = $this->group($shoe, [$this->size, $this->color]);
        self::assertSame('Nike Jordan '.$shoe->id, DB::table('variation_groups')->where('id', $group)->value('name'));
        self::assertSame([(string) $shoe->id], $this->members($group));
        self::assertSame([$this->size, $this->color], app(VariationGroupIntegrity::class)->featureIds($group));
        self::assertSame($this->color.':'.$this->o['Red'].','.$this->size.':'.$this->o['40'], DB::table('variation_group_members')->value('signature'));
        self::assertEquals($before, $shoe->fresh()->toArray());
        $this->assertDatabaseHas('variation_group_audits', ['group_id' => $group, 'action' => 'created']);
        // Already grouped products cannot start another group; names are editable and need not be unique.
        self::assertArrayHasKey('product_id', $this->invalid(fn () => $this->group($shoe, [$this->color])));
        $second = $this->group($this->product([$this->color => $this->o['White']]), [$this->color], 'Nike Jordan '.$shoe->id);
        $this->groups->rename($this->actor(), $second, new VariationGroupData(['version' => $this->version($second), 'name' => 'Renamed']));
        self::assertSame('Renamed', DB::table('variation_groups')->where('id', $second)->value('name'));
        $shoe->translations()->update(['name' => 'Changed product name']);
        self::assertSame('Nike Jordan '.$shoe->id, DB::table('variation_groups')->where('id', $group)->value('name'));
    }

    public function test_membership_requires_matching_category_type_values_and_unique_stable_combinations(): void
    {
        $red40 = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['40'], $this->material => $this->o['Cotton']]);
        $group = $this->group($red40, [$this->color, $this->size]);
        // Partial matrix: White/43 joins without Red/43 or White/40 existing; non-defining values may differ.
        $white43 = $this->product([$this->color => $this->o['White'], $this->size => $this->o['43'], $this->material => $this->o['Leather']]);
        $this->add($group, [$white43]);
        self::assertSame([(string) $red40->id, (string) $white43->id], $this->members($group));
        self::assertSame(2, DB::table('variation_group_members')->count());
        // Duplicate combination (also with a Hidden/Disabled member) is refused, naming the conflict.
        $this->products->lifecycle($this->actor(), (string) $red40->id, $red40->fresh()->lock_version, 'hidden');
        $copy = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['40']]);
        $errors = $this->invalid(fn () => $this->add($group, [$copy]));
        self::assertStringContainsString('SKU-'.$red40->id, $this->flat($errors));
        self::assertSame(2, count($this->members($group)));
        // Wrong category, wrong type and a missing value are explained.
        self::assertStringContainsString('category', strtolower($this->flat($this->invalid(fn () => $this->add($group, [$this->product([$this->color => $this->o['Blue'], $this->size => $this->o['40']], category: $this->other)])))));
        self::assertStringContainsString('Physical', $this->flat($this->invalid(fn () => $this->add($group, [$this->product([$this->color => $this->o['Blue'], $this->size => $this->o['40']], type: 'digital')]))));
        // The message names the group's type so the administrator knows what is expected.
        self::assertStringContainsString('Size', $this->flat($this->invalid(fn () => $this->add($group, [$this->product([$this->color => $this->o['Blue']])]))));
        // The same combination may exist in a different group that reuses the same features.
        $elsewhere = $this->group($copy, [$this->color, $this->size]);
        self::assertSame([(string) $copy->id], $this->members($elsewhere));
        // Renaming an option label never changes stable identity.
        $signature = DB::table('variation_group_members')->where('product_id', $red40->id)->value('signature');
        $this->features->option($this->actor(), $this->color, $this->o['Red'], new SaveFeatureData(['feature_version' => $this->featureData($this->color)['version'], 'version' => DB::table('feature_options')->where('id', $this->o['Red'])->value('lock_version'), 'action' => 'save', 'translations' => ['en' => ['label' => 'Crimson']]]));
        self::assertSame($signature, DB::table('variation_group_members')->where('product_id', $red40->id)->value('signature'));
    }

    public function test_bulk_addition_is_all_or_nothing_and_never_adds_members_of_other_groups(): void
    {
        $group = $this->group($this->product([$this->color => $this->o['Red']]), [$this->color]);
        $white = $this->product([$this->color => $this->o['White']]);
        $alsoWhite = $this->product([$this->color => $this->o['White']]);
        $errors = $this->invalid(fn () => $this->add($group, [$white, $alsoWhite]));
        self::assertNotEmpty($errors);
        self::assertSame(1, count($this->members($group)));
        $other = $this->group($this->product([$this->color => $this->o['Blue']]), [$this->color]);
        $blue = DB::table('variation_group_members')->where('group_id', $other)->value('product_id');
        $errors = $this->invalid(fn () => $this->add($group, [$white, ProductRecord::findOrFail($blue)]));
        self::assertArrayHasKey('products.'.$blue, $errors);
        self::assertSame(1, count($this->members($group)));
        $this->add($group, [$white]);
        self::assertSame(2, count($this->members($group)));
        $this->assertDatabaseHas('variation_group_audits', ['group_id' => $group, 'action' => 'member_added']);
    }

    public function test_product_edits_activation_and_status_respect_group_rules(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $white = $this->product([$this->color => $this->o['White']]);
        $group = $this->group($red, [$this->color]);
        $this->add($group, [$white]);
        // Changing a defining value to a reserved combination is refused; the original stays.
        self::assertStringContainsString('SKU-'.$red->id, $this->flat($this->invalid(fn () => $this->setValues($white, [$this->color => $this->o['Red']]))));
        self::assertSame($this->color.':'.$this->o['White'], DB::table('variation_group_members')->where('product_id', $white->id)->value('signature'));
        // A unique new value is accepted and the stored signature follows it.
        $this->setValues($white, [$this->color => $this->o['Blue']]);
        self::assertSame($this->color.':'.$this->o['Blue'], DB::table('variation_group_members')->where('product_id', $white->id)->value('signature'));
        // Type and category are locked while grouped; incomplete edits are refused without detaching.
        self::assertArrayHasKey('type', $this->invalid(fn () => $this->saveProduct($white, ['type' => 'digital'])));
        self::assertNotEmpty($this->invalid(fn () => $this->saveProduct($white, ['category_id' => (string) $this->other->id])));
        self::assertArrayHasKey('sku', $this->invalid(fn () => $this->saveProduct($white, ['sku' => ''])));
        self::assertSame((string) $group, app(VariationGroupIntegrity::class)->groupOf((string) $white->id));
        // Status changes never detach; activation revalidates integrity.
        foreach (['hidden', 'disabled', 'active'] as $status) {
            $this->products->lifecycle($this->actor(), (string) $white->id, $white->fresh()->lock_version, $status);
            self::assertSame((string) $group, app(VariationGroupIntegrity::class)->groupOf((string) $white->id));
        }
        $this->products->lifecycle($this->actor(), (string) $white->id, $white->fresh()->lock_version, 'disabled');
        DB::table('product_feature_choices')->where('option_id', $this->o['Blue'])->update(['option_id' => $this->o['Red']]);
        self::assertNotEmpty($this->invalid(fn () => $this->products->lifecycle($this->actor(), (string) $white->id, $white->fresh()->lock_version, 'active')));
        self::assertSame('disabled', $white->fresh()->status);
    }

    public function test_defining_feature_changes_need_preview_confirmation_and_reordering_is_presentation_only(): void
    {
        $red40 = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['40']]);
        $red43 = $this->product([$this->color => $this->o['Red'], $this->size => $this->o['43']]);
        $group = $this->group($red40, [$this->color, $this->size]);
        $this->add($group, [$red43]);
        // Removing Size would make both members Red: the collision blocks the change and nothing changes.
        $errors = $this->invalid(fn () => $this->groups->configure($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'features' => [$this->color]])));
        self::assertArrayHasKey('features', $errors);
        self::assertSame([$this->color, $this->size], app(VariationGroupIntegrity::class)->featureIds($group));
        // Reordering keeps signatures; a stale version is rejected.
        $signatures = DB::table('variation_group_members')->orderBy('product_id')->pluck('signature')->all();
        $version = $this->version($group);
        $this->groups->reorder($this->actor(), $group, new VariationGroupData(['version' => $version, 'features' => [$this->size, $this->color]]));
        self::assertSame([$this->size, $this->color], app(VariationGroupIntegrity::class)->featureIds($group));
        self::assertSame($signatures, DB::table('variation_group_members')->orderBy('product_id')->pluck('signature')->all());
        self::assertArrayHasKey('version', $this->invalid(fn () => $this->groups->reorder($this->actor(), $group, new VariationGroupData(['version' => $version, 'features' => [$this->color, $this->size]]))));
        // A valid change previews first, then applies only with the matching confirmation.
        $solo = $this->group($this->product([$this->color => $this->o['Blue'], $this->size => $this->o['40']]), [$this->color]);
        try {
            $this->groups->configure($this->actor(), $solo, new VariationGroupData(['version' => $this->version($solo), 'features' => [$this->color, $this->size]]));
            self::fail('Expected an impact preview');
        } catch (VariationImpactRequired $e) {
            self::assertSame(1, $e->impact['members']);
            self::assertSame(['Size'], $e->impact['added']);
            self::assertSame([$this->color], app(VariationGroupIntegrity::class)->featureIds($solo));
            $this->groups->configure($this->actor(), $solo, new VariationGroupData(['version' => $this->version($solo), 'features' => [$this->color, $this->size], 'confirmed' => true, 'impact_token' => $e->token]));
        }
        self::assertSame([$this->color, $this->size], app(VariationGroupIntegrity::class)->featureIds($solo));
    }

    public function test_create_variation_reuses_duplication_and_joins_only_after_a_complete_valid_save(): void
    {
        $source = $this->product([$this->color => $this->o['Red'], $this->material => $this->o['Cotton']], 'Nike');
        $group = $this->group($source, [$this->color]);
        $create = fn (string $option) => $this->groups->createVariation($this->actor(), $group, new VariationGroupData(['version' => $this->version($group), 'source' => $source->id, 'source_version' => $source->fresh()->lock_version, 'options' => [$this->color => $option]]));
        $count = ProductRecord::count();
        self::assertStringContainsString('SKU-'.$source->id, $this->flat($this->invalid(fn () => $create($this->o['Red']))));
        self::assertSame($count, ProductRecord::count());
        $copy = ProductRecord::findOrFail($create($this->o['White']));
        // Phase 4 duplication rules: Disabled, unassigned SKU, copied compatible values; standalone with a target.
        self::assertSame('disabled', $copy->status);
        self::assertNull($copy->sku);
        self::assertNull(app(VariationGroupIntegrity::class)->groupOf((string) $copy->id));
        $this->assertDatabaseHas('variation_group_intents', ['product_id' => $copy->id, 'group_id' => $group]);
        self::assertSame([$this->o['White']], DB::table('product_feature_assignments as a')->join('product_feature_choices as c', 'c.assignment_id', '=', 'a.id')->where('a.product_id', $copy->id)->where('a.feature_id', $this->color)->pluck('c.option_id')->map(strval(...))->all());
        self::assertSame([$this->o['Cotton']], DB::table('product_feature_assignments as a')->join('product_feature_choices as c', 'c.assignment_id', '=', 'a.id')->where('a.product_id', $copy->id)->where('a.feature_id', $this->material)->pluck('c.option_id')->map(strval(...))->all());
        // Standard Duplicate of a member is always standalone and never targets a group.
        $plain = $this->products->duplicate($this->actor(), (string) $source->id, $source->fresh()->lock_version);
        self::assertNull(app(VariationGroupIntegrity::class)->groupOf($plain));
        $this->assertDatabaseMissing('variation_group_intents', ['product_id' => $plain]);
        // Someone else takes White first: the complete save keeps the copy standalone and explains why.
        $this->add($group, [$this->product([$this->color => $this->o['White']])]);
        $this->saveProduct($copy, ['sku' => 'COPY-1']);
        self::assertNull(app(VariationGroupIntegrity::class)->groupOf((string) $copy->id));
        self::assertSame('pending', app(VariationJoinOutcome::class)->outcome['status']);
        self::assertSame('COPY-1', $copy->fresh()->sku);
        // Fixing the value lets the next complete save join the intended group.
        $this->setValues($copy, [$this->color => $this->o['Blue']]);
        self::assertSame((string) $group, app(VariationGroupIntegrity::class)->groupOf((string) $copy->id));
        $this->assertDatabaseMissing('variation_group_intents', ['product_id' => $copy->id]);
    }

    public function test_remove_transfer_disband_and_trash_preserve_products(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $white = $this->product([$this->color => $this->o['White']]);
        $group = $this->group($red, [$this->color]);
        $this->add($group, [$white]);
        $snapshot = $white->fresh()->toArray();
        $this->groups->remove($this->actor(), $group, (string) $white->id, new VariationGroupData(['version' => $this->version($group), 'product_version' => $white->fresh()->lock_version]));
        self::assertEquals($snapshot, $white->fresh()->toArray());
        self::assertSame([(string) $red->id], $this->members($group));
        $this->assertDatabaseHas('product_audits', ['product_id' => $white->id, 'action' => 'variation_left']);
        // Transfer: a duplicate in the target is refused and the source membership stays.
        $target = $this->group($white, [$this->color]);
        $redCopy = $this->product([$this->color => $this->o['Red']]);
        $this->add($target, [$redCopy]);
        $errors = $this->invalid(fn () => $this->groups->transfer($this->actor(), $group, (string) $red->id, new VariationGroupData(['version' => $this->version($group), 'product_version' => $red->fresh()->lock_version, 'target' => $target, 'target_version' => $this->version($target)])));
        self::assertArrayHasKey('target', $errors);
        self::assertSame((string) $group, app(VariationGroupIntegrity::class)->groupOf((string) $red->id));
        // A valid transfer moves only membership and disbands the emptied source.
        $blue = $this->product([$this->color => $this->o['Blue']]);
        $this->add($group, [$blue]);
        $this->groups->remove($this->actor(), $group, (string) $red->id, new VariationGroupData(['version' => $this->version($group), 'product_version' => $red->fresh()->lock_version]));
        $blueSnapshot = $blue->fresh()->toArray();
        $this->groups->transfer($this->actor(), $group, (string) $blue->id, new VariationGroupData(['version' => $this->version($group), 'product_version' => $blue->fresh()->lock_version, 'target' => $target, 'target_version' => $this->version($target)]));
        self::assertEquals($blueSnapshot, $blue->fresh()->toArray());
        $this->assertDatabaseMissing('variation_groups', ['id' => $group]);
        $this->assertDatabaseHas('variation_group_audits', ['group_id' => $group, 'action' => 'auto_disbanded']);
        // Trash detaches (group stays), restore returns a standalone Disabled product.
        $this->products->lifecycle($this->actor(), (string) $blue->id, $blue->fresh()->lock_version, 'trash');
        self::assertNull(app(VariationGroupIntegrity::class)->groupOf((string) $blue->id));
        self::assertNotNull(ProductRecord::withTrashed()->find($blue->id));
        $this->products->lifecycle($this->actor(), (string) $blue->id, ProductRecord::withTrashed()->find($blue->id)->lock_version, 'restore');
        self::assertSame('disabled', $blue->fresh()->status);
        self::assertNull(app(VariationGroupIntegrity::class)->groupOf((string) $blue->id));
        // Disbanding keeps every member unchanged and standalone.
        $members = $this->members($target);
        $before = ProductRecord::whereIn('id', $members)->orderBy('id')->get()->toArray();
        $this->groups->disband($this->actor(), $target, new VariationGroupData(['version' => $this->version($target)]));
        $this->assertDatabaseMissing('variation_groups', ['id' => $target]);
        self::assertEquals($before, ProductRecord::whereIn('id', $members)->orderBy('id')->get()->toArray());
        self::assertSame(0, DB::table('variation_group_members')->count());
        self::assertSame(0, DB::table('variation_group_features')->count());
        // Trashing the last member disbands its group in the same transaction.
        $solo = $this->product([$this->color => $this->o['Red']]);
        $last = $this->group($solo, [$this->color]);
        $this->products->lifecycle($this->actor(), (string) $solo->id, $solo->fresh()->lock_version, 'trash');
        $this->assertDatabaseMissing('variation_groups', ['id' => $last]);
    }

    public function test_feature_and_option_lifecycle_cannot_invalidate_groups(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $group = $this->group($red, [$this->color]);
        $action = fn (string $action) => $this->features->action($this->actor(), $this->color, new SaveFeatureData(['version' => $this->featureData($this->color)['version'], 'action' => $action, 'confirmed' => true]));
        self::assertStringContainsString('#'.$group, $this->flat($this->invalid(fn () => $action('archive'))));
        self::assertSame('active', DB::table('features')->where('id', $this->color)->value('status'));
        $archiveOption = fn (string $option) => $this->features->option($this->actor(), $this->color, $option, new SaveFeatureData(['feature_version' => $this->featureData($this->color)['version'], 'version' => DB::table('feature_options')->where('id', $option)->value('lock_version'), 'action' => 'archive', 'translations' => []]));
        // A used defining option cannot be archived; an unused one can.
        try {
            $archiveOption($this->o['Red']);
            self::fail('Expected confirmation or refusal');
        } catch (FeatureImpactRequired $e) {
            self::assertNotEmpty($this->invalid(fn () => $this->features->option($this->actor(), $this->color, $this->o['Red'], new SaveFeatureData(['feature_version' => $this->featureData($this->color)['version'], 'version' => DB::table('feature_options')->where('id', $this->o['Red'])->value('lock_version'), 'action' => 'archive', 'translations' => [], 'confirmed' => true, 'impact_token' => $e->token]))));
        }
        self::assertSame('active', DB::table('feature_options')->where('id', $this->o['Red'])->value('status'));
        $archiveOption($this->o['Blue']);
        self::assertSame('archived', DB::table('feature_options')->where('id', $this->o['Blue'])->value('status'));
        // Restricting the feature away from the members' category is refused, naming the group.
        self::assertStringContainsString('#'.$group, $this->flat($this->invalid(fn () => $this->editFeature($this->color, ['categories' => [(string) $this->other->id]]))));
        self::assertSame([], $this->featureData($this->color)['categories']);
        // Blanking the default-language name would make the feature incomplete.
        self::assertNotEmpty($this->invalid(fn () => $this->editFeature($this->color, ['translations' => ['en' => ['name' => '']]])));
    }

    public function test_category_hierarchy_status_and_deletion_respect_groups(): void
    {
        // Single active language, as in CategoryTest, so untranslated fixture categories can be saved.
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $red = $this->product([$this->color => $this->o['Red']]);
        $group = $this->group($red, [$this->color]);
        $handler = app(ManageCategoriesHandler::class);
        try {
            $handler->delete($this->actor(), (string) $this->leaf->id, $this->leaf->fresh()->lock_version);
            self::fail('Deletion must be refused');
        } catch (InvalidCategoryChange $e) {
            self::assertStringContainsString('#'.$group, $e->getMessage());
        }
        self::assertSame((string) $this->leaf->id, (string) $red->fresh()->category_id);
        self::assertNotNull(CategoryRecord::find($this->leaf->id));
        $leaf = $this->leaf->fresh();
        $save = fn (array $changes) => $handler->save($this->actor(), (string) $leaf->id, new SaveCategoryData(...array_replace(['parentId' => (string) $leaf->parent_id, 'position' => $leaf->position, 'status' => $leaf->status, 'version' => $leaf->lock_version, 'translations' => []], $changes)));
        // Disabling the shared leaf would make members incomplete: refused, product untouched.
        try {
            $save(['status' => 'disabled']);
            self::fail('Disabling the group category must be refused');
        } catch (InvalidCategoryChange $e) {
            self::assertStringContainsString('#'.$group, $e->getMessage());
        }
        self::assertSame('active', $this->leaf->fresh()->status);
        self::assertSame('disabled', $red->fresh()->status);
        self::assertSame((string) $group, app(VariationGroupIntegrity::class)->groupOf((string) $red->id));
    }

    public function test_category_move_that_makes_a_defining_feature_inapplicable_is_refused(): void
    {
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $restricted = $this->feature('Shoe size', 'variations', 'single', [(string) $this->root->id]);
        $this->option($restricted, '38');
        $p = $this->product([$restricted => DB::table('feature_options')->where('feature_id', $restricted)->value('id')]);
        $group = $this->group($p, [$restricted]);
        $leaf = $this->leaf->fresh();
        try {
            app(ManageCategoriesHandler::class)->save($this->actor(), (string) $leaf->id, new SaveCategoryData(null, 5, 'active', [], $leaf->lock_version));
            self::fail('Moving out from under the restricted ancestor must be refused');
        } catch (InvalidCategoryChange $e) {
            self::assertStringContainsString('#'.$group, $e->getMessage());
        }
        self::assertSame((string) $this->root->id, (string) $this->leaf->fresh()->parent_id);
    }

    public function test_admin_screens_list_filter_and_enforce_permissions(): void
    {
        $red = $this->product([$this->color => $this->o['Red']], 'Air Max', 'active');
        $white = $this->product([$this->color => $this->o['White']], 'Air Max');
        $group = $this->group($red, [$this->color], 'Air Max family');
        $this->add($group, [$white]);
        $this->group($this->product([$this->color => $this->o['Blue']], 'Solo'), [$this->color], 'Solo group');
        $this->get('/admin/variation-groups?search=SKU-'.$white->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/VariationGroups/Index')->has('groups.data', 1)->where('groups.data.0.name', 'Air Max family')->where('groups.data.0.members', 2)->where('groups.data.0.features', ['Color']));
        $this->get('/admin/variation-groups?members=single')->assertInertia(fn (Assert $p) => $p->has('groups.data', 1)->where('groups.data.0.name', 'Solo group'));
        $this->get('/admin/variation-groups?type=digital')->assertInertia(fn (Assert $p) => $p->has('groups.data', 0));
        $this->get('/admin/variation-groups?feature_id='.$this->color.'&category_id='.$this->leaf->id.'&sort=name&direction=asc')->assertInertia(fn (Assert $p) => $p->has('groups.data', 2)->where('groups.data.0.name', 'Air Max family'));
        $this->get('/admin/variation-groups/'.$group)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/VariationGroups/Show')
            ->where('group.readiness.total', 2)->where('group.readiness.available', 1)->where('group.readiness.eligible', false)
            ->has('group.members', 2)->where('group.members.0.values.'.$this->color.'.label', 'Red'));
        // The product editor's Variations tab lists the group's members, marking the current product.
        $this->get('/admin/products/'.$white->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('variation.group.id', (string) $group)->has('variation.group.members', 2)
            ->where('variation.group.features.0.name', 'Color')
            ->where('variation.group.members', fn ($members) => collect($members)->firstWhere('current', true)['id'] === (string) $white->id));
        $this->get('/admin/products?variation=grouped')->assertInertia(fn (Assert $p) => $p->has('products.data', 3)->where('products.data.0.variation_group.id', fn ($id) => $id !== null));
        $this->get('/admin/products?variation=standalone')->assertInertia(fn (Assert $p) => $p->has('products.data', 0));
        // Viewing needs products.view; every mutation needs products.update (create variation: products.create too).
        $viewer = $this->staff(['admin.access', 'products.view']);
        // auth.session ends a session whose user changes, so each identity starts a fresh session.
        $this->flushSession();
        $this->actingAs($viewer)->get('/admin/variation-groups/'.$group)->assertOk()->assertInertia(fn (Assert $p) => $p->where('candidates', []));
        $this->actingAs($viewer)->post('/admin/variation-groups/'.$group.'/name', ['version' => $this->version($group), 'name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete('/admin/variation-groups/'.$group, ['version' => $this->version($group)])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']))->get('/admin/variation-groups')->assertForbidden();
        self::assertSame('Air Max family', DB::table('variation_groups')->where('id', $group)->value('name'));
        // HTTP flow: stale disband is rejected with a validation error.
        $this->flushSession();
        $this->actingAs($this->actor)->from('/admin/variation-groups/'.$group)->delete('/admin/variation-groups/'.$group, ['version' => 999])->assertSessionHasErrors('version');
    }

    public function test_database_constraints_back_up_membership_and_combination_rules(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $white = $this->product([$this->color => $this->o['White']]);
        $group = $this->group($red, [$this->color]);
        $signature = DB::table('variation_group_members')->value('signature');
        foreach ([
            fn () => DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $white->id, 'signature' => $signature]),
            fn () => DB::table('variation_group_members')->insert(['group_id' => $this->group($white, [$this->color]), 'product_id' => $red->id, 'signature' => $this->color.':'.$this->o['Blue']]),
            fn () => DB::table('variation_group_members')->where('product_id', $red->id)->update(['signature' => 'red']),
            fn () => DB::table('features')->where('id', $this->color)->update(['variation_style' => 'radio']),
        ] as $violation) {
            DB::beginTransaction();
            try {
                $violation();
                self::fail('Expected a constraint violation');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
        // Group configuration is owned by the group; physical product deletion removes its membership only.
        DB::table('variation_groups')->where('id', $group)->delete();
        self::assertSame(0, DB::table('variation_group_members')->where('group_id', $group)->count());
        self::assertNotNull(ProductRecord::find($red->id));
    }

    public function test_audit_trail_and_central_ledger_record_relationship_changes(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $white = $this->product([$this->color => $this->o['White']]);
        $group = $this->group($red, [$this->color]);
        $this->add($group, [$white]);
        $this->groups->disband($this->actor(), $group, new VariationGroupData(['version' => $this->version($group)]));
        $actions = DB::table('variation_group_audits')->where('group_id', $group)->orderBy('id')->pluck('action')->all();
        self::assertSame(['created', 'member_added', 'disbanded'], $actions);
        $context = json_decode(DB::table('variation_group_audits')->where('group_id', $group)->where('action', 'disbanded')->value('context'), true);
        self::assertEqualsCanonicalizing([(string) $red->id, (string) $white->id], $context['members']);
        self::assertSame(2, DB::table('product_audits')->where('action', 'variation_left')->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'variation_group_member')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'variation_group')->exists());
    }

    public function test_migration_rolls_back_and_reapplies(): void
    {
        $red = $this->product([$this->color => $this->o['Red']]);
        $this->group($red, [$this->color]);
        (require database_path('migrations/2026_11_25_000300_add_clickable_variation_colors.php'))->down();
        (require database_path('migrations/2026_11_25_000200_add_variation_color_card_style.php'))->down();
        (require database_path('migrations/2026_11_25_000100_add_variation_card_feature_labels.php'))->down();
        (require database_path('migrations/2026_11_24_000200_index_variation_search_groups.php'))->down();
        (require database_path('migrations/2026_11_24_000100_add_variation_card_settings.php'))->down();
        (require database_path('migrations/2026_11_23_000200_add_variation_storefront_names.php'))->down();
        (require database_path('migrations/2026_11_23_000100_split_variation_listing_surfaces.php'))->down();
        $migration = require database_path('migrations/2026_10_11_001500_create_variation_groups.php');
        $migration->down();
        self::assertSame('standard', DB::table('features')->where('id', $this->color)->value('purpose'));
        self::assertFalse(Schema::hasTable('variation_groups'));
        self::assertNotNull(ProductRecord::find($red->id));
        $migration->up();
        self::assertTrue(Schema::hasTable('variation_group_members'));
        self::assertSame('text', DB::table('features')->where('id', $this->color)->value('variation_style'));
    }
}
