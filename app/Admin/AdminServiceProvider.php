<?php

namespace App\Admin;

use App\Admin\DataTransfer\DataTransferCatalog;
use App\Admin\DataTransfer\DataTransferExports;
use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Infrastructure\Export\BrandCsvExport;
use App\Catalog\Infrastructure\Export\CategoryCsvExport;
use App\Catalog\Infrastructure\Export\ProductCsvExport;
use App\Geography\Infrastructure\Export\RegionCsvExport;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdminNavigation::class);
        $this->app->singleton(DataTransferCatalog::class);
        $this->app->singleton(DataTransferExports::class);
    }

    public function boot(AdminNavigation $navigation, DataTransferCatalog $transfers, DataTransferExports $exports): void
    {
        $transfers->add('core.brands-import', 'import', 'brand_import.title', 'brand_import.intro', 'admin.brands.import.index', 'brands.import');
        $transfers->add('core.products-import', 'import', 'product_import.title', 'product_import.intro', 'admin.products.import.index', 'products.import');
        $transfers->add('core.categories-import', 'import', 'category_import.title', 'admin.data_transfer.categories_import', 'admin.categories.import.index', 'categories.import');
        $transfers->add('core.regions-import', 'import', 'region_import.title', 'region_import.intro', 'admin.regions.import.index', 'regions.manage');
        // Exports are listed for the staff who may run the matching import, so hub visibility is unchanged.
        foreach ([
            'products' => [ProductCsvExport::class, 'products.import'], 'categories' => [CategoryCsvExport::class, 'categories.import'],
            'brands' => [BrandCsvExport::class, 'brands.import'], 'regions' => [RegionCsvExport::class, 'regions.manage'],
        ] as $entity => [$source, $ability]) {
            $exports->add($entity, $source);
            $transfers->add('core.'.$entity.'-export', 'export', 'admin.data_transfer.export_'.$entity, 'admin.data_transfer.export_catalog', 'admin.data-transfer.export.show', $ability, ['entity' => $entity]);
        }
        // Visible to staff who may run at least one listed import or export.
        Gate::define('data-transfer.view', fn (User $user): bool => $transfers->forUser($user) !== []);
        // Carriers are listed for staff who configure them or work with their shipments and pickup lists.
        Gate::define('shipping-carriers.view', fn (User $user): bool => Gate::forUser($user)->any(['shipping-carriers.manage', 'orders.shipments']));

        // Customer reports need the reports capability and the right to see customers themselves.
        Gate::define('reports.customers-view', fn (User $user): bool => Gate::forUser($user)->allows('reports.customers') && Gate::forUser($user)->allows('customers.view'));
        Gate::define('reports.orders-view', fn (User $user): bool => Gate::forUser($user)->allows('reports.view') && Gate::forUser($user)->allows('orders.view'));
        $navigation->addGroup('core.reports', 'reports.title');
        $navigation->add('core.reports-overview', 'reports.overview_title', 'admin.reports.overview', 'reports.view', 35, 'core.reports');
        $navigation->add('core.reports-sales', 'reports.sales_title', 'admin.reports.sales', 'reports.view', 36, 'core.reports');
        $navigation->add('core.reports-orders', 'reports.orders_title', 'admin.reports.orders', 'reports.orders-view', 37, 'core.reports');
        $navigation->add('core.reports-products', 'reports.products_title', 'admin.reports.products', 'reports.view', 38, 'core.reports');
        $navigation->add('core.reports-categories', 'reports.categories_title', 'admin.reports.categories', 'reports.view', 39, 'core.reports');
        $navigation->add('core.reports-brands', 'reports.brands_title', 'admin.reports.brands', 'reports.view', 39, 'core.reports');
        $navigation->add('core.reports-customers', 'reports.customers_title', 'admin.reports.customers', 'reports.customers-view', 40, 'core.reports');
        $navigation->add('core.reports-inventory', 'reports.inventory_title', 'admin.reports.inventory', 'reports.view', 41, 'core.reports');
        $navigation->add('core.reports-promotions', 'reports.promotions_title', 'admin.reports.promotions', 'reports.view', 42, 'core.reports');
        $navigation->add('core.search-terms', 'search.terms_title', 'admin.search-terms.index', 'search.manage', 43, 'core.reports');
        $navigation->addGroup('core.marketing', 'navigation.marketing');
        $navigation->add('core.stickers', 'stickers.title', 'admin.stickers.index', 'stickers.view', 56, 'core.marketing');
        $navigation->addGroup('core.newsletter-menu', 'newsletters.menu_title', 'core.marketing');
        // Blog: the blogs (landing pages) and their posts, together under Marketing.
        $navigation->addGroup('core.blog-menu', 'blogs.title', 'core.marketing');
        $navigation->add('core.blogs', 'blogs.list_title', 'admin.blogs.index', 'blog.view', 61, 'core.blog-menu', ['admin.blogs.*']);
        $navigation->add('core.blog-posts', 'blogs.posts_title', 'admin.blog-posts.index', 'blog.view', 62, 'core.blog-menu', ['admin.blog-posts.*']);
        $navigation->add('core.promotions', 'promotions.title', 'admin.promotions.index', 'promotions.view', 54, 'core.marketing', ['admin.promotions.*']);
        $navigation->add('core.coupons', 'coupons.title', 'admin.coupons.index', 'coupons.view', 54, 'core.marketing', ['admin.coupons.*']);
        $navigation->add('core.gift-cards', 'giftcards.title', 'admin.gift-cards.index', 'giftcards.view', 55, 'core.marketing', ['admin.gift-cards.*']);
        $navigation->add('core.reels', 'reels.title', 'admin.reels.index', 'reels.view', 56, 'core.marketing');
        $navigation->add('core.banners', 'navigation.banners', 'admin.banners.index', 'banners.view', 55, 'core.marketing');
        $navigation->add('core.newsletters', 'newsletters.newsletters_title', 'admin.newsletters.index', 'newsletters.view', 56, 'core.newsletter-menu', ['admin.newsletters.*']);
        $navigation->add('core.newsletter-templates', 'newsletters.templates_title', 'admin.newsletter-templates.index', 'newsletters.view', 57, 'core.newsletter-menu', ['admin.newsletter-templates.*']);
        $navigation->add('core.newsletter-autoresponders', 'newsletters.autoresponders_title', 'admin.newsletter-autoresponders.index', 'newsletters.view', 58, 'core.newsletter-menu', ['admin.newsletter-autoresponders.*']);
        $navigation->add('core.newsletter-campaigns', 'newsletters.campaigns_title', 'admin.newsletter-campaigns.index', 'newsletters.view', 59, 'core.newsletter-menu', ['admin.newsletter-campaigns.*']);
        $navigation->add('core.newsletter-lists', 'newsletters.lists_title', 'admin.newsletter-lists.index', 'newsletters.view', 60, 'core.newsletter-menu', ['admin.newsletter-lists.*']);
        $navigation->add('core.newsletter-subscribers', 'newsletters.subscribers_title', 'admin.newsletter-subscribers.index', 'subscribers.view', 61, 'core.newsletter-menu', ['admin.newsletter-subscribers.*']);
        $navigation->addGroup('core.website', 'navigation.website');
        $navigation->add('core.code-editor', 'code_editor.title', 'admin.code-editor.index', 'theme-code.manage', 64, 'core.website');
        $navigation->add('core.menus', 'menus.title', 'admin.menus.index', 'menus.view', 60, 'core.website');
        $navigation->add('core.layouts', 'layouts.title', 'admin.layouts.index', 'layouts.view', 60, 'core.website', ['admin.layouts.*']);
        $navigation->add('core.faqs', 'faqs.title', 'admin.faqs.index', 'faqs.view', 62, 'core.website');
        $navigation->add('core.pages', 'pages.title', 'admin.pages.index', 'pages.view', 61, 'core.website');
        $navigation->add('core.seo-rules', 'seo_rules.title', 'admin.seo-rules.index', 'seo-rules.view', 63, 'core.website', ['admin.seo-rules.*']);
        $navigation->add('core.social-login', 'social_login.title', 'admin.social-login.index', 'social-login.view', 63, 'core.website', ['admin.social-login.*']);
        $navigation->addGroup('core.shipping-taxes', 'navigation.shipping_taxes');
        $navigation->add('core.shipping-methods', 'shipping.title', 'admin.shipping-methods.index', 'shipping-methods.view', 65, 'core.shipping-taxes', ['admin.shipping-methods.*']);
        $navigation->add('core.shipping-carriers', 'shipping.carriers_title', 'admin.shipping-carriers.index', 'shipping-carriers.view', 69, 'core.shipping-taxes', ['admin.shipping-carriers.*']);
        $navigation->add('core.taxes', 'taxes.title', 'admin.taxes.index', 'taxes.view', 65, 'core.shipping-taxes');
        $navigation->add('core.countries', 'geography.countries_title', 'admin.countries.index', 'countries.view', 66, 'core.shipping-taxes');
        $navigation->add('core.regions', 'geography.regions_title', 'admin.regions.index', 'regions.view', 67, 'core.shipping-taxes');
        $navigation->add('core.rate-areas', 'taxes.areas_title', 'admin.rate-areas.index', 'rate-areas.view', 68, 'core.shipping-taxes');
        $navigation->addGroup('core.logs', 'navigation.logs');
        $navigation->add('core.audit-log', 'audit.title', 'admin.audit.index', 'audit.view', 75, 'core.logs');
        $navigation->add('core.ai-log', 'ai.log', 'admin.settings.ai-log', 'ai.logs.view', 76, 'core.logs');
        $navigation->add('core.dashboard', 'navigation.dashboard', 'admin.dashboard', 'access-admin', 0);
        $navigation->addGroup('core.orders', 'orders.title');
        $navigation->add('core.order-list', 'orders.all', 'admin.orders.index', 'orders.view', 5, 'core.orders');
        $navigation->add('core.call-requests', 'calls.title', 'admin.call-requests.index', 'call-requests.view', 6, 'core.orders');
        $navigation->add('core.order-statuses', 'orders.statuses_title', 'admin.order-statuses.index', 'order-statuses.manage', 7, 'core.orders');
        $navigation->add('core.order-emails', 'orders.emails_nav', 'admin.order-emails.index', 'order-emails.manage', 8, 'core.orders');
        $navigation->add('core.order-files', 'orders.files_nav', 'admin.order-files.index', 'order-emails.manage', 9, 'core.orders', ['admin.order-files.*']);
        $navigation->add('core.payment-methods', 'payments.title', 'admin.payment-methods.index', 'payment-methods.manage', 64);
        // Catalog is the home of everything the store sells; Products keeps its own nested group of related screens.
        $navigation->addGroup('core.catalog', 'navigation.catalog');
        $navigation->addGroup('core.catalog-products', 'products.title', 'core.catalog');
        $navigation->add('core.products', 'products.title', 'admin.products.index', 'products.view', 10, 'core.catalog-products');
        $navigation->add('core.features', 'features.title', 'admin.features.index', 'features.view', 11, 'core.catalog-products');
        $navigation->add('core.product-packages', 'packages.title', 'admin.product-packages.index', 'products.view', 13, 'core.catalog-products');
        $navigation->add('core.variation-groups', 'variations.title', 'admin.variation-groups.index', 'products.view', 12, 'core.catalog-products');
        $navigation->add('core.availability-statuses', 'availability.title', 'admin.availability-statuses.index', 'availability-statuses.manage', 13, 'core.catalog-products');
        $navigation->add('core.categories', 'categories.title', 'admin.categories.index', 'categories.view', 20, 'core.catalog');
        $navigation->add('core.brands', 'brands.title', 'admin.brands.index', 'brands.view', 21, 'core.catalog');
        $navigation->add('core.reviews', 'reviews.title', 'admin.reviews.index', 'reviews.view', 22, 'core.catalog');
        $navigation->add('core.carts', 'carts.title', 'admin.carts.index', 'carts.view', 23, 'core.catalog', ['admin.carts.*']);
        $navigation->addGroup('core.customers', 'customers.title');
        $navigation->add('core.customer-list', 'customers.all', 'admin.customers.index', 'customers.view', 25, 'core.customers');
        $navigation->add('core.customer-groups', 'customers.groups_title', 'admin.customer-groups.index', 'customer-groups.view', 26, 'core.customers');
        $navigation->add('core.customer-blacklist', 'customers.blacklist_title', 'admin.customer-blacklist.index', 'customer-blacklist.view', 27, 'core.customers');
        $navigation->addGroup('core.access', 'navigation.users_menu');
        $navigation->add('core.users', 'navigation.users', 'admin.users.index', 'users.view', 30, 'core.access');
        $navigation->add('core.roles', 'navigation.roles', 'admin.roles.index', 'roles.view', 31, 'core.access');
        $navigation->add('core.data-transfer', 'navigation.data_transfer', 'admin.data-transfer.index', 'data-transfer.view', 80, null, ['admin.categories.import.*', 'admin.regions.import.*']);
        $navigation->add('core.addons', 'navigation.addons', 'admin.addons.index', 'addons.manage', 70);
        $navigation->add('core.cache', 'cache.title', 'admin.cache.index', 'cache.manage', 71);
        $navigation->addGroup('core.settings', 'languages.settings');
        $navigation->add('core.company-settings', 'company.title', 'admin.settings.company', 'settings.manage', 88, 'core.settings');
        $navigation->add('core.general-settings', 'settings.title', 'admin.settings.general', 'settings.manage', 89, 'core.settings');
        $navigation->add('core.search-settings', 'search.title', 'admin.settings.search', 'search.manage', 89, 'core.settings');
        $navigation->add('core.languages', 'languages.title', 'admin.settings.languages', 'languages.manage', 90, 'core.settings');
        $navigation->add('core.translations', 'languages.translations', 'admin.settings.translations', 'languages.manage', 91, 'core.settings');
        $navigation->add('core.currencies', 'currencies.title', 'admin.settings.currencies.index', 'currencies.view', 92, 'core.settings');
    }
}
