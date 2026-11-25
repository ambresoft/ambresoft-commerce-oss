<?php

namespace App\Authorization\Presentation\Console;

use App\Authorization\Infrastructure\PermissionRegistry;
use App\Authorization\Infrastructure\Persistence\PermissionRecord;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SyncPermissions extends Command
{
    protected $signature = 'cms:permissions-sync {--grant-commerce-defaults : Add the reference commerce grants to existing reference roles without removing grants}';

    protected $description = 'Sync enabled capabilities and create missing reference roles; existing grants are preserved';

    /** @var list<string> */
    private const CATALOG_PERMISSIONS = ['products.view', 'products.create', 'products.update', 'products.delete', 'categories.view', 'categories.create', 'categories.update', 'categories.delete'];

    /** @var list<string> */
    private const ORDER_PERMISSIONS = ['orders.view', 'orders.create', 'orders.update', 'orders.status', 'orders.notify', 'orders.customer-ip', 'orders.payments', 'call-requests.view', 'call-requests.manage', 'call-requests.delete'];

    /** @return list<string> */
    private function commercePermissions(string $slug): array
    {
        return match ($slug) {
            // Add-on capabilities run third-party code and stay with super administrators unless granted deliberately.
            'store-admin' => [...self::CATALOG_PERMISSIONS, ...self::ORDER_PERMISSIONS, 'order-statuses.manage', 'order-emails.manage', 'payment-methods.manage', 'shipping-methods.view', 'shipping-methods.manage', 'newsletters.view', 'newsletters.manage', 'newsletters.send', 'subscribers.view', 'subscribers.manage', 'promotions.view', 'promotions.manage', 'coupons.view', 'coupons.manage', 'coupons.delete', 'giftcards.view', 'giftcards.manage', 'giftcards.delete', 'reports.view', 'reports.customers', 'reports.export'],
            'catalog-manager' => self::CATALOG_PERMISSIONS,
            'order-manager' => self::ORDER_PERMISSIONS,
            'content-manager' => ['categories.view', 'categories.update'],
            default => [],
        };
    }

    public function handle(PermissionRegistry $registry): int
    {
        DB::transaction(function () use ($registry): void {
            DB::select('SELECT pg_advisory_xact_lock(2142512)');
            foreach (array_keys($registry->all()) as $name) {
                PermissionRecord::firstOrCreate(['name' => $name]);
            }
            foreach (['super-admin' => 'Super Admin', 'store-admin' => 'Store Admin', 'catalog-manager' => 'Catalog Manager', 'order-manager' => 'Order Manager', 'content-manager' => 'Content Manager'] as $slug => $name) {
                $role = RoleRecord::where('slug', $slug)->first();
                if ($role !== null) {
                    if ($this->option('grant-commerce-defaults')) {
                        $role->permissions()->syncWithoutDetaching(PermissionRecord::whereIn('name', $this->commercePermissions($slug))->pluck('id'));
                    }

                    continue;
                }
                $role = new RoleRecord(['name' => $name, 'slug' => $slug]);
                $role->is_super_admin = $slug === 'super-admin';
                $role->save();
                $abilities = $slug === 'store-admin' ? ['admin.access', 'users.view', 'roles.view'] : ['admin.access'];
                $role->permissions()->sync(PermissionRecord::whereIn('name', [...$abilities, ...$this->commercePermissions($slug)])->pluck('id'));
            }
        });
        $this->info($this->option('grant-commerce-defaults') ? 'Permissions synchronized. Reference commerce grants added; existing grants preserved.' : 'Permissions synchronized. Existing role grants were not changed.');

        return self::SUCCESS;
    }
}
