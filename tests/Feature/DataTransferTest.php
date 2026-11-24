<?php

namespace Tests\Feature;

use App\Admin\DataTransfer\DataTransferCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class DataTransferTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    public function test_hub_lists_only_transfers_the_staff_member_may_run(): void
    {
        $this->actingAs($this->staff(['admin.access', 'categories.view', 'categories.import']))
            ->get('/admin/data-transfer')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/DataTransfer/Index')
                ->has('transfers', 2)
                ->where('transfers.0.id', 'core.categories-import')
                ->where('transfers.0.kind', 'import')
                ->where('transfers.0.href', '/admin/categories/import')
                ->where('transfers.1.id', 'core.categories-export')
                ->where('transfers.1.kind', 'export')
                ->where('transfers.1.href', '/admin/data-transfer/export/categories')
                ->where('navigation', fn ($items) => collect($items)->firstWhere('id', 'core.data-transfer')['active'] === true));
    }

    public function test_hub_and_its_navigation_entry_require_a_transfer_capability(): void
    {
        $this->actingAs($this->staff(['admin.access', 'categories.view']));
        $this->get('/admin/data-transfer')->assertForbidden();
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('navigation', fn ($items) => collect($items)->doesntContain('id', 'core.data-transfer')));
    }

    public function test_category_import_is_highlighted_under_imports_not_categories(): void
    {
        $this->actingAs($this->staff(['admin.access', 'categories.view', 'categories.import']))
            ->get('/admin/categories/import')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation', fn ($items) => collect($items)->where('active', true)->pluck('id')->all() === ['core.data-transfer'])
                ->where('breadcrumbs.1.href', '/admin/data-transfer'));
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page
            ->where('navigation', fn ($items) => collect($items)->where('active', true)->pluck('id')->all() === ['core.categories']));
    }

    public function test_staff_with_both_import_capabilities_see_both_transfers(): void
    {
        $this->actingAs($this->staff(['admin.access', 'categories.import', 'regions.manage']))
            ->get('/admin/data-transfer')->assertInertia(fn (Assert $page) => $page
            ->has('transfers', 4)->where('transfers.0.id', 'core.categories-import')->where('transfers.1.id', 'core.regions-import')->where('transfers.2.id', 'core.categories-export')->where('transfers.3.id', 'core.regions-export'));
    }

    public function test_transfers_with_missing_routes_are_rejected(): void
    {
        $catalog = new DataTransferCatalog;
        $catalog->add('test.export', 'export', 'Export', 'Description', 'admin.missing', 'access-admin');
        $this->expectException(LogicException::class);
        $catalog->forUser($this->staff(['admin.access']));
    }
}
