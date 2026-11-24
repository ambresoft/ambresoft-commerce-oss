<?php

namespace App\Website\Infrastructure;

use App\Catalog\Infrastructure\CatalogDemoSeeder;
use App\Shared\Infrastructure\DemoPicture;
use Illuminate\Support\Facades\DB;

/** Sample header menus built by hand from the demo categories (so links are the categories' full SEO paths). */
final class MenuDemoSeeder
{
    /** @param array<string,int> $categories demo category key => id
     * @return array{main:int,secondary:int}|null null when the sample menus already exist */
    public function run(array $categories): ?array
    {
        if (DB::table('website_menu_translations')->where('name', 'Main menu (demo)')->exists() || $categories === []) {
            return null;
        }
        $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code')->all();
        $main = $this->menu('Main menu (demo)', 'Κύριο μενού (demo)', 'header', $locales);
        $secondary = $this->menu('Secondary links (demo)', 'Δευτερεύοντες σύνδεσμοι (demo)', 'other', $locales);
        $pictures = app(DemoPicture::class);
        $names = CatalogDemoSeeder::CATEGORIES;
        $add = function (int $parentItem, string $key, int $depth, int $position) use (&$add, $categories, $names, $main, $locales, $pictures): void {
            if (! isset($categories[$key])) {
                return;
            }
            // Only the first level carries a picture, like the reference mega menu.
            $item = $this->item($main, $parentItem, $names[$key][1], $names[$key][2], 'category', null, $categories[$key], $position, $locales, $depth === 1 ? $pictures->photo(CatalogDemoSeeder::photo($key)) : null);
            $n = 0;
            foreach ($names as $child => $row) {
                if ($row[0] === $key) {
                    $add($item, $child, $depth + 1, ++$n * 10);
                }
            }
        };
        $top = $this->item($main, null, 'Categories', 'Κατηγορίες', 'heading', null, null, 10, $locales, null);
        $n = 0;
        foreach ($names as $key => $row) {
            if ($row[0] === null) {
                $add($top, $key, 1, ++$n * 10);
            }
        }
        $offers = $this->item($main, null, 'Offers', 'Προσφορές', 'heading', null, null, 20, $locales, null);
        $this->item($main, $offers, 'Deals of the day', 'Προσφορές της ημέρας', 'url', '/offers/deals', null, 10, $locales, null);
        $this->item($main, $offers, 'New arrivals', 'Νέες αφίξεις', 'url', '/offers/new', null, 20, $locales, null);
        foreach ([['Commercial', 'Επαγγελματίες', '/commercial'], ['Gift card', 'Δωροκάρτα', '/gift-card'], ['Order status', 'Κατάσταση παραγγελίας', '/order-status'], ['Customer service', 'Εξυπηρέτηση πελατών', '/customer-service']] as $i => [$en, $el, $url]) {
            $this->item($secondary, null, $en, $el, 'url', $url, null, ($i + 1) * 10, $locales, null);
        }

        return ['main' => $main, 'secondary' => $secondary];
    }

    /** A free "link groups" menu (one group per top-level category, links typed by hand): the homepage directory.
     * @return int|null null when it already exists */
    public function links(): ?int
    {
        if (DB::table('website_menu_translations')->where('name', 'Discover everything (demo)')->exists()) {
            return null;
        }
        $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code')->all();
        $menu = $this->menu('Discover everything (demo)', 'Ανακαλύψτε τα πάντα (demo)', 'other', $locales, 'link_groups');
        $names = CatalogDemoSeeder::CATEGORIES;
        $path = function (string $key) use (&$path, $names): string {
            return ($names[$key][0] === null ? '' : $path($names[$key][0])).'/'.$key;
        };
        $position = 0;
        foreach ($names as $key => $row) {
            if ($row[0] !== null) {
                continue;
            }
            $group = $this->item($menu, null, $row[1], $row[2], 'url', $path($key), null, ++$position * 10, $locales, null);
            // The first link repeats the heading, like a directory page; then the sub-categories (two levels only).
            $this->item($menu, $group, $row[1], $row[2], 'url', $path($key), null, 10, $locales, null);
            $n = 1;
            foreach ($names as $child => $childRow) {
                if ($childRow[0] === $key) {
                    $this->item($menu, $group, $childRow[1], $childRow[2], 'url', $path($child), null, ++$n * 10, $locales, null);
                }
            }
        }

        return $menu;
    }

    /** The footer columns: a link-groups menu with plain text lines (address, phone, hours) and contact links.
     * @return int|null null when it already exists */
    public function footer(): ?int
    {
        if (DB::table('website_menu_translations')->where('name', 'Footer (demo)')->exists()) {
            return null;
        }
        $locales = DB::table('locales')->where('is_active', true)->pluck('id', 'code')->all();
        $menu = $this->menu('Footer (demo)', 'Υποσέλιδο (demo)', 'footer', $locales, 'link_groups');
        $columns = [
            ['My account', 'Ο λογαριασμός μου', [['Sign in', 'Σύνδεση', '/login'], ['Create account', 'Δημιουργία λογαριασμού', '/register']]],
            ['{store_name}', '{store_name}', [['About us', 'Σχετικά με εμάς', '/about'], ['Contact us', 'Επικοινωνία', '/contact'], ['Gift certificates', 'Δωροεπιταγές', '/gift-certificates'], ['Our brands', 'Οι μάρκες μας', '/brands'], ['Blog', 'Blog', '/blog'], ['Sitemap', 'Χάρτης ιστότοπου', '/sitemap']]],
            ['Customer service', 'Εξυπηρέτηση πελατών', [['About your order', 'Σχετικά με την παραγγελία σας', '/order-status'], ['Wishlist', 'Λίστα επιθυμιών', '/favorites'], ['Comparison list', 'Λίστα σύγκρισης', '/compare']]],
            ['Contact us', 'Επικοινωνία', [['44 Main street, Boston', 'Main street 44, Βοστώνη', null], ['+1 (917) 555-5555', '+1 (917) 555-5555', 'tel:+19175555555'], ['Mon-Sun 9.00 - 18.00', 'Δευ-Κυρ 9.00 - 18.00', null], ['demo@example.com', 'demo@example.com', 'mailto:demo@example.com'], ['View on map', 'Προβολή στον χάρτη', 'https://maps.google.com']]],
        ];
        foreach ($columns as $i => [$en, $el, $lines]) {
            $group = $this->item($menu, null, $en, $el, 'heading', null, null, ($i + 1) * 10, $locales, null);
            foreach ($lines as $j => [$lineEn, $lineEl, $url]) {
                $this->item($menu, $group, $lineEn, $lineEl, $url === null ? 'heading' : 'url', $url, null, ($j + 1) * 10, $locales, null);
            }
        }

        return $menu;
    }

    /** @param array<string,int> $locales */
    private function menu(string $en, string $el, string $placement, array $locales, string $style = 'standard'): int
    {
        $id = (int) DB::table('website_menus')->insertGetId(['status' => 'active', 'placement' => $placement, 'source' => 'manual', 'style' => $style, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($locales as $code => $locale) {
            DB::table('website_menu_translations')->insert(['menu_id' => $id, 'locale_id' => $locale, 'name' => $code === 'el' ? $el : $en]);
        }

        return $id;
    }

    /** @param array<string,int> $locales */
    private function item(int $menu, ?int $parent, string $en, string $el, string $kind, ?string $url, ?int $category, int $position, array $locales, ?int $media): int
    {
        $id = (int) DB::table('website_menu_items')->insertGetId(['menu_id' => $menu, 'parent_id' => $parent, 'status' => 'active', 'position' => $position, 'kind' => $kind, 'url' => $kind === 'url' ? $url : null, 'category_id' => $kind === 'category' ? $category : null, 'image_media_id' => $media, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($locales as $code => $locale) {
            DB::table('website_menu_item_translations')->insert(['item_id' => $id, 'locale_id' => $locale, 'name' => $code === 'el' ? $el : $en]);
        }

        return $id;
    }
}
