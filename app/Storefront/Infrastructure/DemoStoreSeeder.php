<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CatalogDemoSeeder;
use App\Settings\Infrastructure\CompanySettings;
use App\Shared\Infrastructure\DemoPicture;
use App\Website\Infrastructure\MenuDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A ready storefront for demos: the electronics catalog, hand-built menus, and a full homepage wired to them. */
final class DemoStoreSeeder
{
    /** @return array{categories:int,products:int,menus:bool} */
    public function run(): array
    {
        $catalog = app(CatalogDemoSeeder::class);
        $created = $catalog->run();
        $categories = $created['ids'] ?? $catalog->ids();
        $menus = app(MenuDemoSeeder::class)->run($categories);
        if ($menus !== null) {
            $this->select('default', 'menu', ['main' => [$menus['main']], 'secondary' => [$menus['secondary']]], 'menu_id');
        }
        $this->showcaseBlocks($categories);
        // The directory of link groups at the bottom of the homepage.
        $links = app(MenuDemoSeeder::class)->links();
        if ($links !== null) {
            $this->directoryBlock($links);
        }
        $this->newsletterBlock();
        $this->company();
        $this->layoutTexts();
        $footer = app(MenuDemoSeeder::class)->footer();
        if ($footer !== null) {
            $this->footerBlock($footer);
        }
        // The starter homepage categories block shows the sample categories until the merchant picks others.
        $keys = array_keys(array_filter(CatalogDemoSeeder::CATEGORIES, fn (array $row): bool => $row[0] === null));
        $this->select('homepage', 'categories', ['items' => array_values(array_filter(array_map(fn ($k) => $categories[$k] ?? null, $keys)))], 'category_id', onlyIfEmpty: true);

        return ['categories' => $created['categories'] ?? 0, 'products' => $created['products'] ?? 0, 'menus' => $menus !== null || $links !== null || $footer !== null];
    }

    /** Sample page titles and meta texts for the built-in layouts (only filled in while the merchant has none). */
    private function layoutTexts(): void
    {
        $name = app(StoreBrand::class)->all()['name'];
        $samples = [
            'default' => [
                'en' => [$name.' – Technology and electronics online store', 'Shop smartphones, laptops, TVs, audio, gaming and smart home devices at great prices, with fast delivery and easy returns.', 'electronics, technology, smartphones, laptops, tv, audio, gaming, smart home'],
                'el' => [$name.' – Ηλεκτρονικό κατάστημα τεχνολογίας', 'Αγοράστε smartphones, laptops, τηλεοράσεις, ήχο, gaming και έξυπνες συσκευές σπιτιού σε τιμές-έκπληξη, με γρήγορη παράδοση και εύκολες επιστροφές.', 'ηλεκτρονικά, τεχνολογία, smartphones, laptops, τηλεοράσεις, ήχος, gaming, έξυπνο σπίτι'],
            ],
            'homepage' => [
                'en' => [$name.' – Latest smartphones, laptops, TVs and more', 'Discover new arrivals and top offers in phones, computers, TV and home cinema, audio, gaming and smart home. Order online with fast delivery.', 'new arrivals, offers, smartphones, laptops, televisions, headphones, consoles'],
                'el' => [$name.' – Νέα smartphones, laptops, τηλεοράσεις και πολλά ακόμη', 'Ανακαλύψτε νέες αφίξεις και προσφορές σε κινητά, υπολογιστές, τηλεοράσεις και home cinema, ήχο, gaming και έξυπνο σπίτι. Παραγγείλετε online με γρήγορη παράδοση.', 'νέες αφίξεις, προσφορές, smartphones, laptops, τηλεοράσεις, ακουστικά, κονσόλες'],
            ],
        ];
        foreach ($samples as $page => $locales) {
            $layout = DB::table('storefront_layouts')->where('page_type', $page)->value('id');
            foreach ($locales as $code => [$title, $description, $keywords]) {
                $locale = DB::table('locales')->where('code', $code)->value('id');
                if ($layout === null || $locale === null) {
                    continue;
                }
                $row = DB::table('storefront_layout_translations')->where('layout_id', $layout)->where('locale_id', $locale)->first();
                $fill = [];
                foreach (['page_title' => $title, 'meta_description' => $description, 'meta_keywords' => $keywords] as $column => $value) {
                    if (trim((string) ($row->$column ?? '')) === '') {
                        $fill[$column] = mb_substr($value, 0, $column === 'page_title' ? 200 : 500);
                    }
                }
                if ($fill !== []) {
                    DB::table('storefront_layout_translations')->updateOrInsert(['layout_id' => $layout, 'locale_id' => $locale], $fill);
                    DB::table('storefront_layouts')->where('id', $layout)->increment('settings_version');
                }
            }
        }
    }

    /** The homepage showcase under the starter blocks: weekly deals, a laptops carousel, customer favourites, brands and the blog.
     * @param array<string,int> $categories */
    private function showcaseBlocks(array $categories): void
    {
        $laptops = isset($categories['laptops']) ? DB::table('products')->where('sku', 'like', 'TECH-%')->whereIn('category_id', DB::table('categories')->where('parent_id', $categories['laptops'])->pluck('id'))->orderBy('id')->pluck('id')->all() : [];
        // Hand-picked: a new store has no paid orders yet, so a "best sellers" source would show nothing.
        $favourites = DB::table('products')->where('sku', 'like', 'TECH-%')->whereIn('id', DB::table('product_translations')->whereIn('slug', ['aster-x5', 'nimbus-air-13', 'volta-studio-anc', 'orbit-watch-3', 'volta-buds-pro', 'aster-tab-11', 'lumo-read-paper-7', 'kova-30w-charger'])->select('product_id'))->orderBy('id')->pluck('id')->all();
        $blocks = [
            ['products', 'product_carousel', 'Deals of the week', 'Προσφορές της εβδομάδας', ['source' => 'sale', 'limit' => 10, 'show_rating' => true], []],
            ['products', 'product_carousel', 'Laptops for every need', 'Laptops για κάθε ανάγκη', ['source' => 'manual', 'limit' => 8], ['items' => ['product_id', $laptops]]],
            ['products', 'product_carousel', 'Customer favourites', 'Αγαπημένα των πελατών', ['source' => 'manual', 'limit' => 10, 'show_rating' => true], ['items' => ['product_id', $favourites]]],
            ['brands', 'brand_carousel', 'Our brands', 'Οι μάρκες μας', ['source' => 'alphabetical', 'limit' => 12], []],
            ['blog_posts', 'blog_carousel', 'From the blog', 'Από το blog', ['source' => 'latest', 'limit' => 6], []],
        ];
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        if ($layout === null) {
            return;
        }
        foreach ($blocks as [$type, $template, $en, $el, $settings, $selections]) {
            if (DB::table('storefront_blocks')->where('layout_id', $layout)->where('name', $en)->exists()) {
                continue;
            }
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            $row = (int) DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->max('row') + 1;
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => $row, 'position' => 1]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => $type, 'name' => $en, 'template' => $template, 'settings' => json_encode($settings)]);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
            foreach (DB::table('locales')->get() as $locale) {
                DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id, 'title' => $locale->code === 'el' ? $el : $en]);
            }
            foreach ($selections as $field => [$column, $ids]) {
                foreach ($ids as $position => $id) {
                    DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => $field, 'position' => $position + 1, $column => $id]);
                }
            }
        }
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** The homepage newsletter sign-up, joining the first active newsletter list (when there is one). */
    private function newsletterBlock(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        $list = DB::table('newsletter_lists')->where('status', 'active')->orderBy('id')->value('id');
        if ($layout === null || $list === null || DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'newsletter')->exists()) {
            return;
        }
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        $row = (int) DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->max('row') + 1;
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => $row, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'newsletter', 'name' => 'Newsletter', 'template' => 'newsletter', 'settings' => json_encode(['terms_url' => '/terms-of-use', 'background_color' => '#dff0fd', 'full_width_background' => true])]);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->get() as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id, 'title' => $locale->code === 'el' ? 'Γράψου στο Newsletter μας για νέα & προσφορές!' : 'Sign up to our newsletter for news & offers!']);
        }
        DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => 'list', 'position' => 1, 'newsletter_list_id' => $list]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** Sample social pages, app links and payment pictures (only filled in while the merchant has none). */
    private function company(): void
    {
        $company = DB::table('company_settings')->where('id', 1)->first();
        if ($company === null) {
            return;
        }
        if (! DB::table('company_links')->exists()) {
            $links = [['social', 'facebook', 'https://www.facebook.com/'], ['social', 'instagram', 'https://www.instagram.com/'], ['social', 'tiktok', 'https://www.tiktok.com/'], ['social', 'linkedin', 'https://www.linkedin.com/'], ['app', 'app_store', 'https://apps.apple.com/'], ['app', 'google_play', 'https://play.google.com/store']];
            foreach ($links as $n => [$kind, $preset, $url]) {
                DB::table('company_links')->insert(['kind' => $kind, 'preset' => $preset, 'name' => CompanySettings::PRESETS[$kind][$preset], 'url' => $url, 'position' => $n + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (! DB::table('company_payment_icons')->exists()) {
            foreach (['Visa' => ['1a3a8f', '3b63c9'], 'Mastercard' => ['c8301f', 'f0a030'], 'PayPal' => ['0b3a6e', '2f80c8'], 'Cash on delivery' => ['4b5563', '9ca3af']] as $position => [$from, $to]) {
                // Placeholder pictures: the merchant replaces them with real logos in Settings → Company.
                DB::table('company_payment_icons')->insert(['media_file_id' => app(DemoPicture::class)->create('#'.$from, '#'.$to, 120, 76), 'name' => $position, 'position' => (int) DB::table('company_payment_icons')->count() + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** The shared footer block (created when an older install has none), with the sample columns and a bottom line. */
    private function footerBlock(int $menu): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'default')->value('id');
        if ($layout === null) {
            return;
        }
        $block = DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'footer')->value('id');
        if ($block === null) {
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'footer', 'width' => 16, 'row' => 1, 'position' => 1]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'footer', 'name' => 'Footer', 'template' => 'footer', 'settings' => json_encode((object) [])]);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        }
        foreach (DB::table('locales')->get() as $locale) {
            DB::table('storefront_block_translations')->updateOrInsert(['block_id' => $block, 'locale_id' => $locale->id], ['html' => $locale->code === 'el' ? '<p>© {year} {store_name}. Με επιφύλαξη παντός δικαιώματος.</p>' : '<p>© {year} {store_name}. All rights reserved.</p>']);
        }
        // Four menu columns plus the social/app column.
        DB::table('storefront_blocks')->where('id', $block)->update(['settings' => json_encode(['columns' => 5])]);
        DB::table('storefront_block_selections')->where('block_id', $block)->delete();
        DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => 'menu', 'position' => 1, 'menu_id' => $menu]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    private function directoryBlock(int $menu): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        if ($layout === null || DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'link_directory')->exists()) {
            return;
        }
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        $row = (int) DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->max('row') + 1;
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => $row, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'link_directory', 'name' => 'Discover everything', 'template' => 'link_directory', 'settings' => json_encode(['columns' => 6])]);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->get() as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id, 'title' => $locale->code === 'el' ? 'Ανακαλύψτε τα πάντα' : 'Discover everything']);
        }
        DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => 'menu', 'position' => 1, 'menu_id' => $menu]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** @param array<string,list<int>> $fields */
    private function select(string $page, string $type, array $fields, string $column, bool $onlyIfEmpty = false): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', $page)->value('id');
        $block = $layout === null ? null : DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', $type)->value('id');
        if ($block === null || ($onlyIfEmpty && DB::table('storefront_block_selections')->where('block_id', $block)->exists())) {
            return;
        }
        DB::table('storefront_block_selections')->where('block_id', $block)->delete();
        foreach ($fields as $field => $ids) {
            foreach ($ids as $position => $id) {
                DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => $field, 'position' => $position + 1, $column => $id]);
            }
        }
        // A new layout version makes the cached structure unreachable.
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }
}
