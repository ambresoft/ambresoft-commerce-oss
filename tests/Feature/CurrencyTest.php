<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Application\Service\CurrencyDeletionGuards;
use App\Pricing\Application\Service\PriceDisplay;
use App\Pricing\Domain\Exception\CurrencyUnavailable;
use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Pricing\Infrastructure\Persistence\CurrencyRecord;
use App\Pricing\Infrastructure\Persistence\EloquentCurrencyRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CurrencyTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    private CurrencyAdministration $currencies;

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
        $this->currencies = app(CurrencyAdministration::class);
    }

    /** @return array<string, mixed> */
    private function form(array $changes = []): array
    {
        return array_replace(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'symbol_position' => 'before', 'thousands_separator' => 'comma', 'decimal_separator' => 'period', 'decimals' => '2', 'rate' => '1.1', 'status' => 'active'], $changes);
    }

    private function data(string $code, string $rate, int $decimals = 2, string $status = 'active', string $symbol = '$'): SaveCurrencyData
    {
        return new SaveCurrencyData($code, $code.' name', $symbol, 'before', ',', '.', $decimals, $rate, $status);
    }

    private function add(string $code, string $rate, int $decimals = 2, string $status = 'active', string $symbol = '$'): CurrencyRecord
    {
        return CurrencyRecord::findOrFail($this->currencies->create((string) $this->actor->id, $this->data($code, $rate, $decimals, $status, $symbol)));
    }

    private function product(?string $price, bool $trashed = false): ProductRecord
    {
        $p = ProductRecord::create(['sku' => $price === null ? null : 'SKU-'.uniqid(), 'price' => $price, 'category_id' => $this->category->id, 'deleted_at' => $trashed ? now() : null]);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Product '.$p->id, 'slug' => 'product-'.$p->id]);

        return $p;
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

    private function signIn(UserRecord $user): self
    {
        return $this->actingAs($user)->withSession(['password_hash_web' => $user->getAuthPassword()]);
    }

    private function audits(string $action): int
    {
        return DB::table('currency_audits')->where('action', $action)->count();
    }

    public function test_fixed_sale_prices_convert_with_the_primary_currency_but_percentages_do_not(): void
    {
        DB::table('customer_groups')->insert(['status' => 'active', 'is_default' => true]);
        $product = ProductRecord::create(['price' => '100']);
        $group = DB::table('customer_groups')->where('is_default', true)->value('id');
        DB::table('product_sales')->insert([['product_id' => $product->id, 'customer_group_id' => $group, 'kind' => 'fixed', 'amount' => '70'], ['product_id' => $product->id, 'customer_group_id' => $group, 'kind' => 'percentage', 'amount' => '30']]);
        $usd = $this->add('USD', '2');
        $this->currencies->makePrimary((string) $this->actor->id, (string) $usd->id, $usd->lock_version, 'USD');
        self::assertSame('140.000000', DB::table('product_sales')->where('kind', 'fixed')->value('amount'));
        self::assertSame('30.000000', DB::table('product_sales')->where('kind', 'percentage')->value('amount'));
    }

    public function test_migration_seeds_one_active_primary_and_postgresql_enforces_currency_integrity(): void
    {
        $eur = CurrencyRecord::sole();
        self::assertSame(['EUR', true, 'active', 2], [$eur->code, $eur->is_primary, $eur->status, $eur->decimals]);
        self::assertSame('1.0000000000', $eur->rate);
        $base = ['name' => 'X', 'symbol' => 'X', 'rate' => '1', 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            ['code' => 'USD', 'is_primary' => true], ['code' => 'usd'], ['code' => 'USD', 'rate' => '0'], ['code' => 'USD', 'rate' => '-1'], ['code' => 'USD', 'decimals' => 7],
            ['code' => 'USD', 'thousands_separator' => '.', 'decimal_separator' => '.'], ['code' => 'USD', 'status' => 'deleted'], ['code' => 'EUR'], ['code' => 'USD', 'symbol_position' => 'middle'],
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('currencies')->insert([...$base, ...$row]));
                self::fail('Expected constraint failure for '.json_encode($row));
            } catch (QueryException $e) {
                self::assertContains($e->getCode(), ['23505', '23514'], json_encode($row));
            }
        }
        foreach ([['rate' => '1.5'], ['status' => 'disabled']] as $change) {
            try {
                DB::transaction(fn () => DB::table('currencies')->where('code', 'EUR')->update($change));
                self::fail('A primary currency must keep rate 1 and Active status.');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
        }
        // The deferred trigger rejects a transaction that leaves zero primaries.
        try {
            DB::transaction(function (): void {
                DB::table('currencies')->update(['is_primary' => false]);
                DB::statement('SET CONSTRAINTS currencies_exactly_one_primary IMMEDIATE');
            });
            self::fail('Expected exactly-one-primary enforcement.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
        self::assertTrue(CurrencyRecord::sole()->is_primary);
    }

    public function test_create_validates_unique_iso_codes_rates_precision_separators_and_status(): void
    {
        $this->get('/admin/settings/currencies/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Settings/CurrencyForm')->where('primary.code', 'EUR'));
        $this->post('/admin/settings/currencies', $this->form(['code' => ' usd ']))->assertSessionHasNoErrors()->assertRedirect();
        $usd = CurrencyRecord::where('code', 'USD')->sole();
        self::assertSame(['US Dollar', '1.1000000000', false, 'active', 1], [$usd->name, $usd->rate, $usd->is_primary, $usd->status, $usd->lock_version]);
        self::assertSame(1, $this->audits('created'));
        $this->post('/admin/settings/currencies', $this->form())->assertSessionHasErrors('code');
        foreach (['XXQ', 'US', 'USDD', '123', 'U$D', ''] as $code) {
            $this->post('/admin/settings/currencies', $this->form(['code' => $code]))->assertSessionHasErrors('code');
        }
        foreach (['0', '0.0', '-1', '1e3', 'abc', 'NaN', 'INF', '1.12345678901', '12345678901', '1,1', ''] as $rate) {
            $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'rate' => $rate]))->assertSessionHasErrors('rate');
        }
        foreach (['7', '-1', 'two', '2.5', ''] as $decimals) {
            $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'decimals' => $decimals]))->assertSessionHasErrors('decimals');
        }
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'thousands_separator' => 'comma', 'decimal_separator' => 'comma']))->assertSessionHasErrors('decimal_separator');
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'thousands_separator' => '-']))->assertSessionHasErrors('thousands_separator');
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'status' => 'deleted']))->assertSessionHasErrors('status');
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'symbol_position' => 'middle']))->assertSessionHasErrors('symbol_position');
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP', 'name' => str_repeat('x', 81)]))->assertSessionHasErrors('name');
        self::assertSame(2, CurrencyRecord::count());
        // Space thousands separators survive request trimming because separators travel as tokens.
        $this->post('/admin/settings/currencies', $this->form(['code' => 'CHF', 'symbol' => 'CHF', 'thousands_separator' => 'space', 'decimals' => '3', 'rate' => '0.0000000001', 'status' => 'disabled']))->assertSessionHasNoErrors();
        $chf = CurrencyRecord::where('code', 'CHF')->sole();
        self::assertSame(["\u{00A0}", 3, '0.0000000001', 'disabled'], [$chf->thousands_separator, $chf->decimals, $chf->rate, $chf->status]);
        $this->get('/admin/settings/currencies')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Settings/Currencies')->has('currencies', 3)
            ->where('currencies.0.code', 'EUR')->where('currencies.0.primary', true)->where('currencies.0.sample', '€1,234.50')
            ->where('currencies.1.code', 'CHF')->where('currencies.1.active', false)->where('currencies.1.sample', "CHF1\u{00A0}234.500")
            ->where('currencies.2.rate', '1.1'));
    }

    public function test_every_currency_operation_requires_its_own_server_side_capability(): void
    {
        $this->post('/admin/settings/currencies', $this->form())->assertSessionHasNoErrors();
        $usd = CurrencyRecord::where('code', 'USD')->sole();
        $eur = CurrencyRecord::where('code', 'EUR')->sole();
        $nobody = $this->staff();
        $this->signIn($nobody)->get('/admin/settings/currencies')->assertForbidden();
        $this->get('/admin/settings/currencies/'.$usd->id.'/edit')->assertForbidden();
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($items) => collect($items)->doesntContain('id', 'core.currencies')));
        $viewer = $this->staff(['admin.access', 'currencies.view']);
        $this->signIn($viewer)->get('/admin/settings/currencies')->assertOk();
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($items) => collect($items)->contains('href', '/admin/settings/currencies')));
        $this->get('/admin/settings/currencies/create')->assertForbidden();
        $this->post('/admin/settings/currencies', $this->form(['code' => 'GBP']))->assertForbidden();
        $this->put('/admin/settings/currencies/'.$usd->id, [...$this->form(['rate' => '1.2']), 'code' => null, 'version' => 1])->assertForbidden();
        $this->post('/admin/settings/currencies/'.$usd->id.'/primary', ['version' => 1, 'confirmation' => 'USD'])->assertForbidden();
        $this->delete('/admin/settings/currencies/'.$usd->id, ['version' => 1])->assertForbidden();
        // Changing only the rate needs currencies.rates; formatting needs update; status needs status.
        $rates = $this->staff(['admin.access', 'currencies.view', 'currencies.rates']);
        $update = fn (array $changes) => [...array_diff_key($this->form($changes), ['code' => true]), 'version' => $usd->fresh()->lock_version];
        $this->signIn($rates)->put('/admin/settings/currencies/'.$usd->id, $update(['rate' => '1.2']))->assertSessionHasNoErrors()->assertRedirect('/admin/settings/currencies/'.$usd->id.'/edit');
        self::assertSame('1.2000000000', $usd->fresh()->rate);
        $this->put('/admin/settings/currencies/'.$usd->id, $update(['rate' => '1.2', 'symbol' => 'US$']))->assertForbidden();
        $this->put('/admin/settings/currencies/'.$usd->id, $update(['rate' => '1.2', 'status' => 'disabled']))->assertForbidden();
        $status = $this->staff(['admin.access', 'currencies.view', 'currencies.status']);
        $this->signIn($status)->put('/admin/settings/currencies/'.$usd->id, $update(['rate' => '1.2', 'status' => 'disabled']))->assertSessionHasNoErrors();
        self::assertSame('disabled', $usd->fresh()->status);
        // Hidden buttons are not the boundary: direct Application calls recheck the actor.
        $this->expectException(AuthorizationException::class);
        $this->currencies->makePrimary((string) $viewer->id, (string) $eur->id, $eur->lock_version, 'EUR');
    }

    public function test_primary_cannot_be_disabled_deleted_or_rerated_and_codes_are_immutable(): void
    {
        $eur = CurrencyRecord::where('code', 'EUR')->sole();
        $put = fn (array $changes) => $this->put('/admin/settings/currencies/'.$eur->id, [...array_diff_key($this->form(['name' => 'Euro', 'symbol' => '€', 'rate' => '1', ...$changes]), ['code' => true]), 'version' => $eur->fresh()->lock_version]);
        $put(['status' => 'disabled'])->assertSessionHasErrors('status');
        $put(['rate' => '1.1'])->assertSessionHasErrors('rate');
        $this->delete('/admin/settings/currencies/'.$eur->id, ['version' => $eur->lock_version])->assertSessionHasErrors('currency');
        $this->put('/admin/settings/currencies/'.$eur->id, [...$this->form(['code' => 'USD', 'rate' => '1']), 'version' => $eur->lock_version])->assertSessionHasErrors('code');
        $this->invalid(fn () => $this->currencies->update((string) $this->actor->id, (string) $eur->id, $eur->lock_version, $this->data('USD', '1')), 'code');
        $this->invalid(fn () => $this->currencies->makePrimary((string) $this->actor->id, (string) $eur->id, $eur->lock_version, 'EUR'), 'currency');
        self::assertSame(['EUR', true, 'active', 1], [$eur->fresh()->code, $eur->fresh()->is_primary, $eur->fresh()->status, $eur->fresh()->lock_version]);
        $put(['symbol_position' => 'after', 'thousands_separator' => 'period', 'decimal_separator' => 'comma'])->assertSessionHasNoErrors();
        self::assertSame(1, $this->audits('updated'));
        $this->get('/admin/settings/currencies/'.$eur->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('currency.primary', true)->where('currency.thousands_separator', 'period')->where('primaryChange', null));
    }

    public function test_rate_and_status_changes_affect_later_display_only_and_invalidate_the_cache(): void
    {
        $product = $this->product('100.00');
        $usd = $this->add('USD', '1.1');
        $prices = app(PriceDisplay::class);
        self::assertSame('$110.00', $prices->format('100.00', 'USD'));
        self::assertTrue(Cache::has(EloquentCurrencyRepository::CACHE_KEY));
        $this->currencies->update((string) $this->actor->id, (string) $usd->id, 1, $this->data('USD', '1.25'));
        self::assertFalse(Cache::has(EloquentCurrencyRepository::CACHE_KEY));
        self::assertSame('$125.00', $prices->format('100.00', 'USD'));
        $audit = DB::table('currency_audits')->where('action', 'rate_changed')->sole();
        self::assertEquals(['from' => '1.1', 'to' => '1.25', 'relative_to' => 'EUR'], json_decode($audit->context, true));
        self::assertSame((string) $this->actor->id, (string) $audit->actor_id);
        $this->currencies->update((string) $this->actor->id, (string) $usd->id, 2, $this->data('USD', '1.25', status: 'disabled'));
        self::assertSame(1, $this->audits('status_changed'));
        try {
            $prices->format('100.00', 'USD');
            self::fail('A disabled currency must not be a conversion target.');
        } catch (CurrencyUnavailable $e) {
            self::assertSame('USD', $e->currencyCode);
        }
        $this->expectException(CurrencyUnavailable::class);
        try {
            $prices->format('100.00', 'GBP');
        } finally {
            // Rates and status never touch stored primary prices or product versions.
            self::assertSame(['100.000000', 1], [$product->fresh()->price, $product->fresh()->lock_version]);
        }
    }

    public function test_stale_currency_writes_are_rejected_without_changes(): void
    {
        $usd = $this->add('USD', '1.1');
        $this->currencies->update((string) $this->actor->id, (string) $usd->id, 1, $this->data('USD', '1.2'));
        $this->invalid(fn () => $this->currencies->update((string) $this->actor->id, (string) $usd->id, 1, $this->data('USD', '1.3')), 'version');
        $this->invalid(fn () => $this->currencies->makePrimary((string) $this->actor->id, (string) $usd->id, 1, 'USD'), 'version');
        $this->invalid(fn () => $this->currencies->delete((string) $this->actor->id, (string) $usd->id, 1), 'version');
        self::assertSame(['1.2000000000', 2, false], [$usd->fresh()->rate, $usd->fresh()->lock_version, $usd->fresh()->is_primary]);
        self::assertSame(0, $this->audits('primary_change_failed'));
    }

    public function test_product_editor_and_listing_use_the_primary_currency_and_shared_formatter(): void
    {
        $product = $this->product('1234.5');
        $this->add('USD', '1.1');
        $this->add('JPY', '160.25', 0, symbol: '¥');
        $this->add('GBP', '0.85', status: 'disabled', symbol: '£');
        $this->get('/admin/products')->assertInertia(fn (Assert $p) => $p->where('currency.code', 'EUR')->where('products.data.0.price', '1234.500000')->where('products.data.0.price_formatted', '€1,234.50'));
        $this->get('/admin/products/'.$product->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('currency', ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2])
            ->where('product.price', '1234.50')->where('conversions', [['code' => 'JPY', 'formatted' => '¥197,829'], ['code' => 'USD', 'formatted' => '$1,357.95']]));
        // Primary formatting changes display everywhere without touching the stored amount.
        $eur = CurrencyRecord::where('code', 'EUR')->sole();
        $this->currencies->update((string) $this->actor->id, (string) $eur->id, 1, new SaveCurrencyData('EUR', 'Euro', '€', 'after', '.', ',', 2, '1', 'active'));
        $this->get('/admin/products')->assertInertia(fn (Assert $p) => $p->where('products.data.0.price_formatted', "1.234,50\u{00A0}€"));
        self::assertSame('1234.500000', $product->fresh()->price);
    }

    public function test_primary_precision_drives_price_input_and_cannot_silently_round_stored_prices(): void
    {
        $products = app(ProductAdministration::class);
        $p = ProductRecord::findOrFail($products->draft((string) $this->actor->id));
        $save = fn (string $price) => $products->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->fresh()->lock_version, ['version' => $p->fresh()->lock_version, 'sku' => 'SKU-1', 'type' => 'physical', 'status' => 'disabled', 'price' => $price, 'category_id' => (string) $this->category->id, 'translations' => ['en' => ['name' => 'Chair']], 'images' => [], 'primary_image' => null, 'files' => []]));
        $this->invalid(fn () => $save('1.255'), 'price');
        $save('1.25');
        $eur = CurrencyRecord::where('code', 'EUR')->sole();
        $this->invalid(fn () => $this->currencies->update((string) $this->actor->id, (string) $eur->id, 1, new SaveCurrencyData('EUR', 'Euro', '€', 'before', ',', '.', 1, '1', 'active')), 'decimals');
        $this->currencies->update((string) $this->actor->id, (string) $eur->id, 1, new SaveCurrencyData('EUR', 'Euro', '€', 'before', ',', '.', 3, '1', 'active'));
        $save('1.255');
        self::assertSame('1.255000', $p->fresh()->price);
        $this->invalid(fn () => $this->currencies->update((string) $this->actor->id, (string) $eur->id, 2, new SaveCurrencyData('EUR', 'Euro', '€', 'before', ',', '.', 2, '1', 'active')), 'decimals');
        $this->currencies->update((string) $this->actor->id, (string) $eur->id, 2, new SaveCurrencyData('EUR', 'Euro', '€', 'before', ',', '.', 4, '1', 'active'));
        self::assertSame(4, $eur->fresh()->decimals);
        self::assertSame('1.255000', $p->fresh()->price);
    }

    public function test_primary_change_converts_every_price_rebases_rates_bumps_versions_and_audits(): void
    {
        $products = app(ProductAdministration::class);
        $a = $this->product('19.99');
        $tie = $this->product('1.25');
        $zero = $this->product('0');
        $draft = $this->product(null);
        $trashed = $this->product('10.00', true);
        $usd = $this->add('USD', '1.1');
        $this->add('GBP', '0.85', symbol: '£');
        $jpy = $this->add('JPY', '160.25', 0, symbol: '¥');
        $this->add('CHF', '0.95', status: 'disabled');
        $versions = ProductRecord::withTrashed()->pluck('lock_version', 'id');
        app(PriceDisplay::class)->primary(); // prime the display cache
        $this->get('/admin/settings/currencies/'.$usd->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('primaryChange.products', 4)->where('primaryChange.from', '€100.00')->where('primaryChange.to', '$110.00')->where('primaryChange.error', null)
            ->where('primaryChange.rates', fn ($rates) => collect($rates)->pluck('to', 'code')->all() === ['EUR' => '0.9090909091', 'CHF' => '0.8636363636', 'GBP' => '0.7727272727', 'JPY' => '145.6818181818', 'USD' => '1']));
        // Explicit typed confirmation is required.
        $this->post('/admin/settings/currencies/'.$usd->id.'/primary', ['version' => 1, 'confirmation' => 'usd'])->assertSessionHasErrors('confirmation');
        $this->post('/admin/settings/currencies/'.$usd->id.'/primary', ['version' => 1])->assertSessionHasErrors('confirmation');
        self::assertTrue(CurrencyRecord::where('code', 'EUR')->sole()->is_primary);
        $this->post('/admin/settings/currencies/'.$usd->id.'/primary', ['version' => 1, 'confirmation' => 'USD'])->assertSessionHasNoErrors()->assertRedirect('/admin/settings/currencies')->assertSessionHas('status');
        $change = new PrimaryCurrencyChange;
        $target = new Currency('0', 'USD', 'US Dollar', '$', 'before', ',', '.', 2, '1.1', false, true);
        foreach ([[$a, '19.99', '21.990000'], [$tie, '1.25', '1.380000'], [$zero, '0', '0.000000'], [$trashed, '10.00', '11.000000']] as [$product, $old, $expected]) {
            self::assertSame($expected, ProductRecord::withTrashed()->findOrFail($product->id)->price);
            // PostgreSQL round() and the domain rule agree, including the 1.375 tie.
            self::assertSame($expected, bcadd($change->convertPrice($old, $target), '0', 6));
        }
        self::assertNull($draft->fresh()->price);
        foreach (ProductRecord::withTrashed()->pluck('lock_version', 'id') as $id => $version) {
            self::assertSame($versions[$id] + 1, $version, 'Every product version advances, including drafts and Trash.');
        }
        $rates = CurrencyRecord::pluck('rate', 'code')->all();
        self::assertSame(['1.0000000000', '0.9090909091', '0.7727272727', '145.6818181818', '0.8636363636'], [$rates['USD'], $rates['EUR'], $rates['GBP'], $rates['JPY'], $rates['CHF']]);
        self::assertSame(['USD'], CurrencyRecord::where('is_primary', true)->pluck('code')->all());
        self::assertSame('USD', app(PriceDisplay::class)->primary()->code, 'The display cache was invalidated after commit.');
        $audit = DB::table('currency_audits')->where('action', 'primary_changed')->sole();
        $context = json_decode($audit->context, true);
        self::assertSame(['EUR', 'USD', '1.1', 2, 4], [$context['from'], $context['to'], $context['rate'], $context['decimals'], $context['products_converted']]);
        self::assertEquals(['from' => '1', 'to' => '0.9090909091'], $context['rates']['EUR']);
        self::assertSame((string) $this->actor->id, (string) $audit->actor_id);
        // An editor opened before the change cannot write an old-currency amount.
        $this->invalid(fn () => $products->save((string) $this->actor->id, (string) $a->id, new SaveProductData($versions[$a->id], ['version' => $versions[$a->id], 'sku' => $a->sku, 'type' => 'physical', 'status' => 'disabled', 'price' => '19.99', 'category_id' => (string) $this->category->id, 'translations' => ['en' => ['name' => 'Old']]])), 'version');
        self::assertSame('21.990000', $a->fresh()->price);
        $this->get('/admin/products/'.$a->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('currency.code', 'USD')->where('product.price', '21.99')->where('conversions', fn ($c) => collect($c)->pluck('code')->all() === ['EUR', 'GBP', 'JPY']));
        // Duplication keeps the authoritative (now USD) base price.
        $copy = ProductRecord::findOrFail($products->duplicate((string) $this->actor->id, (string) $a->id, $a->fresh()->lock_version));
        self::assertSame('21.990000', $copy->price);
        // A zero-decimal primary rounds every stored price to whole units, once, from the stored amount.
        $jpy->refresh();
        $this->currencies->makePrimary((string) $this->actor->id, (string) $jpy->id, $jpy->lock_version, 'JPY');
        self::assertSame(['3204.000000', '201.000000', '0.000000'], [$a->fresh()->price, $tie->fresh()->price, $zero->fresh()->price]);
        self::assertNull($draft->fresh()->price);
        self::assertSame(1, CurrencyRecord::where('is_primary', true)->count());
    }

    public function test_failed_primary_changes_roll_back_completely_and_are_audited(): void
    {
        $product = $this->product('99999999999999');
        $usd = $this->add('USD', '2');
        $tiny = $this->add('XAU', '0.0000000001', symbol: 'oz');
        $before = [CurrencyRecord::orderBy('id')->get(['code', 'rate', 'is_primary', 'lock_version'])->toArray(), ProductRecord::withTrashed()->orderBy('id')->get(['price', 'lock_version'])->toArray()];
        $this->invalid(fn () => $this->currencies->makePrimary((string) $this->actor->id, (string) $usd->id, 1, 'USD'), 'currency');
        $this->invalid(fn () => $this->currencies->makePrimary((string) $this->actor->id, (string) $tiny->id, 1, 'XAU'), 'currency');
        // A database failure in the middle of the conversion also rolls back the currency rows already updated.
        $product->update(['price' => '10']);
        DB::unprepared("CREATE FUNCTION test_fail_price_update() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'simulated failure'; END \$\$; CREATE TRIGGER test_fail_price_update BEFORE UPDATE OF price ON products FOR EACH ROW EXECUTE FUNCTION test_fail_price_update();");
        $before[1] = ProductRecord::withTrashed()->orderBy('id')->get(['price', 'lock_version'])->toArray();
        try {
            $this->currencies->makePrimary((string) $this->actor->id, (string) $usd->id, 1, 'USD');
            self::fail('Expected simulated conversion failure.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before[0], CurrencyRecord::orderBy('id')->get(['code', 'rate', 'is_primary', 'lock_version'])->toArray());
        self::assertSame($before[1], ProductRecord::withTrashed()->orderBy('id')->get(['price', 'lock_version'])->toArray());
        self::assertSame(3, $this->audits('primary_change_failed'));
        self::assertSame(0, $this->audits('primary_changed'));
        self::assertSame('EUR', app(PriceDisplay::class)->primary()->code);
    }

    public function test_secondary_deletion_is_audited_and_blocked_for_primary_or_registered_financial_references(): void
    {
        $usd = $this->add('USD', '1.1');
        $gbp = $this->add('GBP', '0.85');
        $product = $this->product('5.00');
        app(CurrencyDeletionGuards::class)->add(fn (string $code): ?string => $code === 'GBP' ? 'currencies.primary_cannot_delete' : null);
        $this->delete('/admin/settings/currencies/'.$gbp->id, ['version' => 1])->assertSessionHasErrors('currency');
        self::assertNotNull($gbp->fresh());
        $this->delete('/admin/settings/currencies/'.$usd->id, ['version' => 1])->assertSessionHasNoErrors()->assertRedirect('/admin/settings/currencies');
        self::assertNull($usd->fresh());
        $audit = DB::table('currency_audits')->where('action', 'deleted')->sole();
        self::assertSame(['USD', (string) $usd->id], [$audit->currency_code, (string) $audit->currency_id]);
        self::assertSame('5.000000', $product->fresh()->price);
        // A deleted code can be configured again; its audit history remains.
        $this->add('USD', '1.2');
        self::assertSame(3, DB::table('currency_audits')->where('currency_code', 'USD')->whereIn('action', ['created', 'deleted'])->count());
    }

    public function test_primary_change_also_converts_list_prices_shipping_costs_and_price_history_without_recording_a_change(): void
    {
        $product = $this->product('10.00');
        DB::table('products')->where('id', $product->id)->update(['list_price' => '20.00', 'shipping_cost' => '2.00']);
        DB::table('product_price_history')->insert([['product_id' => $product->id, 'price' => '12.00', 'list_price' => null, 'recorded_at' => now()->subMonth()], ['product_id' => $product->id, 'price' => '10.00', 'list_price' => '20.00', 'recorded_at' => now()->subDay()]]);
        $usd = $this->add('USD', '1.1');
        $this->currencies->makePrimary((string) $this->actor->id, (string) $usd->id, $usd->lock_version, 'USD');
        $fresh = ProductRecord::findOrFail($product->id);
        self::assertSame(['11.000000', '22.000000', '2.200000'], [$fresh->price, $fresh->list_price, $fresh->shipping_cost]);
        self::assertSame([['13.200000', null], ['11.000000', '22.000000']], DB::table('product_price_history')->where('product_id', $product->id)->orderBy('recorded_at')->get()->map(fn ($row): array => [$row->price, $row->list_price])->all());
    }
}
