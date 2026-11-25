<?php

namespace App\Authorization\Infrastructure;

use App\Authorization\Application\Contract\StaffAdministrationInterface;
use App\Authorization\Infrastructure\Persistence\EloquentStaffAdministration;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
        $this->app->make(PermissionRegistry::class)->add('theme-code.manage', 'code_editor.permission');
        foreach (['view', 'manage', 'analytics'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('reels.'.$action, 'reels.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('brands.'.$action, 'brands.permission_'.$action);
        }

        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('layouts.'.$action, 'layouts.permission_'.$action);
        }
        $this->app->make(PermissionRegistry::class)->add('account-security.view', 'account_security.permission');
        $this->app->make(PermissionRegistry::class)->add('audit.view', 'audit.permission');
        $this->app->make(PermissionRegistry::class)->add('brands.import', 'brand_import.permission');
        $this->app->make(PermissionRegistry::class)->add('products.import', 'product_import.permission');
        $this->app->make(PermissionRegistry::class)->add('categories.import', 'permissions.categories.import');
        $this->app->make(PermissionRegistry::class)->add('languages.manage', 'languages.permission');
        $this->app->make(PermissionRegistry::class)->add('cache.manage', 'cache.permission');
        $this->app->make(PermissionRegistry::class)->add('search.manage', 'search.permission');
        $this->app->make(PermissionRegistry::class)->add('settings.manage', 'settings.permission');
        $this->app->make(PermissionRegistry::class)->add('settings.brand-landing-pages', 'settings.landing_source_permission');
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('menus.'.$action, 'menus.permission_'.$action);
        }
        foreach (['status', 'trash-view', 'restore', 'force-delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('products.'.$action, 'products.permission_'.$action);
        }
        foreach (['view', 'create', 'update', 'status', 'delete', 'groups', 'order'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('features.'.$action, 'features.permission_'.$action);
        }
        foreach (['view', 'create', 'update', 'rates', 'status', 'primary', 'delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('currencies.'.$action, 'currencies.permission_'.$action);
        }
        foreach (['view', 'create', 'update', 'delete', 'assign'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('taxes.'.$action, 'taxes.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('rate-areas.'.$action, 'taxes.permission_area_'.$action);
        }
        foreach (['view', 'create', 'update', 'status'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('customers.'.$action, 'customers.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('customer-groups.'.$action, 'customers.permission_groups_'.$action);
            $this->app->make(PermissionRegistry::class)->add('customer-blacklist.'.$action, 'customers.permission_blacklist_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('countries.'.$action, 'geography.permission_countries_'.$action);
            $this->app->make(PermissionRegistry::class)->add('regions.'.$action, 'geography.permission_regions_'.$action);
            $this->app->make(PermissionRegistry::class)->add('banners.'.$action, 'banners.permission_'.$action);
            $this->app->make(PermissionRegistry::class)->add('pages.'.$action, 'pages.permission_'.$action);
            $this->app->make(PermissionRegistry::class)->add('blog.'.$action, 'blogs.permission_'.$action);
            $this->app->make(PermissionRegistry::class)->add('seo-rules.'.$action, 'seo_rules.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('promotions.'.$action, 'promotions.permission_'.$action);
            $this->app->make(PermissionRegistry::class)->add('stickers.'.$action, 'stickers.permission_'.$action);
        }
        foreach (['view', 'moderate', 'edit', 'delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('reviews.'.$action, 'reviews.permission_'.$action);
        }
        foreach (['view', 'manage', 'delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('coupons.'.$action, 'coupons.permission_'.$action);
        }
        foreach (['view', 'manage', 'delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('giftcards.'.$action, 'giftcards.permission_'.$action);
        }
        foreach (['view', 'manage', 'send', 'generate'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('newsletters.'.$action, 'newsletters.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('subscribers.'.$action, 'newsletters.permission_subscribers_'.$action);
        }
        foreach (['view', 'manage', 'generate'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('faqs.'.$action, 'faqs.permission_'.$action);
        }
        $this->app->make(PermissionRegistry::class)->add('banners.generate', 'banners.permission_generate');
        $this->app->make(PermissionRegistry::class)->add('blog.generate', 'blogs.permission_generate');
        $this->app->make(PermissionRegistry::class)->add('carts.view', 'carts.permission_view');
        $this->app->make(PermissionRegistry::class)->add('ai.logs.view', 'ai.permission_logs');
        $this->app->make(PermissionRegistry::class)->add('availability-statuses.manage', 'availability.permission');
        foreach (['create', 'status', 'notify', 'customer-ip', 'payments', 'shipments'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('orders.'.$action, 'orders.permission_'.$action);
        }
        foreach (['view', 'manage', 'delete'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('call-requests.'.$action, 'calls.permission_'.$action);
        }
        $this->app->make(PermissionRegistry::class)->add('order-statuses.manage', 'orders.permission_statuses');
        $this->app->make(PermissionRegistry::class)->add('order-emails.manage', 'orders.permission_emails');
        $this->app->make(PermissionRegistry::class)->add('payment-methods.manage', 'payments.permission_manage');
        $this->app->make(PermissionRegistry::class)->add('shipping-methods.view', 'shipping.permission_view');
        $this->app->make(PermissionRegistry::class)->add('shipping-methods.manage', 'shipping.permission_manage');
        $this->app->make(PermissionRegistry::class)->add('shipping-carriers.manage', 'shipping.permission_carriers');
        foreach (['view', 'customers', 'export'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('reports.'.$action, 'reports.permission_'.$action);
        }
        foreach (['view', 'manage'] as $action) {
            $this->app->make(PermissionRegistry::class)->add('social-login.'.$action, 'social_login.permission_'.$action);
        }
        $this->app->make(PermissionRegistry::class)->add('addons.manage', 'addons.permission_manage');
        $this->app->make(PermissionRegistry::class)->add('addons.upload', 'addons.permission_upload');
        $this->app->bind(StaffAdministrationInterface::class, EloquentStaffAdministration::class);
        foreach (['admin.access', 'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.set-password', 'users.assign-roles', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign-permissions', 'products.view', 'products.create', 'products.update', 'products.delete', 'categories.view', 'categories.create', 'categories.update', 'categories.delete', 'orders.view', 'orders.update'] as $ability) {
            $this->app->make(PermissionRegistry::class)->add($ability, 'permissions.'.$ability);
        }
    }

    public function boot(AccessChecker $access): void
    {
        // Delayed until every enabled module has contributed its capabilities.
        $this->app->booted(function () use ($access): void {
            foreach (array_keys($this->app->make(PermissionRegistry::class)->all()) as $ability) {
                Gate::define($ability, fn (UserRecord $user): bool => $access->allows($user, $ability));
            }
            // Administration also needs a password that meets the staff minimum (customers may use shorter ones, A99).
            Gate::define('access-admin', fn (UserRecord $user): bool => $user->staff_password !== false && $access->allows($user, 'admin.access'));
        });
    }
}
