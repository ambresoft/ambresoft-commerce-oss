<?php

namespace App\Storefront\Infrastructure;

use App\Storefront\Application\Contract\BlockType;
use App\Storefront\Domain\Service\PathRules;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FirstPartyBlock implements BlockType
{
    /** Product page section settings (sections are shown by default; category matching is opt-in). */
    public const MAIN_SECTIONS = ['show_bought_together', 'show_similar', 'show_newest', 'newest_same_category', 'show_recently_viewed', 'show_description', 'show_features', 'show_reviews', 'show_faqs', 'show_documents', 'show_price_history', 'show_product_bar', 'show_variation_alternatives'];

    /** Product page carousels whose visible items per row the main content block sets. */
    public const PRODUCT_CAROUSELS = ['similar', 'newest', 'recently_viewed', 'variation_alternatives'];

    public const PRODUCT_SECTION_ORDER = ['show_similar', 'show_newest', 'show_recently_viewed', 'show_description', 'show_features', 'show_reviews'];

    /** Allowed image width/height in CSS pixels. */
    public const SIZE = [40, 1200];

    public function __construct(private string $type) {}

    public function id(): string
    {
        return $this->type;
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $selection = fn (string $key, string $source, bool $single = false): array => ['key' => $key, 'kind' => 'selection', 'label' => 'layouts.'.$key, 'source' => $source, 'single' => $single];
        $limit = ['key' => 'limit', 'kind' => 'number', 'label' => 'layouts.limit', 'min' => 1, 'max' => 48];
        $flag = fn (string $key): array => ['key' => $key, 'kind' => 'boolean', 'label' => 'layouts.'.$key];
        $size = fn (string $key): array => ['key' => $key, 'kind' => 'number', 'label' => 'layouts.'.$key, 'min' => self::SIZE[0], 'max' => self::SIZE[1], 'unit' => 'px'];
        $background = ['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.background_color'];
        $titleSize = fn (string $device): array => ['key' => 'title_size_'.$device, 'kind' => 'number', 'label' => 'layouts.title_size_'.$device, 'min' => 12, 'max' => 72, 'unit' => 'px'];
        $titleColor = ['key' => 'title_color', 'kind' => 'color', 'label' => 'layouts.title_color', 'reset_label' => 'layouts.title_color_reset'];
        $fontSize = fn (string $device): array => ['key' => 'font_size_'.$device, 'kind' => 'number', 'label' => 'layouts.font_size_'.$device, 'min' => 10, 'max' => 32, 'unit' => 'px'];
        $hiddenPaths = ['key' => 'hidden_paths', 'kind' => 'textarea', 'label' => 'layouts.hidden_paths', 'help' => 'layouts.hidden_paths_help'];
        $linkText = ['key' => 'link_text', 'kind' => 'translated_text', 'label' => 'layouts.link_text', 'max' => 100];
        $linkUrl = ['key' => 'link_url', 'kind' => 'url', 'label' => 'layouts.link_url', 'help' => 'layouts.link_url_help'];
        $navigation = ['key' => 'navigation', 'kind' => 'select', 'label' => 'layouts.navigation', 'options' => ['outside', 'inside', 'none'], 'option_labels' => ['outside' => 'layouts.navigation_outside', 'inside' => 'layouts.navigation_inside', 'none' => 'layouts.navigation_none']];
        $fields = match ($this->type) {
            'reels' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['manual', 'newest', 'most_viewed'], 'option_labels' => ['manual' => 'layouts.source_manual', 'newest' => 'reels.newest', 'most_viewed' => 'reels.most_viewed']], [...$selection('items', 'reels'), 'when' => ['source' => 'manual']], $limit, ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.per_row', 'min' => 1, 'max' => 8], $navigation],
            'banners' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['manual', 'latest', 'group']], $selection('items', 'banners'), $selection('group', 'banner_groups', true), $limit, ['key' => 'visible_desktop', 'kind' => 'number', 'label' => 'layouts.visible_desktop', 'help' => 'layouts.visible_help', 'min' => 1, 'max' => 4, 'carousel_only' => true], ['key' => 'visible_tablet', 'kind' => 'number', 'label' => 'layouts.visible_tablet', 'min' => 1, 'max' => 4, 'carousel_only' => true], ['key' => 'height_desktop', 'kind' => 'number', 'label' => 'layouts.height_desktop', 'help' => 'layouts.height_help', 'min' => 100, 'max' => 250, 'unit' => '%'], ['key' => 'height_tablet', 'kind' => 'number', 'label' => 'layouts.height_tablet', 'min' => 100, 'max' => 250, 'unit' => '%'], ['key' => 'autoplay_seconds', 'kind' => 'number', 'label' => 'layouts.autoplay_seconds', 'min' => 0, 'max' => 60, 'unit' => 's'], $navigation, ['key' => 'navigation_style', 'kind' => 'select', 'label' => 'layouts.navigation_style', 'options' => ['arrows', 'dots'], 'option_labels' => ['arrows' => 'layouts.style_arrows', 'dots' => 'layouts.style_dots']], $flag('drag_scroll'), $flag('infinite_scroll'), $flag('full_width'), $flag('remove_border_radius'),
                // Desktop: the banner grows to its grid's height (a tall banner beside stacked ones); phones keep its own shape.
                ['key' => 'fill_height', 'kind' => 'boolean', 'label' => 'layouts.fill_height', 'help' => 'layouts.fill_height_help'],
                // Which banners load at once: none, only the first (the banner at the very top of the page) or all of them.
                ['key' => 'preload', 'kind' => 'select', 'label' => 'layouts.preload', 'help' => 'layouts.preload_help', 'options' => ['none', 'first', 'all'], 'option_labels' => ['none' => 'layouts.preload_none', 'first' => 'layouts.preload_first', 'all' => 'layouts.preload_all']]],
            'products' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['manual', 'newest', 'bestsellers', 'sale', 'recently_viewed']], $selection('items', 'products'), [...$flag('preserve_manual_products'), 'help' => 'layouts.preserve_manual_products_help', 'when' => ['source' => 'manual']], [...$selection('variation_groups', 'variation_groups'), 'label' => 'variations.title', 'help' => 'variations.manual_group_help', 'when' => ['source' => 'manual']], [...$selection('filter_categories', 'categories'), 'help' => 'layouts.filter_categories_help'], $limit, ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.per_row', 'min' => 1, 'max' => 8], $flag('show_price'), $flag('show_add_to_cart'), $flag('show_sku'), $flag('show_availability'), $flag('show_rating'), $flag('show_brand'), $flag('show_category'), $flag('show_variations'), $flag('show_favorites'), $flag('card_shadow'), $flag('autoscroll'), $flag('drag_scroll'), $flag('infinite_scroll'), $navigation, $size('image_width'), $size('image_height'), $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $linkText, $linkUrl,
                // A picture beside the carousel (Image tab); not a link.
                ['key' => 'image', 'kind' => 'image', 'label' => 'layouts.side_image', 'help' => 'layouts.side_image_help', 'tab' => 'image'],
                ['key' => 'image_side', 'kind' => 'select', 'label' => 'layouts.image_side', 'options' => ['left', 'right'], 'option_labels' => ['left' => 'layouts.image_left', 'right' => 'layouts.image_right'], 'tab' => 'image'],
                // How the picture fills its space (CSS object-fit).
                ['key' => 'image_fit', 'kind' => 'select', 'label' => 'layouts.image_fit', 'help' => 'layouts.image_fit_help', 'options' => ['contain', 'cover', 'fill', 'scale-down'], 'option_labels' => ['contain' => 'layouts.image_fit_contain', 'cover' => 'layouts.image_fit_cover', 'fill' => 'layouts.image_fit_fill', 'scale-down' => 'layouts.image_fit_scale_down'], 'tab' => 'image'],
                ['key' => 'image_link', 'kind' => 'url', 'label' => 'layouts.image_link', 'help' => 'layouts.image_link_help', 'tab' => 'image'],
                ['key' => 'image_fit_mobile', 'kind' => 'select', 'label' => 'layouts.image_fit_mobile', 'help' => 'layouts.image_fit_mobile_help', 'options' => ['contain', 'cover', 'fill', 'scale-down'], 'option_labels' => ['contain' => 'layouts.image_fit_contain', 'cover' => 'layouts.image_fit_cover', 'fill' => 'layouts.image_fit_fill', 'scale-down' => 'layouts.image_fit_scale_down'], 'tab' => 'image']],
            'categories' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['manual', 'root']], $selection('items', 'categories'), $limit, ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.per_row', 'min' => 1, 'max' => 8], ['key' => 'rows', 'kind' => 'select', 'label' => 'layouts.rows', 'options' => ['1', '2'], 'option_labels' => ['1' => 'layouts.rows_1', '2' => 'layouts.rows_2']], $flag('card_shadow'), $flag('autoscroll'), $flag('drag_scroll'), $flag('infinite_scroll'), $navigation, $size('image_width'), $size('image_height'), ['key' => 'image_radius', 'kind' => 'number', 'label' => 'layouts.image_radius', 'min' => 0, 'max' => 48, 'unit' => 'px'], $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $linkText, $linkUrl],
            // Blog post cards: the newest published posts (of one blog, or all), or posts picked by hand.
            'blog_posts' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['latest', 'manual'], 'option_labels' => ['latest' => 'layouts.source_latest_posts', 'manual' => 'layouts.source_manual']],
                [...$selection('blog', 'blogs', true), 'help' => 'layouts.blog_filter_help', 'when' => ['source' => 'latest']], [...$selection('items', 'blog_posts'), 'label' => 'layouts.blog_posts', 'when' => ['source' => 'manual']],
                $limit, ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.per_row', 'min' => 1, 'max' => 6], $flag('show_date'), $flag('show_excerpt'), $flag('card_shadow'), $flag('autoscroll'), $flag('drag_scroll'), $flag('infinite_scroll'), $navigation,
                $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $linkText, $linkUrl],
            'coupons', 'offers' => [[...$selection('items', $this->type === 'coupons' ? 'coupons' : 'promotions'), 'label' => 'layouts.pick_'.$this->type, 'help' => 'layouts.deals_picker_help'], $limit, ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.per_row', 'min' => 1, 'max' => 6], ['key' => 'title_icon', 'kind' => 'select', 'label' => 'layouts.title_icon', 'options' => ['none', 'ticket', 'gift', 'trophy', 'percent'], 'option_labels' => ['none' => 'layouts.icon_none', 'ticket' => 'layouts.icon_ticket', 'gift' => 'layouts.icon_gift', 'trophy' => 'layouts.icon_trophy', 'percent' => 'layouts.icon_percent']], $flag('card_shadow'), $flag('autoscroll'), $flag('drag_scroll'), $flag('infinite_scroll'), $navigation, $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $linkText, $linkUrl],
            'html' => [['key' => 'html', 'kind' => 'richtext', 'label' => 'layouts.html']],
            // Product page sections (Products layout), the wishlist grid (Wishlist layout), the subcategory cards (Category landing layout) and the product listing (Category layout); the editor shows each page's own. The Account layout's block has no settings.
            'main_content' => [...array_map(fn (array $f): array => [...$f, 'pages' => ['products'], ...(in_array($f['key'], self::PRODUCT_SECTION_ORDER, true) ? [] : ['tab' => 'product_options'])], array_map($flag, self::MAIN_SECTIONS)),
                ['key' => 'section_order', 'kind' => 'order', 'label' => 'layouts.section_order', 'help' => 'layouts.section_order_help', 'options' => self::PRODUCT_SECTION_ORDER, 'option_labels' => array_combine(self::PRODUCT_SECTION_ORDER, array_map(fn (string $key): string => 'layouts.'.$key, self::PRODUCT_SECTION_ORDER)), 'pages' => ['products']],
                ...array_map(fn (string $carousel): array => ['key' => $carousel.'_per_row', 'kind' => 'number', 'label' => 'layouts.'.$carousel.'_per_row', 'min' => 1, 'max' => 8, 'pages' => ['products'], ...($carousel === 'variation_alternatives' ? ['tab' => 'product_options'] : [])], self::PRODUCT_CAROUSELS),
                // How many products each carousel offers in total (it scrolls through those beyond one row).
                ...array_map(fn (string $carousel): array => ['key' => $carousel.'_limit', 'kind' => 'number', 'label' => 'layouts.'.$carousel.'_limit', 'min' => 1, 'max' => 30, 'pages' => ['products'], ...($carousel === 'variation_alternatives' ? ['tab' => 'product_options'] : [])], self::PRODUCT_CAROUSELS),
                ['key' => 'per_row', 'kind' => 'number', 'label' => 'layouts.wishlist_per_row', 'min' => 1, 'max' => 8, 'pages' => ['wishlist', 'category_landing']],
                // Category landing pages: the subcategory cards.
                ['key' => 'card_shadow', 'kind' => 'boolean', 'label' => 'layouts.card_shadow', 'pages' => ['category_landing']],
                ['key' => 'mobile_display', 'kind' => 'select', 'label' => 'layouts.mobile_display', 'options' => ['grid', 'carousel'], 'option_labels' => ['grid' => 'layouts.mobile_display_grid', 'carousel' => 'layouts.mobile_display_carousel'], 'pages' => ['category_landing']],
                ['key' => 'mobile_per_row', 'kind' => 'number', 'label' => 'layouts.mobile_per_row', 'min' => 1, 'max' => 4, 'pages' => ['category_landing']],
                // Product listings (Category layout, and brand pages on the Catalog landing layout): the product grid and its controls.
                ['key' => 'products_per_row', 'kind' => 'number', 'label' => 'layouts.products_per_row', 'min' => 2, 'max' => 5, 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'mobile_products_per_row', 'kind' => 'select', 'label' => 'layouts.mobile_products_per_row', 'options' => ['1', '2'], 'option_labels' => ['1' => 'layouts.mobile_products_per_row_one', '2' => 'layouts.mobile_products_per_row_two'], 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'tablet_products_per_row', 'kind' => 'select', 'label' => 'layouts.tablet_products_per_row', 'options' => ['2', '3'], 'option_labels' => ['2' => 'layouts.tablet_products_per_row_two', '3' => 'layouts.tablet_products_per_row_three'], 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'products_per_page', 'kind' => 'number', 'label' => 'layouts.products_per_page', 'min' => 6, 'max' => 60, 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'default_sort', 'kind' => 'select', 'label' => 'layouts.default_sort', 'options' => CategoryListing::SORTS, 'option_labels' => array_combine(CategoryListing::SORTS, array_map(fn (string $s): string => 'layouts.sort_'.$s, CategoryListing::SORTS)), 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'show_sorting', 'kind' => 'boolean', 'label' => 'layouts.show_sorting', 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'show_count', 'kind' => 'boolean', 'label' => 'layouts.show_count', 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'show_brand', 'kind' => 'boolean', 'label' => 'layouts.show_brand', 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'show_category', 'kind' => 'boolean', 'label' => 'layouts.show_category', 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                ['key' => 'show_variations', 'kind' => 'boolean', 'label' => 'layouts.show_variations', 'pages' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search']],
                // Checkout: checkboxes above the order button. Required pages must be accepted before ordering; each page opens in a dialog.
                [...$selection('required_pages', 'pages'), 'help' => 'layouts.required_pages_help', 'pages' => ['checkout']],
                [...$selection('optional_pages', 'pages'), 'help' => 'layouts.optional_pages_help', 'pages' => ['checkout']],
                [...$selection('newsletter_list', 'newsletter_lists', true), 'label' => 'layouts.checkout_newsletter', 'help' => 'layouts.checkout_newsletter_help', 'pages' => ['checkout']],
                [...$linkText, 'label' => 'layouts.checkout_newsletter_text', 'pages' => ['checkout']],
                ['key' => 'show_invoice', 'kind' => 'boolean', 'label' => 'layouts.show_invoice', 'help' => 'layouts.show_invoice_help', 'pages' => ['checkout']],
                [...$flag('hide_arrows_mobile'), 'label' => 'layouts.hide_recommendation_arrows_mobile', 'pages' => ['products'], 'tab' => 'product_options']],
            'product_filters' => [['key' => 'visible_options', 'kind' => 'number', 'label' => 'layouts.visible_options', 'min' => 3, 'max' => 50, 'help' => 'layouts.visible_options_help'],
                ['key' => 'show_shopping', 'kind' => 'boolean', 'label' => 'layouts.show_shopping', 'help' => 'layouts.show_shopping_help'],
                ['key' => 'show_delivery', 'kind' => 'boolean', 'label' => 'layouts.show_delivery', 'help' => 'layouts.show_delivery_help'],
                ['key' => 'show_rating', 'kind' => 'boolean', 'label' => 'layouts.show_rating_filter', 'help' => 'layouts.show_rating_filter_help'],
                ['key' => 'show_stock', 'kind' => 'boolean', 'label' => 'layouts.show_stock_filter']],
            // Classic (the original header) or compact: one line with the menu in a drawer opened from the search field.
            'identity' => [['key' => 'header_style', 'kind' => 'select', 'label' => 'layouts.header_layout', 'help' => 'layouts.header_layout_help', 'options' => ['classic', 'compact', 'fashion'], 'option_labels' => ['classic' => 'layouts.header_classic', 'compact' => 'layouts.header_compact', 'fashion' => 'layouts.header_fashion']],
                [...$selection('drawer_menu', 'website_menus', true), 'help' => 'layouts.drawer_menu_help', 'when' => ['header_style' => ['compact', 'fashion']]],
                $flag('show_language_switcher'),
                [...$flag('show_mobile_account_links'), 'tab' => 'mobile_links', 'when' => ['header_style' => 'fashion']],
                // The order of the icons on the right; the same grip and arrow keys as the payment method list.
                ['key' => 'icon_order', 'kind' => 'order', 'label' => 'layouts.icon_order', 'help' => 'layouts.icon_order_help', 'options' => ['language', 'search', 'account', 'call', 'favorites', 'cart'], 'option_labels' => ['language' => 'layouts.icon_language', 'search' => 'layouts.icon_search', 'account' => 'layouts.icon_account', 'call' => 'layouts.icon_call', 'favorites' => 'layouts.icon_favorites', 'cart' => 'layouts.icon_cart']],
                $flag('show_logo'), $flag('show_search'),
                // Product suggestions under the search field while typing (0 turns them off).
                ['key' => 'search_suggestions', 'kind' => 'number', 'label' => 'layouts.search_suggestions', 'help' => 'layouts.search_suggestions_help', 'min' => 0, 'max' => 10], $flag('show_account'), $flag('show_call_request'), $flag('show_favorites'), $flag('show_cart'), $flag('show_mini_cart'),
                ['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.header_background', 'reset_label' => 'layouts.use_store_color'],
                ['key' => 'text_color', 'kind' => 'color', 'label' => 'layouts.header_text_color', 'reset_label' => 'layouts.use_store_color'],
                // Account, favorites and cart icons; empty follows the header text color (white by default).
                ['key' => 'icon_color', 'kind' => 'color', 'label' => 'layouts.header_icon_color', 'reset_label' => 'layouts.use_default_color'], $hiddenPaths],
            'menu' => [$selection('main', 'website_menus', true), $selection('secondary', 'website_menus', true),
                $flag('main_desktop'), $flag('main_tablet'), $flag('main_mobile'), $flag('secondary_desktop'), $flag('secondary_tablet'), $flag('secondary_mobile'), $flag('show_language_switcher'), ['key' => 'dropdown_style', 'kind' => 'select', 'label' => 'layouts.dropdown_style', 'options' => ['cascade', 'directory'], 'option_labels' => ['cascade' => 'layouts.dropdown_cascade', 'directory' => 'layouts.dropdown_directory']], $flag('full_width_dropdown'), $flag('dim_page_on_open'), $flag('open_on_click'),
                ['key' => 'mobile_style', 'kind' => 'select', 'label' => 'layouts.mobile_style', 'options' => ['burger', 'inline'], 'option_labels' => ['burger' => 'layouts.mobile_burger', 'inline' => 'layouts.mobile_inline']],
                ['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.background_color', 'reset_label' => 'layouts.use_default_color'],
                ['key' => 'text_color', 'kind' => 'color', 'label' => 'layouts.text_color', 'reset_label' => 'layouts.use_default_color'], $hiddenPaths],
            'visual_menu' => [[...$selection('menu', 'website_menus', true), 'label' => 'layouts.visual_menu_selection', 'help' => 'layouts.visual_menu_help'], $limit, $navigation, $flag('drag_scroll'), $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $hiddenPaths],
            'link_directory' => [$selection('menu', 'link_menus', true), ['key' => 'columns', 'kind' => 'number', 'label' => 'layouts.columns', 'min' => 2, 'max' => 6],
                $background, $flag('full_width_background'), $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $hiddenPaths],
            'footer' => [$selection('menu', 'link_menus', true), ['key' => 'columns', 'kind' => 'number', 'label' => 'layouts.columns', 'min' => 1, 'max' => 6],
                ['key' => 'mobile_style', 'kind' => 'select', 'label' => 'layouts.footer_mobile', 'help' => 'layouts.footer_mobile_help', 'options' => ['stacked', 'accordion'], 'option_labels' => ['stacked' => 'layouts.footer_stacked', 'accordion' => 'layouts.footer_accordion']],
                $flag('show_social'), $flag('show_apps'), $flag('show_payments'), $flag('show_top_border'),
                ['key' => 'heading_size', 'kind' => 'number', 'label' => 'layouts.footer_heading_size', 'min' => 10, 'max' => 32, 'unit' => 'px'], ['key' => 'text_size', 'kind' => 'number', 'label' => 'layouts.footer_text_size', 'min' => 10, 'max' => 32, 'unit' => 'px'],
                ['key' => 'html', 'kind' => 'richtext', 'label' => 'layouts.footer_bottom'],
                ['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.background_color', 'reset_label' => 'layouts.use_default_color'],
                ['key' => 'text_color', 'kind' => 'color', 'label' => 'layouts.text_color', 'reset_label' => 'layouts.use_default_color'], $hiddenPaths],
            'newsletter' => [$selection('list', 'newsletter_lists', true), ['key' => 'terms_url', 'kind' => 'url', 'label' => 'layouts.terms_url', 'help' => 'layouts.terms_url_help'],
                $background, $flag('full_width_background'), ['key' => 'padding_top', 'kind' => 'number', 'label' => 'layouts.padding_top', 'min' => 0, 'max' => 200, 'unit' => 'px'], ['key' => 'padding_bottom', 'kind' => 'number', 'label' => 'layouts.padding_bottom', 'min' => 0, 'max' => 200, 'unit' => 'px'],
                $titleSize('desktop'), $titleSize('tablet'), $titleSize('mobile'), $titleColor, $hiddenPaths],
            'topbar' => [['key' => 'layout', 'kind' => 'select', 'label' => 'layouts.topbar_layout', 'options' => ['split', 'left', 'right', 'center'], 'option_labels' => ['split' => 'layouts.topbar_split', 'left' => 'layouts.topbar_left_only', 'right' => 'layouts.topbar_right_only', 'center' => 'layouts.topbar_center']],
                ['key' => 'html', 'kind' => 'richtext', 'label' => 'layouts.topbar_content'], ['key' => 'html_right', 'kind' => 'richtext', 'label' => 'layouts.topbar_content_right', 'when' => ['layout' => 'split']],
                $fontSize('desktop'), $fontSize('tablet'), $fontSize('mobile'),
                ['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.background_color', 'reset_label' => 'layouts.use_default_color'],
                ['key' => 'text_color', 'kind' => 'color', 'label' => 'layouts.text_color', 'reset_label' => 'layouts.use_default_color'], $hiddenPaths],
            // White by default; a light logo (the e-mail logo, or the store logo) needs a matching background.
            // Search terms as links to their results: the most searched (minus those staff hide) or the block's own picks.
            'popular_searches' => [['key' => 'source', 'kind' => 'select', 'label' => 'layouts.source', 'options' => ['popular', 'manual']],
                [...$selection('items', 'search_terms'), 'label' => 'layouts.search_terms', 'help' => 'layouts.search_terms_help', 'when' => ['source' => 'manual']], ['key' => 'limit', 'kind' => 'number', 'label' => 'layouts.limit', 'min' => 1, 'max' => 30]],
            'checkout_header' => [['key' => 'background_color', 'kind' => 'color', 'label' => 'layouts.header_background', 'reset_label' => 'layouts.use_default_color'],
                ['key' => 'text_color', 'kind' => 'color', 'label' => 'layouts.header_text_color', 'reset_label' => 'layouts.use_default_color']],
            default => [],
        };

        foreach ($fields as $position => $field) {
            if ($field['key'] === 'navigation') {
                array_splice($fields, $position + 1, 0, [[...$flag('hide_arrows_mobile'), 'carousel_only' => true]]);
                break;
            }
        }

        if ($this->type === 'identity') {
            $options = ['show_logo', 'show_search', 'search_suggestions', 'show_mini_cart', 'background_color', 'text_color', 'icon_color', 'hidden_paths'];
            $fields = array_map(fn (array $field): array => in_array($field['key'], $options, true) ? [...$field, 'tab' => 'identity_options'] : $field, $fields);
        }

        return ['creatable' => ! in_array($this->type, ['identity', 'main_content', 'product_filters', 'page_header', 'checkout_header'], true), 'label' => 'layouts.type_'.$this->type, 'fields' => $fields,
            'defaults' => $this->defaults(),
            'templates' => match ($this->type) {
                'reels' => ['reel_carousel', 'reel_single'], 'coupons' => ['coupon_carousel'], 'offers' => ['offer_carousel'], 'banners' => ['banner_carousel', 'banner_single'], 'products' => ['product_carousel', 'product_single'], 'categories' => ['category_carousel', 'category_single'], 'blog_posts' => ['blog_carousel', 'blog_single'], 'html' => ['html'], 'main_content' => ['main_content'], 'product_filters' => ['product_filters'], 'page_header' => ['page_header'], 'checkout_header' => ['checkout_header'], 'popular_searches' => ['popular_searches'], 'topbar' => ['topbar'], 'menu' => ['menu'], 'visual_menu' => ['menu_tiles_carousel', 'menu_circles_carousel', 'menu_cards_carousel'], 'link_directory' => ['link_directory'], 'footer' => ['footer'], 'newsletter' => ['newsletter'], default => ['identity']
            },
            // The main content block is the page's own content (the product on product pages); it exists only there.
            // `homepage` covers merchant-added layouts, which follow the homepage's rules: the system adds the block when
            // a static page is linked and never lets staff create one, so the homepage itself never has it.
            'pages' => match (true) {
                $this->type === 'main_content' => ['products', 'category_landing', 'category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search', 'cart', 'checkout', 'wishlist', 'account', 'comparison', 'order_tracking', 'sitemap', 'brands', 'blog', 'blog_post', 'homepage'],
                $this->type === 'product_filters' => ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search'],
                // The brand's picture, name and description across the whole page, above the filters and the products.
                $this->type === 'page_header' => ['catalog_landing'],
                // The checkout's own reassuring header: logo, "secure checkout" and the store's phone, instead of the store header.
                $this->type === 'checkout_header' => ['checkout'],
                in_array($this->type, ['identity', 'topbar', 'menu', 'footer'], true) => ['default'],
                default => ['default', 'homepage', 'products', 'category_landing', 'category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search', 'cart', 'checkout', 'wishlist', 'account', 'comparison', 'order_tracking', 'sitemap', 'brands', 'blog', 'blog_post'],
            },
            // Identity and the main content are structural: they can move and be configured, but never removed or switched off.
            // The listing filters can be switched off but not removed, since staff cannot create them again.
            'policy' => ['move' => true, 'disable' => ! in_array($this->type, ['identity', 'main_content', 'page_header'], true), 'delete' => ! in_array($this->type, ['identity', 'main_content', 'product_filters', 'page_header', 'checkout_header'], true), 'configure' => true],
            'ability' => 'layouts.manage'];
    }

    /** Each block type ships sensible sizes; merchants may override them. Blocks saved before a setting existed read these defaults.
     * @return array<string,mixed> */
    private function defaults(): array
    {
        $defaults = match ($this->type) {
            'reels' => ['source' => 'newest', 'limit' => 12, 'per_row' => 6, 'navigation' => 'inside'],
            'banners' => ['source' => 'latest', 'limit' => 3, 'visible_desktop' => 1, 'visible_tablet' => 1, 'height_desktop' => 100, 'height_tablet' => 100, 'autoplay_seconds' => 5, 'navigation' => 'inside', 'navigation_style' => 'arrows', 'drag_scroll' => false, 'infinite_scroll' => false, 'full_width' => false, 'remove_border_radius' => false, 'fill_height' => false, 'preload' => 'none'],
            'products' => ['preserve_manual_products' => true, 'source' => 'newest', 'limit' => 8, 'per_row' => 5, 'show_price' => true, 'show_add_to_cart' => true, 'show_sku' => false, 'show_availability' => false, 'show_rating' => true, 'show_brand' => false, 'show_category' => true, 'show_variations' => true, 'show_favorites' => true, 'card_shadow' => true, 'autoscroll' => false, 'drag_scroll' => false, 'infinite_scroll' => false, 'navigation' => 'inside', 'image_width' => 300, 'image_height' => 260, 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'link_url' => '', 'image_side' => 'left', 'image_fit' => 'contain', 'image_fit_mobile' => 'contain', 'image_link' => ''],
            'coupons', 'offers' => ['limit' => 12, 'per_row' => 4, 'title_icon' => $this->type === 'coupons' ? 'ticket' : 'gift', 'card_shadow' => false, 'autoscroll' => false, 'drag_scroll' => true, 'infinite_scroll' => false, 'navigation' => 'inside', 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'link_url' => ''],
            'blog_posts' => ['source' => 'latest', 'limit' => 6, 'per_row' => 3, 'show_date' => true, 'show_excerpt' => true, 'card_shadow' => false, 'autoscroll' => false, 'drag_scroll' => false, 'infinite_scroll' => false, 'navigation' => 'inside', 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'link_url' => ''],
            'categories' => ['source' => 'manual', 'limit' => 8, 'per_row' => 5, 'rows' => '1', 'card_shadow' => false, 'autoscroll' => false, 'drag_scroll' => false, 'infinite_scroll' => false, 'navigation' => 'inside', 'image_width' => 200, 'image_height' => 1200, 'image_radius' => 16, 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'link_url' => ''],
            'identity' => ['show_mobile_account_links' => true, 'header_style' => 'classic', 'show_language_switcher' => true, 'icon_order' => ['language', 'search', 'account', 'call', 'favorites', 'cart'], 'show_logo' => true, 'show_search' => true, 'search_suggestions' => 6, 'show_account' => true, 'show_call_request' => false, 'show_favorites' => true, 'show_cart' => true, 'show_mini_cart' => true, 'background_color' => '', 'text_color' => '', 'icon_color' => '', 'hidden_paths' => ''],
            'newsletter' => ['terms_url' => '', 'background_color' => '', 'full_width_background' => false, 'padding_top' => 24, 'padding_bottom' => 24, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'hidden_paths' => ''],
            'footer' => ['columns' => 4, 'mobile_style' => 'stacked', 'show_social' => true, 'show_apps' => true, 'show_payments' => true, 'heading_size' => 16, 'text_size' => 15, 'show_top_border' => true, 'background_color' => '', 'text_color' => '', 'hidden_paths' => ''],
            'visual_menu' => ['limit' => 48, 'navigation' => 'inside', 'drag_scroll' => true, 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'hidden_paths' => ''],
            'link_directory' => ['columns' => 6, 'background_color' => '', 'full_width_background' => false, 'title_size_desktop' => 24, 'title_size_tablet' => 22, 'title_size_mobile' => 20, 'title_color' => '', 'hidden_paths' => ''],
            'menu' => ['main_desktop' => true, 'main_tablet' => true, 'main_mobile' => true, 'secondary_desktop' => true, 'secondary_tablet' => true, 'secondary_mobile' => false, 'show_language_switcher' => true, 'dropdown_style' => 'cascade', 'full_width_dropdown' => false, 'dim_page_on_open' => false, 'open_on_click' => false, 'mobile_style' => 'burger', 'background_color' => '', 'text_color' => '', 'hidden_paths' => ''],
            'topbar' => ['layout' => 'split', 'font_size_desktop' => 14, 'font_size_tablet' => 13, 'font_size_mobile' => 12, 'background_color' => '', 'text_color' => '', 'hidden_paths' => ''],
            'main_content' => [...array_fill_keys(self::MAIN_SECTIONS, true), 'newest_same_category' => false, 'section_order' => self::PRODUCT_SECTION_ORDER, ...array_fill_keys(array_map(fn (string $carousel): string => $carousel.'_per_row', self::PRODUCT_CAROUSELS), 5), ...array_fill_keys(array_map(fn (string $carousel): string => $carousel.'_limit', self::PRODUCT_CAROUSELS), 12), 'per_row' => 5, 'card_shadow' => true, 'mobile_display' => 'grid', 'mobile_per_row' => 2, 'products_per_row' => 3, 'mobile_products_per_row' => '1', 'tablet_products_per_row' => '2', 'products_per_page' => 24, 'default_sort' => 'recommended', 'show_sorting' => true, 'show_count' => true, 'show_brand' => false, 'show_category' => false, 'show_variations' => true, 'show_invoice' => true],
            'checkout_header' => ['background_color' => '', 'text_color' => ''],
            'popular_searches' => ['source' => 'popular', 'limit' => 12],
            'product_filters' => ['visible_options' => 8, 'show_shopping' => true, 'show_delivery' => true, 'show_rating' => false, 'show_stock' => false],
            default => [],
        };

        return array_key_exists('navigation', $defaults) || $this->type === 'main_content' ? [...$defaults, 'hide_arrows_mobile' => false] : $defaults;
    }

    /** @param array<string,mixed> $settings
     * @return array<string,mixed> */
    public function validate(array $settings): array
    {
        $rules = [];
        foreach ($this->definition()['fields'] as $field) {
            if ($field['kind'] === 'number') {
                $rules[$field['key']] = ['required', 'integer', 'between:'.$field['min'].','.$field['max']];
            }
            if ($field['kind'] === 'select') {
                $rules[$field['key']] = ['required', Rule::in($field['options'])];
            }
            if ($field['kind'] === 'boolean') {
                $rules[$field['key']] = ['required', 'boolean'];
            }
            if ($field['kind'] === 'order') {
                // Every option exactly once.
                $options = $field['options'];
                $rules[$field['key']] = ['required', 'array', 'size:'.count($options), fn (string $attribute, mixed $value, \Closure $fail) => is_array($value) && array_values(array_unique($value)) === array_values($value) && array_diff($options, $value) === [] ?: $fail(__('layouts.invalid'))];
            }
            if ($field['kind'] === 'url') {
                $rules[$field['key']] = ['nullable', 'string', 'max:2048', fn (string $attribute, mixed $value, \Closure $fail) => ($value === null || $value === '' || MenuRules::safeUrl((string) $value)) ?: $fail(__('layouts.link_url_invalid'))];
            }
            if ($field['kind'] === 'textarea') {
                $rules[$field['key']] = ['nullable', 'string', 'max:1000', fn (string $attribute, mixed $value, \Closure $fail) => app(PathRules::class)->valid((string) $value) ?: $fail(__('layouts.hidden_paths_invalid'))];
            }
            if ($field['kind'] === 'color') {
                // Empty means no background; anything else must be a strict hex color.
                $rules[$field['key']] = ['nullable', 'string', 'regex:/^(#[0-9a-fA-F]{6})?$/D'];
            }
        }
        Validator::make(['settings' => $settings], ['settings' => ['array']])->validate();
        // Banner preloading used to be on/off: on meant the first banner.
        if ($this->type === 'banners' && is_bool($settings['preload'] ?? null)) {
            $settings['preload'] = $settings['preload'] ? 'first' : 'none';
        }
        // Older header orders predate the separate search icon; preserve their positions.
        if ($this->type === 'identity' && is_array($settings['icon_order'] ?? null) && ! in_array('search', $settings['icon_order'], true)) {
            $settings['icon_order'][] = 'search';
        }
        // Only the type's own settings are kept: a key the type does not (or no longer) declare, such as a setting that
        // was removed after blocks were saved with it, is dropped instead of hiding the block or blocking its layout save.
        $settings = array_intersect_key(array_replace($this->defaults(), $settings), $rules);

        $valid = Validator::make($settings, $rules)->validate();
        foreach ($this->definition()['fields'] as $field) {
            if (in_array($field['kind'], ['textarea', 'url'], true)) {
                $valid[$field['key']] = trim((string) ($valid[$field['key']] ?? ''));
            }
            if ($field['kind'] === 'color') {
                $valid[$field['key']] = strtolower((string) ($valid[$field['key']] ?? ''));
            }
        }

        if (array_key_exists('hide_arrows_mobile', $valid)) {
            $valid['hide_arrows_mobile'] = (bool) $valid['hide_arrows_mobile'];
        }

        return $valid;
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string} $context
     * @return array<string,array<string,mixed>> */
    public function resolveMany(array $blocks, array $context): array
    {
        return app(BlockContent::class)->resolve($this->type, $blocks, $context);
    }
}
