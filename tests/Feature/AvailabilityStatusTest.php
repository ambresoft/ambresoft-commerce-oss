<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\AvailabilityStatuses;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductAvailability;
use App\Catalog\Infrastructure\ProductQueries;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AvailabilityStatusTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function data(string $name = 'Ships tomorrow'): array
    {
        return ['position' => 70, 'default_in_stock' => false, 'default_out_of_stock' => false, 'translations' => ['en' => ['name' => $name]]];
    }

    private function product(array $changes = []): ProductRecord
    {
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $category->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->value('id'), 'name' => 'Test', 'slug' => 'test']);
        $products = app(ProductAdministration::class);
        $id = $products->draft((string) $this->actor->id);
        $p = ProductRecord::findOrFail($id);
        $products->save((string) $this->actor->id, $id, new SaveProductData($p->lock_version, [...['sku' => 'AV-'.$id, 'type' => 'physical', 'status' => 'disabled', 'price' => '10', 'category_id' => (string) $category->id, 'translations' => ['en' => ['name' => 'Product']], 'quantity' => 5], ...$changes]));

        return $p->fresh();
    }

    public function test_six_editable_labels_are_localized_and_product_overrides_save_copy_and_resolve(): void
    {
        self::assertCount(6, app(AvailabilityStatuses::class)->options());
        $id = app(AvailabilityStatuses::class)->save((string) $this->actor->id, null, $this->data());
        $p = $this->product(['in_stock_status_id' => $id, 'out_of_stock_status_id' => '2']);
        self::assertSame('Ships tomorrow', app(ProductAvailability::class)->forProduct($p)['name']);
        self::assertSame($id, app(ProductQueries::class)->editor($p)['in_stock_status_id']);
        $copy = app(ProductAdministration::class)->duplicate((string) $this->actor->id, (string) $p->id, $p->lock_version);
        self::assertSame((int) $id, ProductRecord::findOrFail($copy)->in_stock_status_id);
        $p->quantity = 0;
        $p->save();
        self::assertSame('Awaiting supplier', app(ProductAvailability::class)->forProduct($p)['name']);
        DB::table('general_settings')->where('id', 1)->update(['track_inventory' => false]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame('Ships tomorrow', app(ProductAvailability::class)->forProduct($p)['name']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'availability_status')->exists());
    }

    public function test_statuses_require_every_active_language_and_preserve_inactive_translations(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $this->post('/admin/availability-statuses', $this->data())->assertSessionHasErrors('translations.el');
        $this->post('/admin/availability-statuses', [...$this->data(), 'translations' => ['en' => ['name' => 'Tomorrow'], 'el' => ['name' => 'Αύριο']]])->assertSessionHasNoErrors();
        $id = (string) DB::table('availability_statuses')->max('id');
        LocaleRecord::where('code', 'el')->update(['is_active' => false]);
        app(AvailabilityStatuses::class)->save((string) $this->actor->id, $id, ['version' => 1, ...$this->data('Next day')]);
        self::assertSame('Αύριο', DB::table('availability_status_translations')->where('status_id', $id)->where('locale_id', LocaleRecord::where('code', 'el')->value('id'))->value('name'));
    }

    public function test_default_changes_and_deletion_reassign_references_atomically_and_invalidate_editors(): void
    {
        $statuses = app(AvailabilityStatuses::class);
        $id = $statuses->save((string) $this->actor->id, null, [...$this->data(), 'default_in_stock' => true]);
        self::assertSame($id, (string) DB::table('availability_statuses')->where('default_in_stock', true)->value('id'));
        $p = $this->product(['in_stock_status_id' => $id, 'out_of_stock_status_id' => $id]);
        $version = $p->lock_version;
        $this->delete('/admin/availability-statuses/'.$id, ['version' => 1])->assertSessionHasErrors('replacement');
        self::assertSame($version, $p->fresh()->lock_version);
        $this->delete('/admin/availability-statuses/'.$id, ['version' => 1, 'replacement' => '3'])->assertSessionHasNoErrors()->assertRedirect('/admin/availability-statuses');
        $p->refresh();
        self::assertSame(3, $p->in_stock_status_id);
        self::assertSame(3, $p->out_of_stock_status_id);
        self::assertSame(5, $p->quantity);
        self::assertSame($version + 1, $p->lock_version);
        self::assertTrue((bool) DB::table('availability_statuses')->where('id', 3)->value('default_in_stock'));
        self::assertFalse(DB::table('availability_statuses')->where('id', $id)->exists());
    }

    public function test_permissions_versions_defaults_and_database_restrictions_are_enforced(): void
    {
        $viewer = $this->staff(['admin.access', 'products.update']);
        $this->actingAs($viewer)->get('/admin/availability-statuses')->assertForbidden();
        $this->post('/admin/availability-statuses', $this->data())->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->actor);
        $this->put('/admin/availability-statuses/1', ['version' => 9, ...$this->data()])->assertSessionHasErrors('version');
        $this->put('/admin/availability-statuses/1', ['version' => 1, ...$this->data()])->assertSessionHasErrors('default_in_stock');
        $p = $this->product(['in_stock_status_id' => '3']);
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.in_stock_status_id', '3')->has('availabilityStatuses', 6));
        $this->expectException(QueryException::class);
        DB::table('availability_statuses')->where('id', 3)->delete();
    }

    public function test_invalid_product_ids_are_rejected_and_omitted_fields_keep_overrides(): void
    {
        $p = $this->product(['in_stock_status_id' => '3', 'out_of_stock_status_id' => '5']);
        $fields = ['sku' => $p->sku, 'type' => 'physical', 'status' => 'disabled', 'price' => '10', 'category_id' => (string) $p->category_id, 'translations' => ['en' => ['name' => 'Product']]];
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->lock_version, $fields));
        self::assertSame(3, $p->fresh()->in_stock_status_id);
        try {
            app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->fresh()->lock_version, [...$fields, 'in_stock_status_id' => '999999']));
            self::fail('Invalid status accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('in_stock_status_id', $e->errors());
        }
    }
}
