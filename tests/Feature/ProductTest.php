<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Service\ProductDeletionGuards;
use App\Catalog\Infrastructure\Jobs\CleanupProductFile;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductCategories;
use App\Catalog\Infrastructure\ProductFiles;
use App\Catalog\Infrastructure\ProductQueries;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Application\Command\ManageLanguagesHandler;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    private ProductAdministration $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Leaf', 'slug' => 'leaf']);
        $this->products = app(ProductAdministration::class);
    }

    private function draft(): ProductRecord
    {
        return ProductRecord::findOrFail($this->products->draft((string) $this->actor->id));
    }

    public function test_sitemap_exclusion_saves_with_product_validates_and_preserves_omitted_values(): void
    {
        $product = $this->draft();
        self::assertFalse($product->exclude_from_sitemap);
        $this->post('/admin/products/'.$product->id, [...$this->fields($product), 'exclude_from_sitemap' => true])->assertSessionHasNoErrors();
        self::assertTrue($product->fresh()->exclude_from_sitemap);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.exclude_from_sitemap', true));
        $this->post('/admin/products/'.$product->id, $this->fields($product->fresh()))->assertSessionHasNoErrors();
        self::assertTrue($product->fresh()->exclude_from_sitemap);
        $this->post('/admin/products/'.$product->id, [...$this->fields($product->fresh()), 'exclude_from_sitemap' => 'invalid'])->assertSessionHasErrors('exclude_from_sitemap');
        self::assertTrue($product->fresh()->exclude_from_sitemap);
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->fresh()->lock_version));
        self::assertTrue($copy->exclude_from_sitemap);
        $this->post('/admin/products/'.$product->id, [...$this->fields($product->fresh()), 'exclude_from_sitemap' => false])->assertSessionHasNoErrors();
        self::assertFalse($product->fresh()->exclude_from_sitemap);
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'products.view']))->post('/admin/products/'.$product->id, [...$this->fields($product->fresh()), 'exclude_from_sitemap' => true])->assertForbidden();
        self::assertFalse($product->fresh()->exclude_from_sitemap);
    }

    /** @return array<string, mixed> */
    private function fields(ProductRecord $p, string $name = 'Product'): array
    {
        return ['version' => $p->lock_version, 'sku' => 'SKU-'.$p->id, 'type' => 'physical', 'status' => 'disabled', 'price' => '0', 'category_id' => (string) $this->category->id, 'translations' => ['en' => ['name' => $name]], 'images' => [], 'primary_image' => null, 'files' => []];
    }

    /** @param array<string, mixed> $changes */
    private function save(ProductRecord $p, array $changes = []): ProductRecord
    {
        $this->products->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->lock_version, array_replace($this->fields($p), $changes)));

        return $p->fresh();
    }

    /** @param callable(): void $operation */
    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_sale_customer_group_is_optional_in_storage_projection_and_http_saves(): void
    {
        $row = ['minimum_quantity' => 1, 'priority' => 1, 'kind' => 'fixed', 'amount' => '20', 'stock_limit' => null];
        // No customer group is configured or supplied at all.
        $product = $this->save($this->draft(), ['price' => '100', 'sales' => [$row]]);
        $sale = DB::table('product_sales')->where('product_id', $product->id)->sole();
        self::assertNull($sale->customer_group_id);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.sales.0.customer_group_id', ''));
        $this->post('/admin/products/'.$product->id, [...$this->fields($product), 'price' => '100', 'sales_submitted' => true, 'sales' => [[...$row, 'id' => (string) $sale->id, 'customer_group_id' => '']]])->assertSessionHasNoErrors()->assertRedirect();
        self::assertNull(DB::table('product_sales')->where('id', $sale->id)->value('customer_group_id'));
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->fresh()->lock_version));
        self::assertNull(DB::table('product_sales')->where('product_id', $copy->id)->sole()->customer_group_id);
    }

    public function test_sale_rows_save_with_the_product_and_validate_ownership_amounts_dates_and_versions(): void
    {
        DB::table('customer_groups')->insert(['status' => 'active', 'is_default' => true]);
        $row = ['id' => '', 'customer_group_id' => (string) DB::table('customer_groups')->where('is_default', true)->value('id'), 'minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '30', 'stock_limit' => null, 'starts_on' => null, 'ends_on' => '2027-01-01'];
        $product = $this->save($this->draft(), ['sales' => [$row]]);
        $sale = DB::table('product_sales')->where('product_id', $product->id)->sole();
        self::assertSame(['percentage', '30.000000', null], [$sale->kind, $sale->amount, $sale->stock_limit]);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.sales.0.amount', '30')->where('product.sales.0.stock_limit', '')->has('saleCustomerGroups'));
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->lock_version));
        self::assertSame('30.000000', DB::table('product_sales')->where('product_id', $copy->id)->value('amount'));
        self::assertSame(0, $copy->quantity);
        foreach (['amount' => '101', 'customer_group_id' => '999999', 'stock_limit' => -1, 'minimum_quantity' => 0, 'ends_on' => '2025-01-01'] as $field => $value) {
            $this->invalid(fn () => $this->save($product, ['sales' => [[...$row, 'id' => (string) $sale->id, 'starts_on' => '2026-01-01', $field => $value]]]), 'sales.0.'.$field);
            self::assertSame('30.000000', DB::table('product_sales')->where('id', $sale->id)->value('amount'));
        }
        $this->invalid(fn () => $this->save($copy, ['sales' => [[...$row, 'id' => (string) $sale->id]]]), 'sales.0.id');
        $kept = $this->save($product, []);
        self::assertSame(1, DB::table('product_sales')->where('product_id', $product->id)->count());
        $this->invalid(fn () => $this->save($product, ['sales' => []]), 'version');
        $cleared = $this->save($kept, ['sales_submitted' => true, 'sales' => []]);
        self::assertSame(0, DB::table('product_sales')->where('product_id', $product->id)->count());
        $viewer = $this->staff();
        $this->actingAs($viewer)->withSession(['password_hash_web' => $viewer->getAuthPassword()]);
        $this->post('/admin/products/'.$cleared->id, [...$this->fields($cleared), 'sales' => [$row]])->assertForbidden();
    }

    public function test_extra_categories_save_reload_duplicate_and_never_repeat_the_main_category(): void
    {
        $category = function (string $name, ?int $parent = null): string {
            $c = CategoryRecord::create(['parent_id' => $parent, 'position' => 1, 'status' => 'active']);
            $c->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => $name, 'slug' => Str::slug($name)]);

            return (string) $c->id;
        };
        $gifts = $category('Gifts');
        $sale = $category('Sale');
        $product = $this->draft();
        $this->post('/admin/products/'.$product->id, [...$this->fields($product), 'extra_categories_submitted' => true, 'extra_category_ids' => [$sale, $gifts]])->assertSessionHasNoErrors();
        self::assertSame([$sale, $gifts], app(ProductCategories::class)->extras((string) $product->id));
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.extra_category_ids', [$sale, $gifts])
            ->where('extraCategoryOptions', fn ($o) => collect($o)->pluck('name')->contains('Gifts')));
        // Saves that do not send the extras keep them; a main category is never also an extra one.
        $this->post('/admin/products/'.$product->id, $this->fields($product->fresh()))->assertSessionHasNoErrors();
        self::assertSame([$sale, $gifts], app(ProductCategories::class)->extras((string) $product->id));
        $this->post('/admin/products/'.$product->id, [...$this->fields($product->fresh()), 'extra_category_ids' => [(string) $this->category->id, $gifts]])->assertSessionHasNoErrors();
        self::assertSame([$gifts], app(ProductCategories::class)->extras((string) $product->id));
        // System and unknown categories are refused.
        $uncategorized = (string) CategoryRecord::where('system_key', 'uncategorized')->value('id');
        foreach ([$uncategorized, '999999'] as $bad) {
            $this->post('/admin/products/'.$product->id, [...$this->fields($product->fresh()), 'extra_category_ids' => [$bad]])->assertSessionHasErrors('extra_category_ids.0');
        }
        self::assertSame([$gifts], app(ProductCategories::class)->extras((string) $product->id));
        // A copy keeps them; deleting a category removes only that link.
        $copy = $this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->fresh()->lock_version);
        self::assertSame([$gifts], app(ProductCategories::class)->extras($copy));
        DB::table('categories')->where('id', $gifts)->delete();
        self::assertSame([], app(ProductCategories::class)->extras((string) $product->id));
        self::assertSame((string) $this->category->id, (string) $product->fresh()->category_id);
        // The admin list filter finds products by extra category too.
        DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $sale, 'position' => 1]);
        $this->get('/admin/products?category_id='.$sale)->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
    }

    public function test_optional_isbn_and_upc_save_reload_clear_and_follow_duplication_rules(): void
    {
        $draft = $this->draft();
        self::assertNull($draft->isbn);
        self::assertNull($draft->upc);
        $product = $this->save($draft, ['isbn' => ' 0-306-40615-2 ', 'upc' => ' 012345678905 ']);
        self::assertSame('0306406152', $product->isbn);
        self::assertSame('012345678905', $product->upc);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.isbn', '0306406152')->where('product.upc', '012345678905'));
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->lock_version));
        self::assertSame('0306406152', $copy->isbn);
        self::assertNull($copy->upc);
        $saved = $this->save($product, []);
        self::assertSame('0306406152', $saved->isbn);
        self::assertSame('012345678905', $saved->upc);
        $this->invalid(fn () => $this->save($product, ['isbn' => '9780306406157']), 'version');
        foreach (['isbn' => ['0306406153', '4006381333931', '-', [], 123], 'upc' => ['012345678906', '12345678905', [], 123]] as $field => $invalids) {
            foreach ($invalids as $invalid) {
                $this->invalid(fn () => $this->save($saved, [$field => $invalid]), $field);
                self::assertSame($saved->lock_version, $saved->fresh()->lock_version);
            }
        }
        $saved = $this->save($saved, ['isbn' => '0 8044 2957 x']);
        self::assertSame('080442957X', $saved->isbn);
        $cleared = $this->save($saved, ['isbn' => ' ', 'upc' => '']);
        self::assertNull($cleared->isbn);
        self::assertNull($cleared->upc);
        $viewer = $this->staff();
        $this->actingAs($viewer)->withSession(['password_hash_web' => $viewer->getAuthPassword()]);
        $this->post('/admin/products/'.$cleared->id, array_replace($this->fields($cleared), ['isbn' => '9780306406157', 'upc' => '012345678905']))->assertForbidden();
        self::assertNull($cleared->fresh()->isbn);
    }

    public function test_identifier_constraints_and_migration_roundtrip_preserve_the_product(): void
    {
        $product = $this->save($this->draft(), ['isbn' => '9780306406157', 'upc' => '012345678905']);
        foreach (['isbn' => '9780306406158', 'upc' => '012345678906'] as $field => $value) {
            try {
                DB::transaction(fn () => DB::table('products')->where('id', $product->id)->update([$field => $value]));
                self::fail('Invalid identifier bypassed its SQL constraint');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->errorInfo[0]);
            }
        }
        $migration = require database_path('migrations/2026_10_16_000200_add_product_isbn_and_upc.php');
        $migration->down();
        self::assertSame($product->sku, $product->fresh()->sku);
        $migration->up();
        self::assertNull($product->fresh()->isbn);
        self::assertNull($product->fresh()->upc);
        DB::table('products')->where('id', $product->id)->update(['isbn' => '9780306406157', 'upc' => '012345678905']);
        self::assertSame('9780306406157', $product->fresh()->isbn);
    }

    public function test_optional_mpn_is_persisted_audited_copied_and_validated_with_existing_save_guards(): void
    {
        $draft = $this->draft();
        self::assertNull($draft->mpn);
        $product = $this->save($draft, ['mpn' => '  001-MODEL/A  ', 'status' => 'active']);
        self::assertSame('001-MODEL/A', $product->mpn);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.mpn', '001-MODEL/A'));
        $this->assertDatabaseHas('product_audits', ['product_id' => $product->id, 'action' => 'metadata_changed']);
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->lock_version));
        self::assertSame('001-MODEL/A', $copy->mpn);
        $saved = $this->save($product, ['status' => 'active']);
        self::assertSame('001-MODEL/A', $saved->mpn);
        foreach ([str_repeat('A', 201), ['MODEL'], 123, "MODEL\nCODE"] as $invalid) {
            $this->invalid(fn () => $this->save($saved, ['mpn' => $invalid]), 'mpn');
            self::assertSame('001-MODEL/A', $saved->fresh()->mpn);
            self::assertSame($saved->lock_version, $saved->fresh()->lock_version);
        }
        $this->invalid(fn () => $this->save($product, ['mpn' => 'STALE']), 'version');

        $cleared = $this->save($saved, ['mpn' => '  ', 'status' => 'active']);
        self::assertNull($cleared->mpn);
        self::assertSame('active', $cleared->status);
        $this->get('/admin/products/'.$cleared->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.mpn', null));
    }

    public function test_mpn_save_requires_product_update_permission(): void
    {
        $product = $this->save($this->draft(), ['mpn' => '001-MODEL/A']);
        $this->actingAs($this->staff());
        $this->post('/admin/products/'.$product->id, array_replace($this->fields($product), ['mpn' => 'UNAUTHORIZED']))->assertForbidden();
        self::assertSame('001-MODEL/A', $product->fresh()->mpn);
    }

    public function test_optional_ean_and_returnable_are_persisted_exposed_searched_audited_and_copied_safely(): void
    {
        $draft = $this->draft();
        self::assertNull($draft->ean);
        self::assertFalse($draft->returnable);
        $product = $this->save($draft, ['ean' => '  0123456789012  ', 'returnable' => true, 'status' => 'active']);
        self::assertSame('0123456789012', $product->ean);
        self::assertTrue($product->returnable);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('product.ean', '0123456789012')->where('product.returnable', true));
        $this->get('/admin/products?search=0123456789012')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->assertDatabaseHas('product_audits', ['product_id' => $product->id, 'action' => 'metadata_changed']);
        $product = $this->save($product, ['status' => 'active']); // Older clients omit the new optional fields.
        self::assertSame('0123456789012', $product->ean);
        self::assertTrue($product->returnable);
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->lock_version));
        self::assertNull($copy->ean);
        self::assertTrue($copy->returnable);
        self::assertSame('disabled', $copy->status);
        $product = $this->save($product, ['ean' => '96385074', 'returnable' => false, 'status' => 'active']);
        self::assertSame('96385074', $product->ean);
        self::assertFalse($product->returnable);
        $product = $this->save($product, ['ean' => '', 'status' => 'active']);
        self::assertNull($product->ean);
        self::assertSame('active', $product->status);
    }

    public function test_ean_and_returnable_validation_permissions_and_stale_saves_roll_back(): void
    {
        $product = $this->save($this->draft(), ['ean' => '4006381333931', 'returnable' => true]);
        foreach (['4006381333932', '96385075', '123456789012', 'ABC12345', '4006381 33931', ['4006381333931'], 4006381333931] as $invalid) {
            $this->invalid(fn () => $this->save($product, ['ean' => $invalid, 'returnable' => false]), 'ean');
            self::assertSame('4006381333931', $product->fresh()->ean);
            self::assertTrue($product->fresh()->returnable);
            self::assertSame($product->lock_version, $product->fresh()->lock_version);
        }
        $this->invalid(fn () => $this->save($product, ['returnable' => 'yes']), 'returnable');
        $saved = $this->save($product, ['ean' => null, 'returnable' => false]);
        $this->invalid(fn () => $this->save($product, ['ean' => '96385074', 'returnable' => true]), 'version');
        self::assertNull($saved->fresh()->ean);
        self::assertFalse($saved->fresh()->returnable);
        $this->actingAs($this->staff());
        $this->post('/admin/products/'.$saved->id, array_replace($this->fields($saved), ['ean' => '96385074', 'returnable' => true]))->assertForbidden();
        self::assertNull($saved->fresh()->ean);
        self::assertFalse($saved->fresh()->returnable);
    }

    public function test_database_ean_constraint_and_metadata_migration_round_trip_preserve_products(): void
    {
        $product = $this->save($this->draft());
        foreach (['4006381333932', '123', 'abcdefgh', ''] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('products')->where('id', $product->id)->update(['ean' => $invalid]));
                self::fail('Expected EAN constraint rejection');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
        }
        DB::table('products')->where('id', $product->id)->update(['ean' => '0123456789012', 'returnable' => true]);
        $identifiers = require database_path('migrations/2026_10_16_000200_add_product_isbn_and_upc.php');
        $identifiers->down();
        $migration = require database_path('migrations/2026_10_10_001100_add_product_ean_and_returnable.php');
        $migration->down();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => $product->sku]);
        $migration->up();
        self::assertNull($product->fresh()->ean);
        self::assertFalse($product->fresh()->returnable);
        $identifiers->up();
    }

    public function test_drafts_persist_null_skus_and_complete_with_exact_zero_price(): void
    {
        $this->post('/admin/products')->assertRedirect();
        $a = ProductRecord::sole();
        $b = $this->draft();
        self::assertNull($a->sku);
        self::assertNull($b->sku);
        self::assertSame('disabled', $a->status);
        $this->get('/admin/products/'.$a->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.incomplete', true));
        $saved = $this->save($a, ['sku' => '  Mixed / SKU  ']);
        self::assertSame('Mixed / SKU', $saved->sku);
        self::assertSame('0.000000', $saved->price);
        $this->get('/admin/products/'.$a->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.incomplete', false)->where('product.translations.en.slug', 'product')->missing('product.translations.el'));
        $this->assertDatabaseHas('product_audits', ['product_id' => $a->id, 'action' => 'completed']);
    }

    public function test_required_fields_and_negative_values_reject_without_partial_changes(): void
    {
        $p = $this->draft();
        foreach (['sku' => '  ', 'price' => '-1', 'category_id' => null, 'type' => '', 'weight' => '-0.1', 'length' => '-1', 'width' => '-1', 'height' => '-1'] as $field => $value) {
            $this->invalid(fn () => $this->save($p, [$field => $value]), $field);
        }
        $this->invalid(fn () => $this->save($p, ['translations' => ['el' => ['name' => 'Όνομα']]]), 'translations.en.name');
        self::assertSame(1, $p->fresh()->lock_version);
        self::assertNull($p->fresh()->sku);
        $this->assertDatabaseCount('product_translations', 0);
    }

    public function test_skus_are_reserved_case_insensitively_including_trash_and_database_races(): void
    {
        $a = $this->save($this->draft(), ['sku' => 'Mixed SKU']);
        $this->products->lifecycle((string) $this->actor->id, (string) $a->id, $a->lock_version, 'trash');
        $b = $this->draft();
        $this->invalid(fn () => $this->save($b, ['sku' => 'mixed sku']), 'sku');
        try {
            DB::transaction(fn () => DB::table('products')->where('id', $b->id)->update(['sku' => 'MIXED SKU']));
            self::fail('Expected unique constraint');
        } catch (QueryException $e) {
            self::assertSame('23505', $e->getCode());
        }
    }

    public function test_locales_are_dynamic_and_activation_only_requires_the_default_translation(): void
    {
        LocaleRecord::where('is_default', true)->update(['is_default' => false]);
        LocaleRecord::where('code', 'el')->update(['is_default' => true]);
        $p = $this->save($this->draft(), ['translations' => ['el' => ['name' => 'Καρέκλα']], 'status' => 'active']);
        self::assertSame('active', $p->status);
        $this->assertDatabaseCount('product_translations', 1);
        $this->invalid(fn () => $this->save($p, ['translations' => ['fr' => ['name' => 'Invalid']]]), 'translations');
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('defaultLocale', 'el')->missing('product.translations.en'));
    }

    public function test_generated_slugs_suffix_manual_conflicts_fail_and_existing_slugs_stay_stable(): void
    {
        $a = $this->save($this->draft());
        $b = $this->save($this->draft());
        self::assertSame('product-2', $b->translations()->sole()->slug);
        $a = $this->save($a, ['translations' => ['en' => ['name' => 'Changed']]]);
        self::assertSame('product', $a->translations()->sole()->slug);
        $this->invalid(fn () => $this->save($b, ['translations' => ['en' => ['name' => 'Other', 'slug' => 'Product']]]), 'translations.en.slug');
        $this->products->lifecycle((string) $this->actor->id, (string) $a->id, $a->lock_version, 'trash');
        $c = $this->save($this->draft());
        self::assertSame('product-3', $c->translations()->sole()->slug);
        $this->save($b, ['translations' => ['el' => ['name' => 'Ελληνικό', 'slug' => 'product']]]);
        self::assertSame('product', $b->translations()->where('locale_id', LocaleRecord::where('code', 'el')->sole()->id)->sole()->slug);
    }

    public function test_descriptions_use_allowlist_sanitization(): void
    {
        $p = $this->save($this->draft(), ['translations' => ['en' => ['name' => 'Safe', 'description' => '<p onclick="alert(1)"><strong>Good</strong><script>alert(2)</script><a href="javascript:alert(3)">bad</a><a href="https://example.com">link</a><img src=x onerror=alert(4)></p><ul><li>Item</li></ul>']]]);
        $html = $p->translations()->sole()->description;
        self::assertStringContainsString('<strong>Good</strong>', $html);
        self::assertStringContainsString('<li>Item</li>', $html);
        foreach (['onclick', 'javascript:', '<script', '<img', 'onerror'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }
    }

    public function test_categories_enforce_leaves_and_delete_reassigns_with_stale_save_protection(): void
    {
        $p = $this->save($this->draft(), ['status' => 'active']);
        $this->post('/admin/categories', ['parent_id' => (string) $this->category->id, 'position' => 1, 'status' => 'active', 'translations' => ['en' => ['name' => 'Child']]])->assertSessionHasErrors();
        $this->delete('/admin/categories/'.$this->category->id, ['version' => 1])->assertSessionHasNoErrors();
        $fresh = $p->fresh();
        self::assertSame('disabled', $fresh->status);
        self::assertSame(CategoryRecord::where('system_key', 'uncategorized')->sole()->id, $fresh->category_id);
        self::assertGreaterThan($p->lock_version, $fresh->lock_version);
        $this->invalid(fn () => $this->save($p, ['status' => 'active']), 'version');
        $this->assertDatabaseHas('product_audits', ['product_id' => $p->id, 'action' => 'category_recovery']);
    }

    public function test_parent_category_assignments_and_system_category_are_rejected(): void
    {
        CategoryRecord::create(['parent_id' => $this->category->id, 'position' => 1, 'status' => 'active']);
        $p = $this->draft();
        $this->invalid(fn () => $this->save($p), 'category_id');
        $this->invalid(fn () => $this->save($p, ['category_id' => CategoryRecord::where('system_key', 'uncategorized')->sole()->id]), 'category_id');
    }

    public function test_bulk_status_has_independent_outcomes_and_stale_editor_cannot_reactivate(): void
    {
        $good = $this->save($this->draft());
        $draft = $this->draft();
        $results = $this->products->bulk((string) $this->actor->id, [(string) $good->id, (string) $draft->id], 'active');
        self::assertTrue($results[0]['success']);
        self::assertFalse($results[1]['success']);
        $this->invalid(fn () => $this->save($good), 'version');
        $results = $this->products->bulk((string) $this->actor->id, [(string) $draft->id], 'hidden');
        self::assertTrue($results[0]['success']);
        self::assertSame('hidden', $draft->fresh()->status);
        $this->get('/admin/products?incomplete=1')->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.incomplete', true));
        $this->assertDatabaseHas('product_audits', ['action' => 'bulk_active']);
    }

    public function test_duplicate_copies_images_text_and_price_but_no_sku_or_private_files(): void
    {
        $p = $this->draft();
        $upload = UploadedFile::fake()->createWithContent('shared.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
        $media = MediaFileRecord::create(app(PrivateImageStorage::class)->store(new ImageUpload($upload->getRealPath())));
        $file = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Useful guide'));
        $p = $this->save($p, ['type' => 'digital', 'images' => [(string) $media->id], 'primary_image' => (string) $media->id, 'staged_images' => [(string) $media->id], 'staged_files' => [$file]]);
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $p->id, $p->lock_version));
        self::assertNull($copy->sku);
        self::assertSame('disabled', $copy->status);
        self::assertSame('digital', $copy->type);
        self::assertSame('product-2', $copy->translations()->sole()->slug);
        $copiedMedia = DB::table('product_images')->where('product_id', $copy->id)->sole();
        self::assertNotSame((string) $media->id, (string) $copiedMedia->media_file_id);
        $this->assertDatabaseHas('product_images', ['product_id' => $copy->id, 'media_file_id' => $copiedMedia->media_file_id, 'is_primary' => true]);
        $this->assertDatabaseMissing('product_files', ['product_id' => $copy->id]);
        $this->invalid(fn () => app(SharedMediaDeletion::class)->assertUnreferenced($media), 'media');
        $emptyCopy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) ($draft = $this->draft())->id, 1));
        self::assertNull($emptyCopy->sku);
    }

    public function test_digital_activation_file_removal_type_switch_and_replacement(): void
    {
        $p = $this->draft();
        $this->invalid(fn () => $this->save($p, ['type' => 'digital', 'status' => 'active']), 'files');
        $first = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('original.txt', 'First content'));
        $p = $this->save($p, ['type' => 'digital', 'status' => 'active', 'staged_files' => [$first]]);
        $this->invalid(fn () => $this->save($p, ['type' => 'digital', 'status' => 'active', 'files' => []]), 'files');
        $association = DB::table('product_files')->where('product_id', $p->id)->sole();
        $next = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('second.txt', 'Second content'));
        $p = $this->save($p, ['type' => 'digital', 'status' => 'active', 'files' => [['id' => (string) $association->id, 'display_name' => 'Guide.txt', 'replacement' => 0]], 'staged_files' => [$next]]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id, 'private_file_id' => $next, 'display_name' => 'Guide.txt', 'position' => 1]);
        $association = DB::table('product_files')->where('product_id', $p->id)->sole();
        $p = $this->save($p, ['type' => 'physical', 'files' => [['id' => (string) $association->id, 'display_name' => 'Guide.txt']]]);
        self::assertSame(1, DB::table('product_files')->where('product_id', $p->id)->count());
        $p = $this->save($p, ['type' => 'digital', 'status' => 'active', 'files' => [['id' => (string) DB::table('product_files')->where('product_id', $p->id)->sole()->id, 'display_name' => 'Guide.txt']]]);
        self::assertSame('active', $p->status);
    }

    public function test_private_upload_validation_and_projection_never_exposes_storage_keys(): void
    {
        foreach ([['danger.php', '<?php echo 1;'], ['fake.pdf', 'not a PDF']] as [$name, $content]) {
            $this->invalid(fn () => app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent($name, $content)), 'file_uploads');
        }
        config(['products.private_files.max_kilobytes' => 1]);
        $this->invalid(fn () => app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 2048))), 'file_uploads');
        $id = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        $p = $this->save($this->draft(), ['type' => 'digital', 'staged_files' => [$id]]);
        $editor = app(ProductQueries::class)->editor($p);
        self::assertArrayNotHasKey('storage_key', $editor['files'][0]);
        self::assertArrayNotHasKey('private_file_id', $editor['files'][0]);
    }

    public function test_trash_restore_and_permanent_delete_use_durable_cleanup_and_preserve_audit(): void
    {
        Bus::fake();
        $fileId = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        $key = DB::table('private_product_files')->where('id', $fileId)->value('storage_key');
        $p = $this->save($this->draft(), ['type' => 'digital', 'status' => 'active', 'staged_files' => [$fileId]]);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id]);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'restore');
        $p = $p->fresh();
        self::assertSame('disabled', $p->status);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        $this->invalid(fn () => $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'purge', 'wrong'), 'confirmation');
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'purge', 'Product');
        $this->assertDatabaseMissing('products', ['id' => $p->id]);
        $this->assertDatabaseHas('product_file_cleanups', ['private_file_id' => $fileId]);
        Storage::disk('local')->assertExists($key);
        Bus::assertNothingDispatched();
        $this->artisan('cms:product-files-cleanup')->assertSuccessful();
        Bus::assertDispatched(CleanupProductFile::class);
        (new CleanupProductFile($fileId))->handle();
        (new CleanupProductFile($fileId))->handle();
        Storage::disk('local')->assertMissing($key);
        $this->assertDatabaseHas('product_audits', ['product_id' => $p->id, 'action' => 'permanently_deleted']);
    }

    public function test_cleanup_rechecks_references_and_protected_deletion_rolls_back(): void
    {
        $fileId = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('shared.txt', 'Guide'));
        $key = DB::table('private_product_files')->where('id', $fileId)->value('storage_key');
        $p = $this->save($this->draft(), ['staged_files' => [$fileId]]);
        (new CleanupProductFile($fileId))->handle();
        Storage::disk('local')->assertExists($key);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        app(ProductDeletionGuards::class)->add(fn (string $id): ?string => $id === (string) $p->id ? 'products.unavailable' : null);
        $this->invalid(fn () => $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'purge', 'Product'), 'product');
        $this->assertDatabaseHas('products', ['id' => $p->id]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id]);
    }

    public function test_listing_filters_pagination_search_and_return_state(): void
    {
        $physical = $this->save($this->draft(), ['translations' => ['en' => ['name' => 'Chair'], 'el' => ['name' => 'Καρέκλα']], 'sku' => 'CHAIR']);
        $this->save($this->draft(), ['type' => 'digital', 'status' => 'hidden']);
        $this->draft();
        $this->get('/admin/products?search='.urlencode('Καρέκλα'))->assertInertia(fn (Assert $p) => $p->has('products.data', 1)->where('products.data.0.id', (string) $physical->id));
        $this->get('/admin/products?type=digital&status=hidden&per_page=25')->assertInertia(fn (Assert $p) => $p->has('products.data', 1)->where('products.per_page', 25));
        $this->get('/admin/products?search=absent')->assertInertia(fn (Assert $p) => $p->has('products.data', 0)->where('catalogCount', 3));
        $this->get('/admin/products?sort=sql')->assertSessionHasErrors('sort');
        $return = '/admin/products?search=CHAIR&sort=sku&direction=asc&page=2&per_page=25';
        $this->get('/admin/products/'.$physical->id.'/edit?return_to='.urlencode($return))->assertInertia(fn (Assert $p) => $p->where('returnTo', $return));
        $this->get('/admin/products/'.$physical->id.'/edit?return_to=https://evil.test')->assertInertia(fn (Assert $p) => $p->where('returnTo', '/admin/products'));
    }

    public function test_default_pagination_filters_and_activation_slug_validation(): void
    {
        $this->get('/admin/products')->assertInertia(fn (Assert $p) => $p->where('catalogCount', 0)->where('products.per_page', 50));
        $complete = $this->save($this->draft(), ['sku' => 'MATCH-SKU']);
        $this->draft();
        $this->get('/admin/products?search=match-sku')->assertInertia(fn (Assert $p) => $p->has('products.data', 1)->where('products.data.0.id', (string) $complete->id));
        $this->get('/admin/products?incomplete=1&per_page=100')->assertInertia(fn (Assert $p) => $p->has('products.data', 1)->where('products.data.0.incomplete', true)->where('products.per_page', 100));
        $this->get('/admin/products?category_id='.$this->category->id.'&incomplete=0')->assertInertia(fn (Assert $p) => $p->has('products.data', 1)->where('products.data.0.id', (string) $complete->id));
        $this->get('/admin/products?per_page=200')->assertSessionHasErrors('per_page');
        $this->get('/admin/products?category_search=Leaf')->assertInertia(fn (Assert $p) => $p->has('categories', 1)->where('categories.0.id', (string) $this->category->id));
        // Price input precision is the primary currency's configured decimals.
        DB::table('currencies')->where('is_primary', true)->update(['decimals' => 0]);
        $this->invalid(fn () => $this->save($complete, ['price' => '1.25']), 'price');
        DB::table('currencies')->where('is_primary', true)->update(['decimals' => 2]);
        $complete->translations()->update(['slug' => null]);
        $this->invalid(fn () => $this->products->lifecycle((string) $this->actor->id, (string) $complete->id, $complete->lock_version, 'active'), 'translations.en.slug');
        $this->assertDatabaseHas('products', ['id' => $complete->id, 'status' => 'disabled']);
    }

    public function test_missing_staged_bytes_cannot_be_associated_after_cleanup_or_storage_loss(): void
    {
        $fileId = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        $key = DB::table('private_product_files')->where('id', $fileId)->value('storage_key');
        Storage::disk('local')->delete($key);
        $draft = $this->draft();
        $this->invalid(fn () => $this->save($draft, ['staged_files' => [$fileId]]), 'files');
        $this->assertDatabaseCount('product_files', 0);
        self::assertNull($draft->fresh()->sku);
    }

    public function test_permissions_are_checked_on_every_browser_operation(): void
    {
        $p = $this->draft();
        $this->actingAs($this->staff());
        foreach (['/admin/products', '/admin/products/trash', '/admin/products/'.$p->id.'/edit', '/admin/product-media/1'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/products')->assertForbidden();
        $this->post('/admin/products/'.$p->id, $this->fields($p))->assertForbidden();
        $this->post('/admin/products/'.$p->id.'/duplicate', ['version' => 1])->assertForbidden();
        foreach (['active', 'disabled', 'hidden', 'trash', 'restore', 'purge'] as $action) {
            $this->post('/admin/products/'.$p->id.'/action', ['version' => 1, 'action' => $action, 'confirmation' => 'Product'])->assertForbidden();
        }
        $this->post('/admin/products/bulk', ['ids' => [(string) $p->id], 'status' => 'active'])->assertForbidden();
    }

    public function test_http_uploads_gallery_order_primary_integrity_and_empty_multipart_removal(): void
    {
        $p = $this->draft();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
        $this->post('/admin/products/'.$p->id, [...$this->fields($p), 'return_to' => '/admin/products?page=2', 'image_uploads' => [UploadedFile::fake()->createWithContent('first.png', $png), UploadedFile::fake()->createWithContent('second.png', $png)], 'file_uploads' => [UploadedFile::fake()->createWithContent('guide.txt', 'A real guide')]])->assertSessionHasNoErrors()->assertRedirect('/admin/products/'.$p->id.'/edit?return_to='.urlencode('/admin/products?page=2'));
        $p = $p->fresh();
        $ids = DB::table('product_images')->where('product_id', $p->id)->orderBy('position')->pluck('media_file_id')->map(strval(...))->all();
        // The editor gets display metadata (size, dimensions) for the image uploader, never storage keys.
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.image_meta.'.$ids[0].'.width', 1)->where('product.image_meta.'.$ids[0].'.height', 1)->where('product.image_meta.'.$ids[0], fn ($meta) => array_keys($meta->all()) === ['size', 'width', 'height']));
        self::assertCount(2, $ids);
        $this->assertDatabaseCount('product_files', 1);
        $p = $this->save($p, ['images' => array_reverse($ids), 'primary_image' => $ids[0]]);
        self::assertSame(array_reverse($ids), DB::table('product_images')->where('product_id', $p->id)->orderBy('position')->pluck('media_file_id')->map(strval(...))->all());
        $this->assertDatabaseHas('product_images', ['product_id' => $p->id, 'media_file_id' => $ids[0], 'is_primary' => true]);
        $this->invalid(fn () => $this->save($p, ['images' => [$ids[1]], 'primary_image' => $ids[0]]), 'images');
        $this->get('/admin/product-media/'.$ids[0])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $payload = $this->fields($p);
        unset($payload['images'], $payload['files']);
        $this->post('/admin/products/'.$p->id, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_product_cannot_attach_another_products_owned_image(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
        $first = $this->draft();
        $this->post('/admin/products/'.$first->id, [...$this->fields($first), 'image_uploads' => [UploadedFile::fake()->createWithContent('owned.png', $png)]])->assertSessionHasNoErrors();
        $owned = (string) DB::table('product_images')->where('product_id', $first->id)->value('media_file_id');
        $second = $this->draft();
        $this->invalid(fn () => $this->save($second, ['images' => [$owned], 'primary_image' => $owned]), 'images');
        $this->assertDatabaseMissing('product_images', ['product_id' => $second->id]);
    }

    public function test_category_rename_preserves_product_version_but_disabling_and_default_change_revalidate(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => false]);
        $p = $this->save($this->draft(), ['status' => 'active']);
        $data = ['version' => 1, 'parent_id' => null, 'position' => 1, 'status' => 'active', 'translations' => ['en' => ['name' => 'Renamed', 'slug' => 'renamed']]];
        $this->put('/admin/categories/'.$this->category->id, $data)->assertSessionHasNoErrors();
        self::assertSame($p->lock_version, $p->fresh()->lock_version);
        self::assertSame('active', $p->fresh()->status);
        $this->put('/admin/categories/'.$this->category->id, [...$data, 'version' => 2, 'status' => 'disabled'])->assertSessionHasNoErrors();
        self::assertSame('disabled', $p->fresh()->status);
        $this->category->refresh()->update(['status' => 'active']);
        $p = $this->save($p->fresh(), ['status' => 'active']);
        app(ManageLanguagesHandler::class)->configure((string) $this->actor->id, ['en', 'el'], 'el');
        self::assertSame('disabled', $p->fresh()->status);
        self::assertGreaterThan($p->lock_version, $p->fresh()->lock_version);
    }

    public function test_restore_recovers_unavailable_category_and_rejects_a_legacy_sku_conflict(): void
    {
        $p = $this->save($this->draft());
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        $this->category->update(['status' => 'disabled']);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'restore');
        $p = $p->fresh();
        self::assertSame(CategoryRecord::where('system_key', 'uncategorized')->sole()->id, $p->category_id);
        self::assertSame('disabled', $p->status);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        // Simulate legacy data before the unique index; PostgreSQL DDL rolls back with this test.
        DB::statement('DROP INDEX products_sku_unique');
        $other = $this->draft();
        DB::table('products')->where('id', $other->id)->update(['sku' => strtolower($p->sku)]);
        $this->invalid(fn () => $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'restore'), 'sku');
        self::assertNotNull($p->fresh()->deleted_at);
    }

    public function test_cleanup_storage_failure_is_retryable_and_failed_purge_registration_rolls_back(): void
    {
        $id = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        app(ProductFiles::class)->schedule([$id]);
        $disk = Storage::disk('local');
        $broken = \Mockery::mock($disk)->makePartial();
        $broken->shouldReceive('exists')->andReturn(true);
        $broken->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('local', $broken);
        try {
            (new CleanupProductFile($id))->handle();
            self::fail('Expected retryable cleanup failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Private file cleanup failed.', $e->getMessage());
        } finally {
            Storage::set('local', $disk);
        }
        $this->assertDatabaseHas('private_product_files', ['id' => $id]);
        $this->assertDatabaseHas('product_file_cleanups', ['private_file_id' => $id]);
        (new CleanupProductFile($id))->handle();
        $this->assertDatabaseMissing('private_product_files', ['id' => $id]);

        $id = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('next.txt', 'Next'));
        $p = $this->save($this->draft(), ['staged_files' => [$id]]);
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $p = ProductRecord::withTrashed()->findOrFail($p->id);
        DB::statement('ALTER TABLE product_file_cleanups ADD CONSTRAINT test_cleanup_rejection CHECK (false) NOT VALID');
        try {
            $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'purge', 'Product');
            self::fail('Expected durable queue registration failure');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
        $this->assertDatabaseHas('products', ['id' => $p->id]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id]);
    }

    public function test_stale_save_cannot_resurrect_trash_and_slug_constraint_reserves_all_rows(): void
    {
        $p = $this->save($this->draft());
        $this->products->lifecycle((string) $this->actor->id, (string) $p->id, $p->lock_version, 'trash');
        $this->invalid(fn () => $this->save($p), 'version');
        $other = $this->draft();
        try {
            DB::transaction(fn () => $other->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Other', 'slug' => 'product']));
            self::fail('Expected slug reservation');
        } catch (QueryException $e) {
            self::assertSame('23505', $e->getCode());
        }
        $this->app->instance('env', 'production');
        try {
            $this->post('/admin/products')->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_new_products_start_with_zero_stock_unset_limits_and_follow_the_general_setting(): void
    {
        $draft = $this->draft();
        self::assertSame(0, $draft->fresh()->quantity);
        self::assertNull($draft->fresh()->list_price);
        self::assertNull($draft->fresh()->min_qty);
        self::assertNull($draft->fresh()->max_qty);
        self::assertNull($draft->fresh()->qty_step);
        self::assertNull($draft->fresh()->out_of_stock_actions);
        // Omitting the new fields (older clients) keeps price and SKU behavior unchanged and stock at 0.
        $saved = $this->save($draft, ['price' => '19.90']);
        self::assertSame('19.900000', $saved->price);
        self::assertSame('SKU-'.$draft->id, $saved->sku);
        self::assertSame(0, $saved->quantity);
        self::assertNull($saved->min_qty);
        $this->get('/admin/products/'.$saved->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.quantity', 0)->where('product.list_price', null)->where('product.min_qty', null)->where('product.out_of_stock_actions', null)->where('product.general_out_of_stock_action', 'N'));
    }

    public function test_stock_list_price_and_ordering_rules_persist_reload_audit_and_leave_status_alone(): void
    {
        $product = $this->save($this->draft(), ['status' => 'active', 'price' => '100', 'list_price' => '129.5', 'quantity' => '8', 'min_qty' => '2', 'max_qty' => '10', 'qty_step' => '2', 'out_of_stock_actions' => 'B']);
        self::assertSame(8, $product->quantity);
        self::assertSame('129.500000', $product->list_price);
        self::assertSame([2, 10, 2, 'B'], [$product->min_qty, $product->max_qty, $product->qty_step, $product->out_of_stock_actions]);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.quantity', 8)->where('product.list_price', '129.50')->where('product.min_qty', 2)->where('product.max_qty', 10)->where('product.qty_step', 2)->where('product.out_of_stock_actions', 'B'));
        // Reaching zero stock never changes status; blank limits return to unset (NULL), not zero.
        $empty = $this->save($product, ['status' => 'active', 'quantity' => '0', 'list_price' => '', 'min_qty' => '', 'max_qty' => null, 'qty_step' => '']);
        self::assertSame('active', $empty->status);
        self::assertSame(0, $empty->quantity);
        self::assertNull($empty->list_price);
        self::assertNull($empty->min_qty);
        self::assertNull($empty->max_qty);
        self::assertNull($empty->qty_step);
        self::assertSame('B', $empty->out_of_stock_actions);
        $changes = json_decode((string) DB::table('product_audits')->where('product_id', $product->id)->where('action', 'metadata_changed')->orderByDesc('id')->value('context'), true);
        self::assertEquals(['from' => 8, 'to' => 0], $changes['quantity']);
        // The central ledger captures the new reviewed fields.
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'product')->where('entity_id', (string) $product->id)->where('changes', 'like', '%quantity%')->exists());
    }

    public function test_invalid_stock_prices_limits_and_actions_are_rejected_without_changing_the_product(): void
    {
        $product = $this->save($this->draft(), ['quantity' => '5', 'list_price' => '20', 'min_qty' => '1', 'max_qty' => '9', 'qty_step' => '1', 'out_of_stock_actions' => 'N']);
        $bad = [
            'quantity' => ['-1', '1.5', 'abc', '', '2147483648'],
            'list_price' => ['-0.01', 'abc', '1.234', '1e3'],
            'min_qty' => ['0', '-2', '1.5', 'x'],
            'max_qty' => ['0', '-1', '2.5', 'x'],
            'qty_step' => ['0', '-1', '0.5', 'x'],
            'out_of_stock_actions' => ['Z', 'S', 'n', 'b'],
        ];
        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $this->invalid(fn () => $this->save($product, [$field => $value]), $field);
            }
        }
        // Maximum below minimum, through the same shared validation.
        $this->invalid(fn () => $this->save($product, ['min_qty' => '10', 'max_qty' => '4']), 'max_qty');
        $fresh = $product->fresh();
        self::assertSame([5, '20.000000', 1, 9, 1, 'N'], [$fresh->quantity, $fresh->list_price, $fresh->min_qty, $fresh->max_qty, $fresh->qty_step, $fresh->out_of_stock_actions]);
        // HTTP: errors are returned next to the fields and the saved values are untouched.
        $this->from('/admin/products/'.$product->id.'/edit')->post('/admin/products/'.$product->id, array_replace($this->fields($fresh), ['quantity' => '-3', 'min_qty' => '0', 'out_of_stock_actions' => 'X']))->assertSessionHasErrors(['quantity', 'min_qty', 'out_of_stock_actions']);
        self::assertSame(5, $product->fresh()->quantity);
        // The database also refuses invalid stock values.
        foreach (['quantity' => -1, 'min_qty' => 0, 'out_of_stock_actions' => 'X'] as $column => $value) {
            DB::beginTransaction();
            try {
                DB::table('products')->where('id', $product->id)->update([$column => $value]);
                self::fail('Expected a database constraint for '.$column);
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
        DB::beginTransaction();
        try {
            DB::table('products')->where('id', $product->id)->update(['min_qty' => 5, 'max_qty' => 4]);
            self::fail('Maximum below minimum must be refused by the database');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_stock_fields_cannot_be_changed_without_product_update_permission(): void
    {
        $product = $this->save($this->draft(), ['quantity' => '4', 'out_of_stock_actions' => 'N']);
        $this->actingAs($this->staff());
        $this->post('/admin/products/'.$product->id, array_replace($this->fields($product), ['quantity' => '999', 'list_price' => '5', 'min_qty' => '1', 'out_of_stock_actions' => 'B']))->assertForbidden();
        $fresh = $product->fresh();
        self::assertSame(4, $fresh->quantity);
        self::assertSame('N', $fresh->out_of_stock_actions);
        self::assertNull($fresh->list_price);
    }

    public function test_duplicates_start_at_zero_stock_but_keep_ordering_rules_and_variations_hold_their_own_quantity(): void
    {
        $source = $this->save($this->draft(), ['quantity' => '25', 'list_price' => '30', 'min_qty' => '3', 'qty_step' => '3', 'out_of_stock_actions' => 'B', 'price' => '24']);
        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $source->id, $source->lock_version));
        // Stock is never invented for a copy; reference price and ordering rules are carried over.
        self::assertSame(0, $copy->quantity);
        self::assertSame('30.000000', $copy->list_price);
        self::assertSame([3, null, 3, 'B'], [$copy->min_qty, $copy->max_qty, $copy->qty_step, $copy->out_of_stock_actions]);
        self::assertSame(25, $source->fresh()->quantity);
        // Independent products of one variation group each keep their own stock.
        $other = $this->save($copy, ['sku' => 'COPY-SKU', 'quantity' => '4']);
        self::assertSame([25, 4], [$source->fresh()->quantity, $other->quantity]);
        $this->save($source, ['quantity' => '0']);
        self::assertSame(4, $other->fresh()->quantity);
    }

    public function test_listing_exposes_stock_and_a_reference_price_only_when_it_exceeds_the_price(): void
    {
        $discounted = $this->save($this->draft(), ['price' => '80', 'list_price' => '100', 'quantity' => '3']);
        $same = $this->save($this->draft(), ['price' => '50', 'list_price' => '50']);
        $lower = $this->save($this->draft(), ['price' => '50', 'list_price' => '40']);
        $unset = $this->save($this->draft(), ['price' => '10']);
        $this->get('/admin/products?sort=quantity&direction=desc')->assertInertia(fn (Assert $page) => $page->where('products.data', function ($rows) use ($discounted, $same, $lower, $unset): bool {
            $byId = collect($rows)->keyBy('id');
            self::assertSame(3, $byId[(string) $discounted->id]['quantity']);
            self::assertNotNull($byId[(string) $discounted->id]['list_price_formatted']);
            foreach ([$same, $lower, $unset] as $p) {
                self::assertNull($byId[(string) $p->id]['list_price_formatted']);
            }
            self::assertSame((string) $discounted->id, $rows[0]['id']);

            return true;
        }));
    }

    public function test_stock_migration_preserves_existing_products_without_inventing_stock(): void
    {
        // Reels and deal blocks build on the selection table's promotion column, so they unwind first.
        $reels = require database_path('migrations/2026_11_20_000100_create_reels.php');
        $reels->down();
        $deals = require database_path('migrations/2026_11_19_000100_add_deal_block_selections.php');
        $deals->down();
        $promotions = require database_path('migrations/2026_10_25_000100_create_promotions.php');
        $promotions->down();
        // Newsletter blocks reference newsletter lists and unwind first.
        $newsletterBlock = require database_path('migrations/2026_10_30_000400_add_newsletter_block_selection.php');
        $newsletterBlock->down();
        $newsletters = require database_path('migrations/2026_10_18_000100_create_newsletters.php');
        $images = require database_path('migrations/2026_10_17_000200_add_method_images.php');
        $shipping = require database_path('migrations/2026_10_17_000100_create_shipping.php');
        $generalOptions = require database_path('migrations/2026_10_14_000900_expand_general_settings.php');
        $taxes = require database_path('migrations/2026_10_12_001800_create_tax_management.php');
        $settings = require database_path('migrations/2026_10_11_001700_create_general_settings.php');
        $migration = require database_path('migrations/2026_10_11_001600_add_product_stock_and_ordering.php');
        $product = $this->save($this->draft(), ['price' => '12.5', 'quantity' => '9', 'list_price' => '15']);
        // Later migrations build on the stock column (and shipping on the tax tables), so they unwind first.
        $newsletters->down();
        $images->down();
        $shipping->down();
        $generalOptions->down();
        $taxes->down();
        $settings->down();
        $migration->down();
        $row = DB::table('products')->where('id', $product->id)->first();
        self::assertSame('SKU-'.$product->id, $row->sku);
        self::assertSame('12.500000', $row->price);
        self::assertFalse(property_exists($row, 'quantity'));
        $migration->up();
        $row = DB::table('products')->where('id', $product->id)->first();
        // Every pre-existing product begins at zero; list price is not derived from price.
        self::assertSame([0, null, null, null, null, 'N'], [$row->quantity, $row->list_price, $row->min_qty, $row->max_qty, $row->qty_step, $row->out_of_stock_actions]);
        self::assertSame('12.500000', $row->price);
        $settings->up();
        $taxes->up();
        $generalOptions->up();
        $shipping->up();
        $images->up();
        $newsletters->up();
        $newsletterBlock->up();
        $promotions->up();
        $deals->up();
        $reels->up();
        // The unchanged default now follows the general setting instead of storing a copy of it.
        self::assertNull(DB::table('products')->where('id', $product->id)->value('out_of_stock_actions'));
    }

    public function test_a_product_follows_the_general_setting_until_it_stores_its_own_value_and_can_return_to_following(): void
    {
        $product = $this->save($this->draft(), ['quantity' => '2']);
        self::assertNull($product->out_of_stock_actions);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.out_of_stock_actions', null)->where('product.general_out_of_stock_action', 'N'));
        // A general change reaches every product that has no value of its own, with no product rows rewritten.
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'B'])->assertSessionHasNoErrors();
        self::assertNull($product->fresh()->out_of_stock_actions);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.out_of_stock_actions', null)->where('product.general_out_of_stock_action', 'B'));
        // An explicit value, even one equal to the general setting, is a deliberate override and stays put.
        $product = $this->save($product, ['out_of_stock_actions' => 'N']);
        self::assertSame('N', $product->out_of_stock_actions);
        $this->put('/admin/settings/general', ['version' => 2, 'out_of_stock_action' => 'N'])->assertSessionHasNoErrors();
        self::assertSame('N', $product->fresh()->out_of_stock_actions);
        // Blank returns the product to following.
        $product = $this->save($product, ['out_of_stock_actions' => '']);
        self::assertNull($product->out_of_stock_actions);
        $product = $this->save($product, ['out_of_stock_actions' => 'B']);
        self::assertNull($this->save($product, ['out_of_stock_actions' => null])->out_of_stock_actions);
    }

    public function test_listing_filters_products_by_whether_they_override_the_general_stock_setting(): void
    {
        $following = $this->save($this->draft(), ['price' => '5']);
        $custom = $this->save($this->draft(), ['price' => '6', 'out_of_stock_actions' => 'B']);
        $ids = fn (string $filter): array => collect($this->get('/admin/products?stock_action='.$filter)->viewData('page')['props']['products']['data'])->pluck('id')->all();
        self::assertSame([(string) $custom->id], $ids('custom'));
        self::assertSame([(string) $following->id], $ids('general'));
        $this->get('/admin/products?stock_action=other')->assertSessionHasErrors('stock_action');
    }

    private function customerGroup(string $name, string $status = 'active'): string
    {
        $id = DB::table('customer_groups')->insertGetId(['status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_translations')->insert(['group_id' => $id, 'locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => $name]);

        return (string) $id;
    }

    public function test_shipping_release_date_and_box_packaging_persist_reload_and_audit(): void
    {
        $product = $this->save($this->draft(), ['free_shipping' => true, 'shipping_cost' => '2.00', 'available_from' => '2026-10-20', 'box_min_items' => '2', 'box_max_items' => '6', 'box_length' => '40', 'box_width' => '30.5', 'box_height' => '20']);
        self::assertTrue($product->free_shipping);
        self::assertSame('2.000000', $product->shipping_cost);
        self::assertSame('2026-10-20', $product->available_from?->toDateString());
        self::assertSame([2, 6], [$product->box_min_items, $product->box_max_items]);
        self::assertSame(['40.000000', '30.500000', '20.000000'], [$product->box_length, $product->box_width, $product->box_height]);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.free_shipping', true)->where('product.shipping_cost', '2.00')->where('product.available_from', '2026-10-20')->where('product.box_min_items', 2)->where('product.box_max_items', 6));

        // Zero or blank item counts disable packaging; box dimensions are then optional and nothing is required.
        $cleared = $this->save($product, ['free_shipping' => false, 'shipping_cost' => '', 'available_from' => '', 'box_min_items' => '0', 'box_max_items' => '', 'box_length' => '', 'box_width' => '', 'box_height' => '']);
        self::assertFalse($cleared->free_shipping);
        self::assertNull($cleared->shipping_cost);
        self::assertNull($cleared->available_from);
        self::assertNull($cleared->box_min_items);
        self::assertNull($cleared->box_max_items);
        $changes = json_decode((string) DB::table('product_audits')->where('product_id', $product->id)->where('action', 'metadata_changed')->orderByDesc('id')->value('context'), true);
        self::assertEquals(['from' => '2026-10-20', 'to' => null], $changes['available_from']);
        self::assertEquals(['from' => true, 'to' => false], $changes['free_shipping']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'product')->where('entity_id', (string) $product->id)->where('changes', 'like', '%box_min_items%')->exists());
    }

    public function test_box_packaging_requires_positive_box_dimensions_and_consistent_counts(): void
    {
        $product = $this->save($this->draft());
        foreach (['box_length', 'box_width', 'box_height'] as $missing) {
            $box = ['box_max_items' => '4', 'box_length' => '10', 'box_width' => '10', 'box_height' => '10', $missing => ''];
            $this->invalid(fn () => $this->save($product, $box), $missing);
            $this->invalid(fn () => $this->save($product, [...$box, $missing => '0']), $missing);
        }
        $this->invalid(fn () => $this->save($product, ['box_min_items' => '5', 'box_max_items' => '2', 'box_length' => '1', 'box_width' => '1', 'box_height' => '1']), 'box_max_items');
        $this->invalid(fn () => $this->save($product, ['box_min_items' => '-1']), 'box_min_items');
        $this->invalid(fn () => $this->save($product, ['shipping_cost' => '-2']), 'shipping_cost');
        $this->invalid(fn () => $this->save($product, ['shipping_cost' => '2.001']), 'shipping_cost');
        $this->invalid(fn () => $this->save($product, ['available_from' => '20/10/2026']), 'available_from');
        self::assertNull($product->fresh()->box_max_items);
        self::assertSame($product->lock_version, $product->fresh()->lock_version);
        // A partial request cannot enable packaging around the requirement either.
        $this->invalid(fn () => $this->save($product, ['box_min_items' => '1']), 'box_length');
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['box_min_items' => 1]);
    }

    public function test_customer_group_visibility_is_saved_cleared_copied_and_blocks_group_deletion(): void
    {
        $retail = $this->customerGroup('Retail');
        $wholesale = $this->customerGroup('Wholesale');
        $disabled = $this->customerGroup('Old', 'disabled');
        $product = $this->save($this->draft(), ['customer_groups_submitted' => true, 'customer_groups' => [$wholesale, $retail]]);
        self::assertSame([$retail, $wholesale], DB::table('product_customer_groups')->where('product_id', $product->id)->orderBy('group_id')->pluck('group_id')->map(strval(...))->all());
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.customer_groups', [$retail, $wholesale])->where('customerGroups', fn ($groups) => collect($groups)->pluck('id')->all() === [$retail, $wholesale]));
        $this->invalid(fn () => $this->save($product, ['customer_groups' => ['99999']]), 'customer_groups');
        // A disabled group cannot be newly assigned, but a group disabled later stays assigned on save and duplication.
        $this->invalid(fn () => $this->save($product, ['customer_groups' => [$retail, $disabled]]), 'customer_groups');
        DB::table('customer_groups')->where('id', $wholesale)->update(['status' => 'disabled']);
        $product = $this->save($product, ['customer_groups' => [$retail, $wholesale]]);

        $copy = ProductRecord::findOrFail($this->products->duplicate((string) $this->actor->id, (string) $product->id, $product->lock_version));
        self::assertSame(2, DB::table('product_customer_groups')->where('product_id', $copy->id)->count());

        // Deleting a group that restricts a product is refused instead of making the product visible to everyone.
        $this->delete('/admin/customer-groups/'.$retail, ['version' => 1])->assertSessionHasErrors('group');
        $this->assertDatabaseHas('customer_groups', ['id' => $retail]);

        // The multipart editor submits the marker alone to clear the restriction; omitting both keeps it.
        $kept = $this->save($product->fresh(), ['sku' => 'KEPT']);
        self::assertSame(2, DB::table('product_customer_groups')->where('product_id', $kept->id)->count());
        $this->save($kept, ['customer_groups_submitted' => true]);
        self::assertSame(0, DB::table('product_customer_groups')->where('product_id', $product->id)->count());
        self::assertTrue(DB::table('product_audits')->where('product_id', $product->id)->where('action', 'metadata_changed')->where('context', 'like', '%customer_groups%')->exists());
    }

    public function test_attachments_are_available_for_every_type_while_buyer_downloads_stay_digital(): void
    {
        $p = $this->draft();
        $chart = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('sizes.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=')));
        $p = $this->save($p, ['staged_files' => [$chart], 'file_upload_purposes' => ['attachment']]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id, 'private_file_id' => $chart, 'purpose' => 'attachment']);
        self::assertSame('attachment', app(ProductQueries::class)->editor($p)['files'][0]['purpose']);

        // A physical product cannot gain a buyer download; without a purpose its upload becomes an attachment.
        $book = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('book.pdf', "%PDF-1.4\n%%EOF"));
        $files = array_map(fn (array $f): array => ['id' => $f['id'], 'display_name' => $f['display_name']], app(ProductQueries::class)->editor($p)['files']);
        $this->invalid(fn () => $this->save($p, ['files' => $files, 'staged_files' => [$book], 'file_upload_purposes' => ['download']]), 'file_uploads');
        $p = $this->save($p, ['files' => $files, 'staged_files' => [$book]]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id, 'private_file_id' => $book, 'purpose' => 'attachment']);

        // An attachment does not satisfy a digital product's activation requirement; a download does.
        $files = array_map(fn (array $f): array => ['id' => $f['id'], 'display_name' => $f['display_name']], app(ProductQueries::class)->editor($p->fresh())['files']);
        $this->invalid(fn () => $this->save($p->fresh(), ['type' => 'digital', 'status' => 'active', 'files' => $files]), 'files');
        $download = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('book.txt', 'Book'));
        $p = $this->save($p->fresh(), ['type' => 'digital', 'status' => 'active', 'files' => $files, 'staged_files' => [$download], 'file_upload_purposes' => ['download']]);
        self::assertSame('active', $p->status);
        // Replacing a file keeps its purpose.
        $association = DB::table('product_files')->where('product_id', $p->id)->where('private_file_id', $chart)->sole();
        $next = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('sizes.txt', 'Sizes'));
        $all = array_map(fn (array $f): array => ['id' => $f['id'], 'display_name' => $f['display_name'], ...($f['id'] === (string) $association->id ? ['replacement' => 0] : [])], app(ProductQueries::class)->editor($p)['files']);
        $p = $this->save($p, ['type' => 'digital', 'status' => 'active', 'files' => $all, 'staged_files' => [$next], 'file_upload_purposes' => ['download']]);
        $this->assertDatabaseHas('product_files', ['product_id' => $p->id, 'private_file_id' => $next, 'purpose' => 'attachment']);
        $this->assertDatabaseMissing('product_files', ['product_id' => $p->id, 'purpose' => 'attachment', 'private_file_id' => $chart]);
    }

    public function test_price_history_records_effective_changes_starts_fresh_on_duplicates_and_reaches_the_editor(): void
    {
        $p = $this->draft();
        self::assertSame(0, DB::table('product_price_history')->where('product_id', $p->id)->count());
        $p = $this->save($p, ['price' => '65.00']);
        $p = $this->save($p, ['price' => '65', 'sku' => 'RENAMED']);
        $p = $this->save($p, ['price' => '50.00', 'list_price' => '65.00']);
        $p = $this->save($p, ['price' => '50.00', 'list_price' => '']);
        $rows = DB::table('product_price_history')->where('product_id', $p->id)->orderBy('id')->get();
        self::assertSame([['65.000000', null], ['50.000000', '65.000000'], ['50.000000', null]], $rows->map(fn ($row): array => [$row->price, $row->list_price])->all());
        self::assertSame([(int) $this->actor->id], $rows->pluck('actor_id')->unique()->values()->all());
        // A failed save leaves no history behind.
        $this->invalid(fn () => $this->save($p, ['price' => '40.00', 'box_max_items' => '2']), 'box_length');
        self::assertSame(3, DB::table('product_price_history')->where('product_id', $p->id)->count());

        $copy = $this->products->duplicate((string) $this->actor->id, (string) $p->id, $p->lock_version);
        self::assertSame(['50.000000'], DB::table('product_price_history')->where('product_id', $copy)->pluck('price')->all());
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $page) => $page->has('priceHistory', 3)->where('priceHistory.0.price', '65.00')->where('priceHistory.2.list_price', null));
    }

    public function test_price_history_migration_reconstructs_past_prices_from_the_audit_ledger(): void
    {
        $p = $this->save($this->draft(), ['price' => '65.00']);
        $p = $this->save($p, ['price' => '50.00']);
        $draft = $this->draft();
        $migration = require database_path('migrations/2026_10_14_000800_add_product_attachments_and_price_history.php');
        $migration->down();
        $migration->up();
        self::assertSame(['65.000000', '50.000000'], DB::table('product_price_history')->where('product_id', $p->id)->orderBy('recorded_at')->orderBy('id')->pluck('price')->all());
        self::assertSame(0, DB::table('product_price_history')->where('product_id', $draft->id)->count());
    }
}
