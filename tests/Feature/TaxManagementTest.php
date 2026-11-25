<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class TaxManagementTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private TaxAdministration $taxes;

    private RateAreaAdministration $areas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->taxes = app(TaxAdministration::class);
        $this->areas = app(RateAreaAdministration::class);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    /** @param list<string> $countries @param array<string, list<string>> $regions */
    private function area(string $name = 'Greece', array $countries = ['GR'], bool $default = false, array $regions = [], string $status = 'active'): string
    {
        return $this->areas->create($this->id(), new SaveRateAreaData(['en' => $name], $status, $default, $countries, $regions));
    }

    /** @param list<SaveTaxRateData> $rates */
    private function data(array $rates = [], string $name = 'VAT', int $priority = 0, string $status = 'active', bool $included = false, string $basis = 'shipping'): SaveTaxData
    {
        return new SaveTaxData(['en' => $name], null, $priority, $status, $included, $basis, $rates);
    }

    private function tax(array $rates = [], string $name = 'VAT', int $priority = 0, string $status = 'active', bool $included = false): string
    {
        return $this->taxes->create($this->id(), $this->data($rates, $name, $priority, $status, $included));
    }

    /** @param callable(): mixed $operation */
    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_rate_areas_hold_countries_regions_and_one_default(): void
    {
        $greece = $this->area();
        $us = $this->area('United States', ['US', 'CA'], regions: ['US' => ['CA', 'OR']]);
        self::assertSame(['US', 'CA'], DB::table('rate_area_countries')->where('rate_area_id', $us)->orderByDesc('country_code')->pluck('country_code')->all());
        self::assertSame(['CA', 'OR'], DB::table('rate_area_regions')->where('rate_area_id', $us)->orderBy('region_code')->pluck('region_code')->all());
        $default = $this->area('Everywhere else', [], default: true);
        self::assertSame(1, DB::table('rate_areas')->where('is_default', true)->count());

        $this->invalid(fn () => $this->area('Second default', [], default: true), 'is_default');
        $this->invalid(fn () => $this->area('Default with members', ['GR'], default: true), 'countries');
        $this->invalid(fn () => $this->area('Nowhere', ['ZZ']), 'countries.0');
        $this->invalid(fn () => $this->area('Empty', []), 'countries');
        $this->invalid(fn () => $this->area('Bad region', ['GR'], regions: ['FR' => ['A']]), 'regions');
        $this->invalid(fn () => $this->area('Bad region code', ['GR'], regions: ['GR' => ['a b']]), 'regions.GR.0');
        // The name is unique per language, ignoring case.
        $this->invalid(fn () => $this->area('GREECE', ['CY']), 'names');
        self::assertSame(3, DB::table('rate_areas')->count());
        self::assertNotSame($greece, $default);
    }

    public function test_rate_area_updates_replace_membership_advance_the_version_and_reject_stale_edits(): void
    {
        $id = $this->area('Balkans', ['GR', 'BG'], regions: ['BG' => ['SOF']]);
        $this->areas->update($this->id(), $id, 1, new SaveRateAreaData(['en' => 'Balkans', 'el' => 'Βαλκάνια'], 'active', false, ['GR', 'RO']));
        self::assertEqualsCanonicalizing(['GR', 'RO'], DB::table('rate_area_countries')->where('rate_area_id', $id)->pluck('country_code')->all());
        self::assertSame(0, DB::table('rate_area_regions')->where('rate_area_id', $id)->count());
        self::assertSame(2, (int) DB::table('rate_areas')->where('id', $id)->value('lock_version'));
        // An unchanged save is not a change.
        $this->areas->update($this->id(), $id, 2, new SaveRateAreaData(['en' => 'Balkans', 'el' => 'Βαλκάνια'], 'active', false, ['GR', 'RO']));
        self::assertSame(2, (int) DB::table('rate_areas')->where('id', $id)->value('lock_version'));
        $this->invalid(fn () => $this->areas->update($this->id(), $id, 1, new SaveRateAreaData(['en' => 'Late'], 'active', false, ['GR'])), 'version');
        $this->areas->update($this->id(), $id, 2, new SaveRateAreaData(['en' => 'Balkans'], 'disabled', false, ['GR']));
        self::assertSame('disabled', DB::table('rate_areas')->where('id', $id)->value('status'));
        self::assertSame(1, DB::table('rate_area_translations')->where('rate_area_id', $id)->count());
    }

    public function test_a_rate_area_used_by_a_tax_cannot_be_deleted(): void
    {
        $used = $this->area('Used');
        $free = $this->area('Free', ['CY']);
        $this->tax([new SaveTaxRateData($used, 'percent', '10')]);
        $this->invalid(fn () => $this->areas->delete($this->id(), $used, 1), 'rate_area');
        self::assertTrue(DB::table('rate_areas')->where('id', $used)->exists());
        $this->areas->delete($this->id(), $free, 1);
        self::assertFalse(DB::table('rate_areas')->where('id', $free)->exists());
        // The database refuses it as well.
        $this->expectException(QueryException::class);
        DB::table('rate_areas')->where('id', $used)->delete();
    }

    public function test_taxes_store_names_flags_and_exact_rates(): void
    {
        $gr = $this->area();
        $fr = $this->area('France', ['FR']);
        $zero = $this->area('Cyprus', ['CY']);
        $id = $this->taxes->create($this->id(), new SaveTaxData(['en' => 'VAT', 'el' => 'ΦΠΑ'], 'EL123', 5, 'active', true, 'billing', [
            new SaveTaxRateData($gr, 'percent', '24'), new SaveTaxRateData($fr, 'fixed', '5.5'), new SaveTaxRateData($zero, 'percent', '0'),
        ]));
        $tax = DB::table('taxes')->where('id', $id)->first();
        self::assertSame(['EL123', 5, 'active', true, 'billing', 1], [$tax->registration_number, $tax->priority, $tax->status, $tax->price_includes_tax, $tax->address_basis, (int) $tax->lock_version]);
        self::assertSame(['en' => 'VAT', 'el' => 'ΦΠΑ'], DB::table('tax_translations')->join('locales', 'locales.id', '=', 'locale_id')->where('tax_id', $id)->pluck('tax_translations.name', 'locales.code')->all());
        $rates = DB::table('tax_rates')->where('tax_id', $id)->orderBy('rate_area_id')->get()->keyBy('rate_area_id');
        // Percentages are exact fractions; fixed amounts are primary-currency decimals.
        self::assertSame('0.24000000', $rates[$gr]->percent_rate);
        self::assertSame('5.500000', $rates[$fr]->fixed_amount);
        self::assertSame(['percent', '0.00000000', null], [$rates[$zero]->rate_type, $rates[$zero]->percent_rate, $rates[$zero]->fixed_amount]);
        $this->get('/admin/taxes/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/Taxes/Form')
            ->where('tax.names.el', 'ΦΠΑ')->where('tax.rates.0.value', '24')->where('tax.rates.1.type', 'fixed')->where('tax.rates.1.value', '5.5')->where('tax.rates.2.value', '0'));
    }

    public function test_invalid_definitions_and_rates_are_rejected_without_creating_anything(): void
    {
        $area = $this->area();
        $rate = fn (string $type, string $value, ?string $areaId = null): array => [new SaveTaxRateData($areaId ?? $area, $type, $value)];
        foreach (['-1', '100.01', '24.12345', 'abc', '1e2', '', '1,5'] as $value) {
            $this->invalid(fn () => $this->tax($rate('percent', $value)), 'rates.0.value');
        }
        foreach (['-0.01', '5.555', 'x', '1e3', '12345678901234567.00'] as $value) {
            $this->invalid(fn () => $this->tax($rate('fixed', $value)), 'rates.0.value');
        }
        $this->invalid(fn () => $this->tax($rate('other', '1')), 'rates.0.type');
        $this->invalid(fn () => $this->tax($rate('percent', '1', '9999')), 'rates.0.area_id');
        $this->invalid(fn () => $this->tax([new SaveTaxRateData($area, 'percent', '1'), new SaveTaxRateData($area, 'fixed', '2')]), 'rates.1.area_id');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['en' => ''], null, 0, 'active', false, 'shipping', [])), 'names.en');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['el' => 'Μόνο ελληνικά'], null, 0, 'active', false, 'shipping', [])), 'names.en');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['en' => 'X'], null, -1, 'active', false, 'shipping', [])), 'priority');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['en' => 'X'], null, 10000, 'active', false, 'shipping', [])), 'priority');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['en' => 'X'], null, 0, 'paused', false, 'shipping', [])), 'status');
        $this->invalid(fn () => $this->taxes->create($this->id(), new SaveTaxData(['en' => 'X'], null, 0, 'active', false, 'delivery', [])), 'address_basis');
        self::assertSame(0, DB::table('taxes')->count());
        self::assertSame(0, DB::table('tax_rates')->count());
        // The database rejects invalid rates too.
        $tax = $this->tax();
        foreach ([['percent', 1.5, null], ['percent', -0.1, null], ['fixed', null, -1], ['percent', null, 5], ['fixed', 0.2, 3]] as [$type, $percent, $fixed]) {
            DB::beginTransaction();
            try {
                DB::table('tax_rates')->insert(['tax_id' => $tax, 'rate_area_id' => $area, 'rate_type' => $type, 'percent_rate' => $percent, 'fixed_amount' => $fixed, 'created_at' => now(), 'updated_at' => now()]);
                self::fail('Expected the rate constraint to refuse the row');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_fixed_amounts_follow_the_primary_currency_precision(): void
    {
        $area = $this->area();
        // EUR has two decimals: three are refused, two are accepted.
        $this->invalid(fn () => $this->tax([new SaveTaxRateData($area, 'fixed', '1.234')]), 'rates.0.value');
        $id = $this->tax([new SaveTaxRateData($area, 'fixed', '1.23')]);
        self::assertSame('1.230000', DB::table('tax_rates')->where('tax_id', $id)->value('fixed_amount'));
    }

    public function test_updates_edit_rates_status_and_priority_and_respect_versions(): void
    {
        $a = $this->area();
        $b = $this->area('France', ['FR']);
        $id = $this->tax([new SaveTaxRateData($a, 'percent', '24')]);
        $this->taxes->update($this->id(), $id, 1, $this->data([new SaveTaxRateData($a, 'percent', '23'), new SaveTaxRateData($b, 'fixed', '2')], 'VAT', 3, 'disabled', true, 'billing'));
        $row = DB::table('taxes')->where('id', $id)->first();
        self::assertSame([3, 'disabled', true, 'billing', 2], [$row->priority, $row->status, $row->price_includes_tax, $row->address_basis, (int) $row->lock_version]);
        self::assertSame(['0.23000000', null], [DB::table('tax_rates')->where('tax_id', $id)->where('rate_area_id', $a)->value('percent_rate'), DB::table('tax_rates')->where('tax_id', $id)->where('rate_area_id', $b)->value('percent_rate')]);
        // Dropping a rate removes it; keeping everything as is is a no-op.
        $same = $this->data([new SaveTaxRateData($a, 'percent', '23')], 'VAT', 3, 'disabled', true, 'billing');
        $this->taxes->update($this->id(), $id, 2, $same);
        self::assertSame(1, DB::table('tax_rates')->where('tax_id', $id)->count());
        self::assertSame(3, (int) DB::table('taxes')->where('id', $id)->value('lock_version'));
        $this->taxes->update($this->id(), $id, 3, $same);
        self::assertSame(3, (int) DB::table('taxes')->where('id', $id)->value('lock_version'));
        $this->invalid(fn () => $this->taxes->update($this->id(), $id, 2, $same), 'version');
        $this->invalid(fn () => $this->taxes->update($this->id(), '999999', 1, $same), 'version');
    }

    public function test_deleting_a_tax_removes_its_rates_but_is_refused_while_products_use_it(): void
    {
        $area = $this->area();
        $id = $this->tax([new SaveTaxRateData($area, 'percent', '10')]);
        $categoryId = DB::table('categories')->insertGetId(['position' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['sku' => 'S-1', 'price' => '10', 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_taxes')->insert(['product_id' => $product, 'tax_id' => $id]);
        $this->invalid(fn () => $this->taxes->delete($this->id(), $id, 1), 'tax');
        self::assertSame(1, DB::table('product_taxes')->count());
        DB::table('product_taxes')->delete();
        $this->taxes->delete($this->id(), $id, 1);
        self::assertSame([0, 0, 0], [DB::table('taxes')->count(), DB::table('tax_rates')->count(), DB::table('tax_translations')->count()]);
        self::assertTrue(DB::table('rate_areas')->where('id', $area)->exists());
    }

    public function test_every_route_and_operation_enforces_the_matching_capability(): void
    {
        $area = $this->area();
        $id = $this->tax([new SaveTaxRateData($area, 'percent', '10')]);
        $nobody = $this->staff();
        $this->actingAs($nobody);
        foreach (['/admin/taxes', '/admin/taxes/create', '/admin/taxes/'.$id.'/edit', '/admin/rate-areas', '/admin/rate-areas/create', '/admin/rate-areas/'.$area.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $payload = ['names' => ['en' => 'Hack'], 'priority' => 0, 'status' => 'active', 'price_includes_tax' => false, 'address_basis' => 'shipping', 'rates' => []];
        $this->post('/admin/taxes', $payload)->assertForbidden();
        $this->put('/admin/taxes/'.$id, [...$payload, 'version' => 1])->assertForbidden();
        $this->delete('/admin/taxes/'.$id, ['version' => 1])->assertForbidden();
        $this->post('/admin/taxes/assign-all', ['tax_ids' => [$id], 'confirmed' => true])->assertForbidden();
        $this->post('/admin/rate-areas', ['names' => ['en' => 'X'], 'status' => 'active', 'is_default' => false, 'countries' => ['GR'], 'regions' => []])->assertForbidden();
        $this->delete('/admin/rate-areas/'.$area, ['version' => 1])->assertForbidden();
        // Direct Application callers are checked too.
        try {
            $this->taxes->create((string) $nobody->id, $this->data());
            self::fail('Expected an authorization failure');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        self::assertSame(1, DB::table('taxes')->count());

        // Viewing is separate from changing.
        $viewer = $this->staff(['admin.access', 'taxes.view', 'rate-areas.view']);
        // A new identity needs a new session: the session guard binds a session to one password hash.
        $this->flushSession();
        $this->actingAs($viewer);
        $this->get('/admin/taxes/'.$id.'/edit')->assertOk();
        $this->get('/admin/rate-areas')->assertOk();
        $this->put('/admin/taxes/'.$id, [...$payload, 'version' => 1])->assertForbidden();
        $this->post('/admin/rate-areas', ['names' => ['en' => 'X'], 'status' => 'active', 'is_default' => false, 'countries' => ['GR'], 'regions' => []])->assertForbidden();
        $this->get('/admin/taxes/create')->assertForbidden();
    }

    public function test_the_admin_screens_list_filter_sort_and_save_through_http(): void
    {
        $gr = $this->area();
        $this->tax([new SaveTaxRateData($gr, 'percent', '24')], 'Standard VAT', 2);
        $this->tax([new SaveTaxRateData($gr, 'fixed', '1.5')], 'Eco fee', 1, 'disabled');
        $this->get('/admin/taxes?sort=name')->assertInertia(fn (Assert $page) => $page->component('Admin/Taxes/Index')
            ->where('taxes.data.0.name', 'Eco fee')->where('taxes.data.1.name', 'Standard VAT')->where('taxes.data.1.rates.0.label', '24%')->where('taxes.data.0.rates.0.label', '€1.50'));
        $this->get('/admin/taxes')->assertInertia(fn (Assert $page) => $page->where('taxes.data.0.name', 'Eco fee')->where('taxes.total', 2));
        $this->get('/admin/taxes?status=active')->assertInertia(fn (Assert $page) => $page->where('taxes.total', 1)->where('taxes.data.0.name', 'Standard VAT'));
        $this->get('/admin/taxes?search=eco')->assertInertia(fn (Assert $page) => $page->where('taxes.total', 1));
        $this->get('/admin/taxes?sort=drop')->assertSessionHasErrors('sort');

        $this->post('/admin/taxes', ['names' => ['en' => 'Luxury', 'el' => ''], 'registration_number' => ' R-1 ', 'priority' => '4', 'status' => 'active', 'price_includes_tax' => false, 'address_basis' => 'billing', 'rates' => [['area_id' => $gr, 'type' => 'percent', 'value' => '30']]])->assertSessionHasNoErrors()->assertRedirect();
        $created = DB::table('taxes')->where('priority', 4)->first();
        self::assertSame('R-1', $created->registration_number);
        $this->from('/admin/taxes/'.$created->id.'/edit')->put('/admin/taxes/'.$created->id, ['version' => 1, 'names' => ['en' => ''], 'priority' => '4', 'status' => 'active', 'price_includes_tax' => false, 'address_basis' => 'billing', 'rates' => [['area_id' => $gr, 'type' => 'percent', 'value' => '-3']]])
            ->assertSessionHasErrors(['names.en', 'rates.0.value']);
        self::assertSame('0.30000000', DB::table('tax_rates')->where('tax_id', $created->id)->value('percent_rate'));
    }

    public function test_changes_reach_the_central_audit_ledger_with_the_actor(): void
    {
        $area = $this->area();
        $id = $this->tax([new SaveTaxRateData($area, 'percent', '24')]);
        $this->taxes->update($this->id(), $id, 1, $this->data([new SaveTaxRateData($area, 'percent', '13')]));
        $rows = DB::table('audit_logs')->whereIn('entity_type', ['tax', 'tax_rate', 'rate_area', 'rate_area_country', 'tax_translation'])->get();
        self::assertEqualsCanonicalizing(['tax', 'tax_rate', 'rate_area', 'rate_area_country', 'tax_translation'], $rows->pluck('entity_type')->unique()->values()->all());
        self::assertSame([$this->actor->email], $rows->pluck('actor_email')->unique()->all());
        $rate = $rows->firstWhere('entity_type', 'tax_rate');
        self::assertStringContainsString('percent_rate', $rate->changes);
    }

    public function test_the_tax_migration_round_trips_without_touching_products_or_prices(): void
    {
        $migration = require database_path('migrations/2026_10_12_001800_create_tax_management.php');
        // Shipping methods reference taxes and areas, so they unwind first.
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
        $categoryId = DB::table('categories')->insertGetId(['position' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['sku' => 'KEEP', 'price' => '12.500000', 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()]);
        $newsletters->down();
        $images->down();
        $shipping->down();
        $migration->down();
        foreach (['taxes', 'tax_rates', 'rate_areas', 'product_taxes'] as $table) {
            self::assertFalse(Schema::hasTable($table));
        }
        self::assertFalse(Schema::hasColumn('general_settings', 'tax_calculation_method'));
        self::assertSame('12.500000', DB::table('products')->where('id', $product)->value('price'));
        $migration->up();
        $shipping->up();
        $images->up();
        $newsletters->up();
        $newsletterBlock->up();
        $promotions->up();
        $deals->up();
        $reels->up();
        self::assertSame(['unit_price', false], [DB::table('general_settings')->value('tax_calculation_method'), (bool) DB::table('general_settings')->value('display_catalog_prices_with_tax')]);
        self::assertSame(0, DB::table('product_taxes')->count());
        self::assertSame('12.500000', DB::table('products')->where('id', $product)->value('price'));
    }
}
