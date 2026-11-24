<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CatalogDemoSeeder;
use App\Catalog\Infrastructure\ShowcaseProductDemoSeeder;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\PrivateVideoStorage;
use App\Settings\Infrastructure\CompanySettings;
use App\Shared\Application\Command\ImageUpload;
use App\Shared\Infrastructure\DemoPicture;
use App\Website\Infrastructure\MenuDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** A ready storefront for demos: the electronics catalog, hand-built menus, and a full homepage wired to them. */
final class DemoStoreSeeder
{
    /** @return array{categories:int,products:int,menus:bool} */
    public function run(): array
    {
        $catalog = app(CatalogDemoSeeder::class);
        $created = $catalog->run();
        $categories = $created['ids'] ?? $catalog->ids();
        app(ShowcaseProductDemoSeeder::class)->run();
        $menus = app(MenuDemoSeeder::class)->run($categories);
        if ($menus !== null) {
            $this->select('default', 'menu', ['main' => [$menus['main']], 'secondary' => [$menus['secondary']]], 'menu_id');
            $this->compactHeader($menus['main']);
        }
        if ($created !== null) {
            $this->firstRunLook();
        }
        $this->reels();
        $this->showcaseBlocks($categories, app(MenuDemoSeeder::class)->quickNavigation());
        // The newsletter sign-up, then the directory of link groups at the bottom of the homepage.
        $this->newsletterBlock();
        $links = app(MenuDemoSeeder::class)->links();
        if ($links !== null) {
            $this->directoryBlock($links);
        }
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
                'en' => [$name.' – Technology and electronics online store', 'Shop smartphones, tablets, laptops, audio and wearables at great prices, with fast delivery and easy returns.', 'electronics, technology, smartphones, tablets, laptops, audio, wearables'],
                'el' => [$name.' – Ηλεκτρονικό κατάστημα τεχνολογίας', 'Αγοράστε smartphones, tablets, laptops, ήχο και φορητές συσκευές σε τιμές-έκπληξη, με γρήγορη παράδοση και εύκολες επιστροφές.', 'ηλεκτρονικά, τεχνολογία, smartphones, tablets, laptops, ήχος, φορητές συσκευές'],
            ],
            'homepage' => [
                'en' => [$name.' – Smartphones, tablets, laptops and more', 'Discover new arrivals and top offers in phones, tablets, laptops, audio, wearables and accessories. Order online with fast delivery.', 'new arrivals, offers, smartphones, tablets, laptops, headphones, smartwatches'],
                'el' => [$name.' – Smartphones, tablets, laptops και πολλά ακόμη', 'Ανακαλύψτε νέες αφίξεις και προσφορές σε κινητά, tablets, laptops, ήχο, φορητές συσκευές και αξεσουάρ. Παραγγείλετε online με γρήγορη παράδοση.', 'νέες αφίξεις, προσφορές, smartphones, tablets, laptops, ακουστικά, smartwatches'],
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

    /** The compact header: logo, a wide search bar with the main menu in its drawer, and the account, wishlist and cart icons.
     * The separate menu row is switched off; the menu opens from the search bar. */
    private function compactHeader(int $menu): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'default')->value('id');
        $identity = $layout === null ? null : DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'identity')->first();
        if ($identity === null) {
            return;
        }
        $settings = [...json_decode((string) $identity->settings, true) ?: [], 'header_style' => 'compact', 'show_search' => true, 'show_logo' => true, 'background_color' => '#09ab7d', 'text_color' => '#ffffff', 'icon_color' => '#ffffff', 'hidden_paths' => '/checkout'];
        DB::table('storefront_blocks')->where('id', $identity->id)->update(['settings' => json_encode($settings)]);
        DB::table('storefront_block_selections')->where('block_id', $identity->id)->where('field', 'drawer_menu')->delete();
        DB::table('storefront_block_selections')->insert(['block_id' => $identity->id, 'field' => 'drawer_menu', 'position' => 1, 'menu_id' => $menu]);
        DB::table('storefront_placements')->where('layout_id', $layout)->whereIn('block_id', DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'menu')->select('id'))->update(['enabled' => false]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** On a fresh demo: full-width hero banners, the "showcase" product card and the Ambresoft logo. */
    private function firstRunLook(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        $banners = $layout === null ? null : DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'banners')->first();
        if ($banners !== null) {
            $settings = [...json_decode((string) $banners->settings, true) ?: [], 'full_width' => true, 'visible_desktop' => 1, 'visible_tablet' => 1, 'height_desktop' => 100, 'autoplay_seconds' => 6];
            DB::table('storefront_blocks')->where('id', $banners->id)->update(['settings' => json_encode($settings)]);
        }
        // Phones swipe the starter carousels; their arrows only cover the pictures.
        foreach ($layout === null ? [] : DB::table('storefront_blocks')->where('layout_id', $layout)->whereIn('type', ['banners', 'products', 'categories'])->get() as $block) {
            DB::table('storefront_blocks')->where('id', $block->id)->update(['settings' => json_encode([...json_decode((string) $block->settings, true) ?: [], 'hide_arrows_mobile' => true])]);
        }
        if ($layout !== null) {
            DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
        }
        // The checkout's own header in the store green.
        $checkout = DB::table('storefront_layouts')->where('page_type', 'checkout')->value('id');
        foreach ($checkout === null ? [] : DB::table('storefront_blocks')->where('layout_id', $checkout)->where('type', 'checkout_header')->get() as $block) {
            DB::table('storefront_blocks')->where('id', $block->id)->update(['settings' => json_encode([...json_decode((string) $block->settings, true) ?: [], 'background_color' => '#09ab7d', 'text_color' => '#ffffff'])]);
            DB::table('storefront_layouts')->where('id', $checkout)->increment('lock_version');
        }
        DB::table('general_settings')->update(['product_card_style' => 'showcase']);
        // Cash on delivery needs no provider account, so the demo checkout can place orders right away.
        DB::table('payment_methods')->where('code', 'cod')->update(['is_active' => true]);
        if (DB::table('company_settings')->where('id', 1)->whereNull('logo_media_id')->exists()) {
            DB::table('company_settings')->where('id', 1)->update(['logo_media_id' => app(DemoPicture::class)->photo('logo')]);
        }
    }

    /** Six sample reels (bundled videos and covers in database/demo/videos), each linked to the products it shows. */
    private function reels(): void
    {
        if (DB::table('reels')->exists()) {
            return;
        }
        $samples = [['made-to-move', 'Made to move', 'Φτιαγμένο για κίνηση', []], ['aster-x5', 'Aster X5 Pro camera', 'Κάμερα Aster X5 Pro', ['aster-x5-pro', 'aster-x5']],
            ['nimbus-air', 'Nimbus Air in 6 seconds', 'Το Nimbus Air σε 6 δευτερόλεπτα', ['nimbus-air-13', 'nimbus-air-15']], ['volta-studio', 'Volta Studio ANC', 'Volta Studio ANC', ['volta-studio-anc', 'volta-buds-pro']],
            ['orbit-watch', 'Orbit Watch 3', 'Orbit Watch 3', ['orbit-watch-3', 'orbit-watch-3-sport']], ['aster-tab', 'Aster Tab 11', 'Aster Tab 11', ['aster-tab-11', 'aster-pen-2']]];
        $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code');
        foreach ($samples as $n => [$file, $en, $el, $slugs]) {
            $path = tempnam(sys_get_temp_dir(), 'reel') ?: throw new RuntimeException('Unable to create a temporary file.');
            copy(database_path('demo/videos/reel-'.$file.'.mp4'), $path);
            $video = app(PrivateVideoStorage::class)->store(new UploadedFile($path, $file.'.mp4', 'video/mp4', null, true), 'video');
            @unlink($path);
            $cover = tempnam(sys_get_temp_dir(), 'reel') ?: throw new RuntimeException('Unable to create a temporary file.');
            copy(database_path('demo/videos/reel-'.$file.'.jpg'), $cover);
            $image = app(PrivateImageStorage::class)->store(new ImageUpload($cover));
            @unlink($cover);
            $id = DB::table('reels')->insertGetId(['status' => 'active', 'video_id' => DB::table('media_videos')->insertGetId([...$video, 'created_at' => now(), 'updated_at' => now()]),
                'cover_media_id' => MediaFileRecord::create($image)->id, 'created_at' => now()->subMinutes($n), 'updated_at' => now()]);
            foreach ($locales as $code => $locale) {
                DB::table('reel_translations')->insert(['reel_id' => $id, 'locale_id' => $locale, 'name' => $code === 'el' ? $el : $en]);
            }
            $products = DB::table('product_translations')->whereIn('slug', $slugs)->distinct()->pluck('product_id');
            foreach ($products->values() as $position => $product) {
                DB::table('reel_products')->insert(['reel_id' => $id, 'product_id' => $product, 'position' => $position + 1]);
            }
        }
    }

    /** The homepage showcase under the starter blocks, then every content block put in the demo order.
     * @param array<string,int> $categories */
    private function showcaseBlocks(array $categories, ?int $quickNavigation): void
    {
        $laptops = isset($categories['laptops']) ? DB::table('products')->where('sku', 'like', 'TECH-%')->whereIn('category_id', DB::table('categories')->where('parent_id', $categories['laptops'])->pluck('id'))->orderBy('id')->pluck('id')->all() : [];
        // Hand-picked: a new store has no paid orders yet, so a "best sellers" source would show nothing.
        $favourites = DB::table('products')->where('sku', 'like', 'TECH-%')->whereIn('id', DB::table('product_translations')->whereIn('slug', ['nimbus-pro-16-blk-32-1t', 'aster-x5', 'nimbus-air-13', 'volta-studio-anc', 'orbit-watch-3', 'volta-buds-pro', 'aster-tab-11', 'lumo-read-paper-7', 'kova-30w-charger'])->select('product_id'))->orderBy('id')->pluck('id')->all();
        $quick = $quickNavigation ?? DB::table('website_menu_translations')->where('name', 'Quick navigation (demo)')->value('menu_id');
        $blocks = [
            ['visual_menu', 'menu_tiles_carousel', 'Quick navigation', 'Γρήγορη πλοήγηση', ['limit' => 12, 'hide_arrows_mobile' => true], $quick === null ? [] : ['menu' => ['menu_id', [$quick]]]],
            ['reels', 'reel_carousel', 'Our videos', 'Τα βίντεο μας', ['source' => 'newest', 'limit' => 12, 'per_row' => 5, 'hide_arrows_mobile' => true], []],
            ['products', 'product_carousel', 'Deals of the week', 'Προσφορές της εβδομάδας', ['source' => 'sale', 'limit' => 10, 'show_rating' => true, 'hide_arrows_mobile' => true], []],
            ['products', 'product_carousel', 'Laptops for every need', 'Laptops για κάθε ανάγκη', ['source' => 'manual', 'limit' => 8, 'hide_arrows_mobile' => true], ['items' => ['product_id', $laptops]]],
            ['products', 'product_carousel', 'Customer favourites', 'Αγαπημένα των πελατών', ['source' => 'manual', 'limit' => 10, 'show_rating' => true, 'hide_arrows_mobile' => true], ['items' => ['product_id', $favourites]]],
            ['coupons', 'coupon_carousel', 'Coupons for you', 'Κουπόνια για εσάς', ['limit' => 6, 'per_row' => 3, 'hide_arrows_mobile' => true], ['items' => ['coupon_id', DB::table('coupons')->whereIn('code', ['WELCOME10', 'SAVE20', 'FREESHIP'])->orderBy('id')->pluck('id')->all()]]],
            ['brands', 'brand_carousel', 'Our brands', 'Οι μάρκες μας', ['source' => 'alphabetical', 'limit' => 12, 'per_row' => 4, 'image_width' => 320, 'image_height' => 180, 'image_radius' => 20, 'hide_arrows_mobile' => true], []],
            ['blog_posts', 'blog_carousel', 'From the blog', 'Από το blog', ['source' => 'latest', 'limit' => 6, 'hide_arrows_mobile' => true], []],
        ];
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        if ($layout === null) {
            return;
        }
        $added = false;
        foreach ($blocks as [$type, $template, $en, $el, $settings, $selections]) {
            if (DB::table('storefront_blocks')->where('layout_id', $layout)->where('name', $en)->exists()) {
                continue;
            }
            $added = true;
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
        if (! $added) {
            return;
        }
        // Demo order: banners, quick navigation, new arrivals, categories, deals, videos, then the rest as added.
        $rank = ['banners' => 1, 'Quick navigation' => 2, 'products:newest' => 3, 'categories' => 4, 'Deals of the week' => 5, 'Our videos' => 6];
        $grids = DB::table('storefront_grids as g')->join('storefront_placements as p', 'p.grid_id', '=', 'g.id')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')
            ->where('g.layout_id', $layout)->where('g.region', 'content')->orderBy('g.row')->get(['g.id', 'g.row', 'b.type', 'b.name', 'b.settings']);
        $key = fn (object $g): string => isset($rank[$g->name]) ? $g->name : ($g->type === 'products' && (json_decode((string) $g->settings, true)['source'] ?? '') === 'newest' && $g->name === 'Products' ? 'products:newest' : $g->type);
        $sorted = $grids->sortBy(fn (object $g): array => [$rank[$key($g)] ?? 100, $g->row])->values();
        // Two passes keep the (layout, region, row) positions unique while they move.
        foreach ($sorted as $n => $g) {
            DB::table('storefront_grids')->where('id', $g->id)->update(['row' => 10000 + $n]);
        }
        foreach ($sorted as $n => $g) {
            DB::table('storefront_grids')->where('id', $g->id)->update(['row' => $n + 1]);
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
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'newsletter', 'name' => 'Newsletter', 'template' => 'newsletter', 'settings' => json_encode(['terms_url' => '/terms-of-use', 'background_color' => '#ecfdf5', 'full_width_background' => true, 'padding_top' => 48, 'padding_bottom' => 48, 'title_size_desktop' => 28])]);
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
            foreach (['Visa' => 'payment-visa', 'Mastercard' => 'payment-mastercard', 'American Express' => 'payment-amex', 'Apple Pay' => 'payment-applepay', 'PayPal' => 'payment-paypal', 'Cash on delivery' => 'payment-cod'] as $name => $picture) {
                // The logos of common payment methods; the merchant keeps only the ones they accept in Settings → Company.
                DB::table('company_payment_icons')->insert(['media_file_id' => app(DemoPicture::class)->photo($picture), 'name' => $name, 'position' => (int) DB::table('company_payment_icons')->count() + 1, 'created_at' => now(), 'updated_at' => now()]);
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
