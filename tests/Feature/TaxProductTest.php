<?php

namespace Tests\Feature;

use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Infrastructure\Persistence\CurrencyRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Infrastructure\TaxDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class TaxProductTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    private string $greece;

    private string $default;

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
        $areas = app(RateAreaAdministration::class);
        $this->greece = $areas->create($this->id(), new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $this->default = $areas->create($this->id(), new SaveRateAreaData(['en' => 'Elsewhere'], 'active', true));
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    /** @param list<SaveTaxRateData>|null $rates */
    private function tax(string $name, string $percent = '24', int $priority = 0, bool $included = false, string $status = 'active', ?array $rates = null): string
    {
        return app(TaxAdministration::class)->create($this->id(), new SaveTaxData(['en' => $name], null, $priority, $status, $included, 'shipping', $rates ?? [new SaveTaxRateData($this->greece, 'percent', $percent)]));
    }

    private function product(?string $price = '100', bool $trashed = false): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'SKU-'.uniqid(), 'price' => $price, 'category_id' => $this->category->id, 'deleted_at' => $trashed ? now() : null]);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Product '.$p->id, 'slug' => 'product-'.$p->id]);

        return $p;
    }

    /** @return array<string, mixed> */
    private function form(ProductRecord $p, array $changes = []): array
    {
        return array_replace(['version' => $p->fresh()->lock_version, 'sku' => $p->sku, 'type' => 'physical', 'status' => 'disabled', 'price' => rtrim(rtrim((string) $p->fresh()->price, '0'), '.'), 'category_id' => (string) $this->category->id,
            'translations' => ['en' => ['name' => 'Product '.$p->id]], 'images' => [], 'primary_image' => null, 'files' => []], $changes);
    }

    /** @return list<string> */
    private function assigned(ProductRecord $p): array
    {
        return DB::table('product_taxes')->where('product_id', $p->id)->orderBy('tax_id')->pluck('tax_id')->map(strval(...))->all();
    }

    public function test_the_product_editor_assigns_replaces_and_clears_taxes(): void
    {
        $vat = $this->tax('VAT');
        $fee = $this->tax('Eco fee', '2', 1);
        $product = $this->product();
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.tax_ids', [])
            ->where('product.taxes', fn ($options) => collect($options)->pluck('name')->all() === ['VAT', 'Eco fee']));

        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$vat, $fee]]))->assertSessionHasNoErrors();
        self::assertSame([$vat, $fee], $this->assigned($product));
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.tax_ids', [$vat, $fee]));

        // Older clients that omit the field leave assignments untouched.
        $this->post('/admin/products/'.$product->id, $this->form($product, ['price' => '110']))->assertSessionHasNoErrors();
        self::assertSame([$vat, $fee], $this->assigned($product));

        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$fee]]))->assertSessionHasNoErrors();
        self::assertSame([$fee], $this->assigned($product));
        // An empty selection (dropped from multipart bodies) still clears, via the explicit marker.
        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true]))->assertSessionHasNoErrors();
        self::assertSame([], $this->assigned($product));
        // Removing an assignment never deletes the tax.
        self::assertSame(2, DB::table('taxes')->count());
        self::assertSame('110.000000', $product->fresh()->price);
    }

    public function test_invalid_tax_ids_are_rejected_and_the_whole_product_save_rolls_back(): void
    {
        $vat = $this->tax('VAT');
        $disabled = $this->tax('Retired', status: 'disabled');
        $product = $this->product();
        foreach ([['999999'], [$disabled], ['abc'], [$vat, $vat], ['0']] as $ids) {
            $this->from('/admin/products/'.$product->id.'/edit')->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => $ids, 'price' => '555']))->assertSessionHasErrors();
            self::assertSame([], $this->assigned($product));
            self::assertSame('100.000000', $product->fresh()->price);
        }
        // A save that fails elsewhere keeps the previous assignments.
        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$vat]]));
        $this->from('/admin/products/'.$product->id.'/edit')->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [], 'price' => '-1']))->assertSessionHasErrors('price');
        self::assertSame([$vat], $this->assigned($product));
    }

    public function test_a_disabled_tax_stays_assigned_but_cannot_be_newly_assigned(): void
    {
        $vat = $this->tax('VAT');
        $product = $this->product();
        $other = $this->product();
        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$vat]]));
        DB::table('taxes')->where('id', $vat)->update(['status' => 'disabled']);
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.taxes.0.active', false)->where('product.tax_ids', [$vat]));
        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$vat], 'price' => '101']))->assertSessionHasNoErrors();
        self::assertSame([$vat], $this->assigned($product));
        $this->get('/admin/products/'.$other->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.taxes', []));
        $this->from('/admin/products/'.$other->id.'/edit')->post('/admin/products/'.$other->id, $this->form($other, ['taxes_submitted' => true, 'tax_ids' => [$vat]]))->assertSessionHasErrors('tax_ids');
    }

    public function test_duplicates_and_trash_keep_assignments_independent_per_product(): void
    {
        $vat = $this->tax('VAT');
        $source = $this->product();
        $this->post('/admin/products/'.$source->id, $this->form($source, ['taxes_submitted' => true, 'tax_ids' => [$vat]]));
        $copyId = app(ProductAdministration::class)->duplicate($this->id(), (string) $source->id, $source->fresh()->lock_version);
        $copy = ProductRecord::findOrFail($copyId);
        self::assertSame([$vat], $this->assigned($copy));
        // The copy is its own product: changing it leaves the source alone.
        $this->post('/admin/products/'.$copy->id, $this->form($copy, ['sku' => 'COPY', 'taxes_submitted' => true]));
        self::assertSame([[$vat], []], [$this->assigned($source), $this->assigned($copy)]);
    }

    public function test_bulk_assignment_adds_missing_pairs_once_including_trash(): void
    {
        $vat = $this->tax('VAT');
        $fee = $this->tax('Fee', '1');
        $a = $this->product('10');
        $b = $this->product('20');
        $trashed = $this->product('30', trashed: true);
        $this->post('/admin/products/'.$a->id, $this->form($a, ['taxes_submitted' => true, 'tax_ids' => [$vat]]));
        $before = $a->fresh()->lock_version;
        $untouched = $b->fresh()->lock_version;

        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat, $fee], 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertSame([$vat, $fee], $this->assigned($a));
        self::assertSame([$vat, $fee], $this->assigned($b));
        self::assertSame([$vat, $fee], $this->assigned($trashed));
        self::assertSame(6, DB::table('product_taxes')->count());
        // Every product that gained an assignment advances its version, so stale editors cannot overwrite it.
        self::assertSame([$before + 1, $untouched + 1], [$a->fresh()->lock_version, $b->fresh()->lock_version]);
        $this->from('/admin')->post('/admin/products/'.$b->id, $this->form($b, ['version' => $untouched, 'taxes_submitted' => true]))->assertSessionHasErrors('version');

        // Running it again creates nothing and touches nothing.
        $versions = DB::table('products')->orderBy('id')->pluck('lock_version')->all();
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat, $fee], 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertSame(6, DB::table('product_taxes')->count());
        self::assertSame($versions, DB::table('products')->orderBy('id')->pluck('lock_version')->all());
        self::assertSame('10.000000', $a->fresh()->price);
    }

    public function test_bulk_assignment_demands_confirmation_active_taxes_and_both_capabilities(): void
    {
        $vat = $this->tax('VAT');
        $off = $this->tax('Retired', status: 'disabled');
        $this->product();
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat]])->assertSessionHasErrors('confirmed');
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat], 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [], 'confirmed' => true])->assertSessionHasErrors('tax_ids');
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$off], 'confirmed' => true])->assertSessionHasErrors('tax_ids');
        $this->post('/admin/taxes/assign-all', ['tax_ids' => ['999999'], 'confirmed' => true])->assertSessionHasErrors('tax_ids');
        self::assertSame(0, DB::table('product_taxes')->count());

        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'taxes.assign']));
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat], 'confirmed' => true])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'products.update']));
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat], 'confirmed' => true])->assertForbidden();
        self::assertSame(0, DB::table('product_taxes')->count());
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'taxes.assign', 'products.update']));
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$vat], 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('product_taxes')->count());
    }

    public function test_the_calculation_service_reads_assignments_rates_and_settings_from_storage(): void
    {
        $vat = $this->tax('VAT', '24', 1, rates: [new SaveTaxRateData($this->greece, 'percent', '24'), new SaveTaxRateData($this->default, 'percent', '10')]);
        $fee = $this->tax('Eco fee', rates: [new SaveTaxRateData($this->greece, 'fixed', '1.50')]);
        $product = $this->product('100');
        $this->post('/admin/products/'.$product->id, $this->form($product, ['taxes_submitted' => true, 'tax_ids' => [$vat, $fee]]));
        $service = app(TaxCalculationService::class);
        $price = (string) $product->fresh()->price;

        $greek = $service->forProduct((string) $product->id, $price, new TaxContext(new Address('GR'), new Address('GR')));
        self::assertSame('100.00', $greek->net);
        self::assertSame(['Eco fee', 'VAT'], array_map(fn ($t): string => $t->name, $greek->taxes));
        // Priority 0 fixed fee first, then VAT at priority 1 on price + fee: 24% of 101.50 = 24.36.
        self::assertSame(['1.50', '24.36', '25.86', '125.86'], [$greek->taxes[0]->amount, $greek->taxes[1]->amount, $greek->totalTax, $greek->final]);

        // Elsewhere: the default area's 10% applies; the fee has no default rate and is reported.
        $french = $service->forProduct((string) $product->id, $price, new TaxContext(new Address('FR'), new Address('FR')));
        self::assertSame(['10.00', '110.00', false], [$french->totalTax, $french->final, $french->complete()]);
        self::assertSame([$fee], array_map(fn ($t): string => $t->taxId, $french->unresolved()));

        // Settings come from Settings → General.
        (new GeneralSettings)->save($this->id(), ['version' => 1, 'out_of_stock_action' => 'N', 'tax_estimate_without_address' => true, 'tax_default_country' => 'GR', 'tax_calculation_method' => 'subtotal']);
        $estimated = $service->forProduct((string) $product->id, $price);
        self::assertSame('25.86', $estimated->totalTax);
        self::assertSame('subtotal', (new GeneralSettings)->all()['tax_calculation_method']);
        // The tax is disabled: it no longer applies, yet stays assigned.
        DB::table('taxes')->where('id', $vat)->update(['status' => 'disabled']);
        self::assertSame('1.50', $service->forProduct((string) $product->id, $price)->totalTax);
        self::assertSame([$vat, $fee], $this->assigned($product));
        // Prices stay exactly as stored.
        self::assertSame('100.000000', $product->fresh()->price);
    }

    public function test_tax_settings_validate_and_persist_through_the_general_settings_form(): void
    {
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N', 'tax_calculation_method' => 'subtotal', 'display_catalog_prices_with_tax' => true, 'display_cart_prices_with_tax' => true, 'tax_estimate_without_address' => true, 'tax_default_country' => 'gr', 'tax_default_region' => 'a1'])->assertSessionHasNoErrors();
        $row = DB::table('general_settings')->first();
        self::assertSame(['subtotal', true, true, true, 'GR', 'A1'], [$row->tax_calculation_method, $row->display_catalog_prices_with_tax, $row->display_cart_prices_with_tax, $row->tax_estimate_without_address, $row->tax_default_country, $row->tax_default_region]);
        $bad = fn (array $changes) => $this->put('/admin/settings/general', array_replace(['version' => 2, 'out_of_stock_action' => 'N'], $changes));
        $bad(['tax_calculation_method' => 'line'])->assertSessionHasErrors('tax_calculation_method');
        $bad(['tax_default_country' => 'ZZ'])->assertSessionHasErrors('tax_default_country');
        $bad(['tax_default_region' => 'bad code!'])->assertSessionHasErrors('tax_default_region');
        // Estimation needs a country; clearing it while estimating is refused, clearing both is fine.
        $bad(['tax_default_country' => ''])->assertSessionHasErrors('tax_default_country');
        $bad(['tax_estimate_without_address' => false, 'tax_default_country' => '', 'tax_default_region' => ''])->assertSessionHasNoErrors();
        $row = DB::table('general_settings')->first();
        self::assertSame([false, null, null], [(bool) $row->tax_estimate_without_address, $row->tax_default_country, $row->tax_default_region]);
        // Omitted tax fields keep their values (older callers).
        $this->put('/admin/settings/general', ['version' => 3, 'out_of_stock_action' => 'B'])->assertSessionHasNoErrors();
        self::assertSame('subtotal', DB::table('general_settings')->value('tax_calculation_method'));
    }

    public function test_fixed_tax_amounts_follow_primary_currency_changes_and_precision_guards(): void
    {
        $fixed = $this->tax('Fee', rates: [new SaveTaxRateData($this->greece, 'fixed', '5.25'), new SaveTaxRateData($this->default, 'percent', '10')]);
        $currencies = app(CurrencyAdministration::class);
        $usd = $currencies->create($this->id(), new SaveCurrencyData('USD', 'US Dollar', '$', 'before', ',', '.', 2, '1.1', 'active'));
        $version = (int) CurrencyRecord::findOrFail($usd)->lock_version;
        $taxVersion = (int) DB::table('taxes')->where('id', $fixed)->value('lock_version');

        // EUR cannot drop to whole units while a fixed amount has cents.
        $eur = CurrencyRecord::where('is_primary', true)->sole();
        try {
            $currencies->update($this->id(), (string) $eur->id, (int) $eur->lock_version, new SaveCurrencyData('EUR', $eur->name, $eur->symbol, $eur->symbol_position, $eur->thousands_separator, $eur->decimal_separator, 0, '1', 'active'));
            self::fail('Expected the precision guard');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decimals', $e->errors());
        }

        $currencies->makePrimary($this->id(), $usd, $version, 'USD');
        // 5.25 EUR at 1.1 USD per EUR is 5.775, rounded half away from zero to 5.78 USD; percentages never change; the tax advances a version.
        $rates = DB::table('tax_rates')->where('tax_id', $fixed)->get()->keyBy('rate_area_id');
        self::assertSame(['5.780000', '0.10000000'], [$rates[$this->greece]->fixed_amount, $rates[$this->default]->percent_rate]);
        self::assertSame($taxVersion + 1, (int) DB::table('taxes')->where('id', $fixed)->value('lock_version'));
        $result = app(TaxCalculationService::class)->forTaxes([$fixed], '100.00', new TaxContext(new Address('GR')));
        self::assertSame('5.78', $result->totalTax);
    }

    public function test_the_sample_greek_data_is_opt_in_idempotent_and_calculates(): void
    {
        $seeder = app(TaxDemoSeeder::class);
        // Existing demo products receive the standard sample VAT; unrelated products are never touched.
        $demo = $this->product('100');
        DB::table('feature_demo_records')->insert(['key' => 'phase5.product.sample', 'entity' => 'products', 'entity_id' => $demo->id]);
        $other = $this->product('50');

        $first = $seeder->run($this->id());
        // The default area from setUp is reused, so only the two Greek areas are new.
        self::assertSame(['areas' => 2, 'taxes' => 3, 'products' => 1], $first);
        self::assertSame(['areas' => 0, 'taxes' => 0, 'products' => 0], $seeder->run($this->id()));
        self::assertSame([4, 3], [DB::table('rate_areas')->count(), DB::table('taxes')->count()]);
        self::assertCount(1, $this->assigned($demo));
        self::assertSame([], $this->assigned($other));
        self::assertSame([$this->default], DB::table('rate_areas')->where('is_default', true)->pluck('id')->map(strval(...))->all());

        $standard = (string) DB::table('tax_translations')->where('name', 'VAT – standard (sample)')->value('tax_id');
        $service = app(TaxCalculationService::class);
        $mainland = $service->forTaxes([$standard], '124.00', new TaxContext(new Address('GR', 'I')));
        $island = $service->forTaxes([$standard], '117.00', new TaxContext(new Address('GR', 'K')));
        $abroad = $service->forTaxes([$standard], '100.00', new TaxContext(new Address('DE')));
        // Included VAT: 24% mainland, 17% on the Aegean islands (region wins), explicit zero elsewhere.
        self::assertSame(['100.00', '24.00'], [$mainland->net, $mainland->totalTax]);
        self::assertSame(['100.00', '17.00'], [$island->net, $island->totalTax]);
        self::assertSame(['100.00', '0.00', true], [$abroad->net, $abroad->totalTax, $abroad->complete()]);

        // Production is refused.
        $this->app->instance('env', 'production');
        try {
            $this->expectException(\RuntimeException::class);
            $seeder->run($this->id());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
