<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Order\Application\Contract\OrderMailTransport;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderDemoSeeder;
use App\Order\Infrastructure\OrderEmailDefaults;
use App\Order\Infrastructure\OrderEmailTemplates;
use App\Order\Infrastructure\OrderNotifier;
use App\Order\Infrastructure\OrderOrigin;
use App\Order\Infrastructure\OrderStatuses;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\Fakes\RecordingMailTransport;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    private OrderAdministration $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        DB::table('company_settings')->where('id', 1)->update(['name' => 'Test Store', 'email_orders' => 'enquiries@store.example']);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'orders@store.example', 'order_email_reply_to' => 'help@store.example']);
        $this->orders = app(OrderAdministration::class);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    private function mail(): RecordingMailTransport
    {
        return app(OrderMailTransport::class);
    }

    private function product(string $price = '10', int $quantity = 10, string $name = 'Chair', string $action = 'N'): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'SKU-'.uniqid(), 'price' => $price, 'quantity' => $quantity, 'out_of_stock_actions' => $action, 'category_id' => $this->category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => $name, 'slug' => 'p-'.$p->id]);

        return $p;
    }

    private function statusId(string $code): string
    {
        return (string) DB::table('order_statuses')->where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    private function payload(array $items, array $changes = []): array
    {
        return array_replace([
            'locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Example', 'email' => ' Maria@Example.test ', 'phone' => '+30 210 000 0000',
            'billing' => ['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'],
            'shipping_same' => true, 'items' => $items, 'status_id' => $this->statusId('open'),
            'customer_note' => 'Leave at the door', 'internal_note' => 'VIP do-not-share',
        ], $changes);
    }

    private function create(array $items, array $changes = []): string
    {
        return $this->orders->create($this->id(), $this->payload($items, $changes));
    }

    private function version(string $order): int
    {
        return (int) DB::table('orders')->where('id', $order)->value('lock_version');
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    private function vat(ProductRecord $product, string $percent = '24', bool $included = false): string
    {
        $area = app(RateAreaAdministration::class)->create($this->id(), new SaveRateAreaData(['en' => 'Greece '.uniqid()], 'active', false, ['GR']));
        $tax = app(TaxAdministration::class)->create($this->id(), new SaveTaxData(['en' => 'VAT '.$percent], null, 0, 'active', $included, 'billing', [new SaveTaxRateData($area, 'percent', $percent)]));
        DB::table('product_taxes')->insert(['product_id' => $product->id, 'tax_id' => $tax]);

        return $tax;
    }

    public function test_bulk_status_reuses_stock_history_and_email_rules_and_skips_unchanged_orders(): void
    {
        $product = $this->product('10', 10);
        $a = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $b = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]]);
        $this->orders->changeStatus($this->id(), $a, $this->version($a), $this->statusId('processing'));
        $sent = count($this->mail()->sent);
        $this->patch('/admin/orders/bulk-status', ['orders' => [['id' => $a, 'version' => $this->version($a)], ['id' => $b, 'version' => $this->version($b)]], 'status_id' => $this->statusId('processing'), 'return_to' => '/admin/orders?status=1&page=2'])
            ->assertRedirect('/admin/orders?status=1&page=2')->assertSessionHasNoErrors();
        self::assertSame(5, $product->refresh()->quantity);
        self::assertSame($this->statusId('processing'), (string) DB::table('orders')->where('id', $b)->value('status_id'));
        self::assertSame($sent + 1, count($this->mail()->sent));
        self::assertSame(2, DB::table('order_status_history')->where('order_id', $a)->count());
        self::assertSame(2, DB::table('order_status_history')->where('order_id', $b)->count());
        $this->orders->bulkStatus($this->id(), [['id' => $a, 'version' => $this->version($a)], ['id' => $b, 'version' => $this->version($b)]], $this->statusId('cancelled'));
        self::assertSame(10, $product->refresh()->quantity);
    }

    public function test_bulk_status_rolls_back_every_order_and_email_on_stale_or_stock_failure(): void
    {
        $product = $this->product('10', 4);
        $a = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]]);
        $b = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]]);
        $sent = count($this->mail()->sent);
        foreach ([0, 1] as $version) {
            $this->patch('/admin/orders/bulk-status', ['orders' => [['id' => $a, 'version' => 1], ['id' => $b, 'version' => $version]], 'status_id' => $this->statusId('processing')])->assertSessionHasErrors();
            self::assertSame(4, $product->refresh()->quantity);
            self::assertSame(2, DB::table('orders')->whereIn('id', [$a, $b])->where('status_id', $this->statusId('open'))->count());
            self::assertSame(2, DB::table('order_status_history')->whereIn('order_id', [$a, $b])->count());
            self::assertSame($sent, count($this->mail()->sent));
        }
        $this->invalid(fn () => $this->orders->bulkStatus($this->id(), [['id' => $a, 'version' => 1], ['id' => $b, 'version' => 2]], $this->statusId('cancelled')), 'version');
        self::assertSame(2, DB::table('order_status_history')->whereIn('order_id', [$a, $b])->count());
    }

    public function test_bulk_status_rejects_invalid_selection_unavailable_status_and_missing_permission(): void
    {
        $product = $this->product();
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        foreach ([[], [['id' => $order, 'version' => 1], ['id' => $order, 'version' => 1]], [['id' => '999999999', 'version' => 1]], array_fill(0, 101, ['id' => $order, 'version' => 1])] as $selection) {
            $this->patch('/admin/orders/bulk-status', ['orders' => $selection, 'status_id' => $this->statusId('processing')])->assertSessionHasErrors();
        }
        DB::table('order_statuses')->where('id', $this->statusId('completed'))->update(['is_active' => false]);
        $this->patch('/admin/orders/bulk-status', ['orders' => [['id' => $order, 'version' => 1]], 'status_id' => $this->statusId('completed')])->assertSessionHasErrors('status_id');

    }

    public function test_an_order_snapshots_customer_items_and_taxes_records_history_and_emails_after_commit(): void
    {
        $product = $this->product('10.00', 5, 'Desk lamp');
        $this->vat($product);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'maria@example.test', 'first_name' => 'Maria', 'last_name' => 'Example', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::transaction(function () use ($product, $customer): string {
            $id = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]], ['customer_id' => $customer]);
            self::assertSame([], $this->mail()->sent, 'Nothing is sent before the transaction commits');

            return $id;
        });
        $order = DB::table('orders')->where('id', $id)->sole();
        self::assertMatchesRegularExpression('/^1\d{5,}$/', $order->order_number);
        self::assertSame('maria@example.test', $order->email);
        self::assertSame((int) $customer, (int) $order->customer_id);
        self::assertNull($order->ip_address);
        self::assertSame(['EUR', 2], [$order->currency_code, (int) $order->currency_decimals]);
        self::assertSame(['30.000000', '7.200000', '0.000000', '37.200000'], [$order->subtotal, $order->tax_total, $order->tax_included, $order->total]);
        $item = DB::table('order_items')->where('order_id', $id)->sole();
        self::assertSame(['Desk lamp', $product->sku, 3, '10.000000', '30.000000', '7.200000', '37.200000'], [$item->name, $item->sku, (int) $item->quantity, $item->unit_price, $item->subtotal, $item->tax_total, $item->total]);
        self::assertSame('7.200000', DB::table('order_taxes')->where('order_id', $id)->sole()->amount);
        $history = DB::table('order_status_history')->where('order_id', $id)->sole();
        self::assertNull($history->from_status_id);
        self::assertSame($this->statusId('open'), (string) $history->to_status_id);
        self::assertSame('sent', DB::table('order_notifications')->where('history_id', $history->id)->value('state'));
        self::assertCount(1, $this->mail()->sent);
        $mail = $this->mail()->sent[0];
        self::assertSame(['orders@store.example', 'Test Store', 'maria@example.test', 'help@store.example'], [$mail->fromAddress, $mail->fromName, $mail->to, $mail->replyTo]);
        self::assertSame('Your order '.$order->order_number.' at Test Store', $mail->subject);
        self::assertStringContainsString('Desk lamp', $mail->html);
        self::assertStringContainsString('€37.20', $mail->html);
        // Private data never reaches the customer.
        self::assertStringNotContainsString('VIP do-not-share', $mail->html.$mail->text);
        self::assertStringContainsString('Leave at the door', DB::table('order_email_templates')->where('status_id', $this->statusId('open'))->value('body_html').'Leave at the door');
    }

    public function test_guest_orders_and_snapshots_survive_later_catalog_and_customer_changes(): void
    {
        $product = $this->product('20', 5, 'Original name');
        $id = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        self::assertNull(DB::table('orders')->where('id', $id)->value('customer_id'));
        $product->update(['price' => '99', 'sku' => 'CHANGED']);
        $product->translations()->update(['name' => 'Renamed']);
        DB::table('product_taxes')->insert(['product_id' => $product->id, 'tax_id' => $this->vat($this->product())]);
        $item = DB::table('order_items')->where('order_id', $id)->sole();
        self::assertSame(['Original name', '20.000000'], [$item->name, $item->unit_price]);
        self::assertSame('20.000000', DB::table('orders')->where('id', $id)->value('total'));
        // A permanently deleted product leaves a readable line without its link.
        DB::table('products')->where('id', $product->id)->delete();
        $this->get('/admin/orders/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('order.items.0.name', 'Original name')->where('order.items.0.product_id', null));
    }

    public function test_order_numbers_are_unique_and_never_reused_after_a_failed_creation(): void
    {
        $product = $this->product();
        $first = DB::table('orders')->where('id', $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]))->value('order_number');
        try {
            DB::transaction(function () use ($product): void {
                $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $third = DB::table('orders')->where('id', $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]))->value('order_number');
        self::assertSame((int) $first + 2, (int) $third, 'The rolled-back number is skipped, never reused');
        $this->expectException(QueryException::class);
        DB::table('orders')->where('order_number', $third)->update(['order_number' => $first]);
    }

    public function test_invalid_lines_amounts_and_statuses_are_rejected_without_a_partial_order(): void
    {
        $product = $this->product();
        $trashed = $this->product();
        $trashed->update(['deleted_at' => now()]);
        $good = ['product_id' => (string) $product->id, 'quantity' => 1];
        $this->invalid(fn () => $this->create([]), 'items');
        $this->invalid(fn () => $this->create([['product_id' => (string) $product->id, 'quantity' => 0]]), 'items.0.quantity');
        $this->invalid(fn () => $this->create([$good, ['product_id' => (string) $trashed->id, 'quantity' => 1]]), 'items.1.product_id');
        $this->invalid(fn () => $this->create([$good, $good]), 'items.1.product_id');
        $this->invalid(fn () => $this->create([['product_id' => (string) $product->id, 'quantity' => 1, 'unit_price' => '-5']]), 'items.0.unit_price');
        $this->invalid(fn () => $this->create([['product_id' => (string) $product->id, 'quantity' => 1, 'unit_price' => '1.005']]), 'items.0.unit_price');
        $this->invalid(fn () => $this->create([$good], ['email' => 'not-an-email']), 'email');
        $this->invalid(fn () => $this->create([$good], ['billing' => ['first_name' => 'A', 'last_name' => 'B', 'line1' => 'x', 'city' => 'y', 'country_code' => 'XX']]), 'billing.country_code');
        DB::table('order_statuses')->where('code', 'incomplete')->update(['is_active' => false]);
        $this->invalid(fn () => $this->create([$good], ['status_id' => $this->statusId('incomplete')]), 'status_id');
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
        self::assertSame([], $this->mail()->sent);
        // An order-specific price never changes the catalog.
        $id = $this->create([['product_id' => (string) $product->id, 'quantity' => 2, 'unit_price' => '7.50']]);
        self::assertSame(['7.500000', '10.000000', true], [DB::table('order_items')->where('order_id', $id)->value('unit_price'), DB::table('order_items')->where('order_id', $id)->value('catalog_price'), (bool) DB::table('order_items')->where('order_id', $id)->value('price_overridden')]);
        self::assertSame('10.000000', $product->fresh()->price);
        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $id)->update(['total' => '1']);
    }

    public function test_every_order_route_and_service_enforces_its_capability(): void
    {
        $product = $this->product();
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        $viewer = $this->staff(['admin.access', 'orders.view']);
        $this->actingAs($viewer);
        $this->get('/admin/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSeeIp', false)->where('orders.data.0.ip_address', null));
        $this->get('/admin/orders/'.$order)->assertOk();
        $this->get('/admin/orders/create')->assertForbidden();
        $this->patch('/admin/orders/bulk-status', ['orders' => [['id' => $order, 'version' => $this->version($order)]], 'status_id' => $this->statusId('processing')])->assertForbidden();
        $this->post('/admin/orders', $this->payload([['product_id' => (string) $product->id, 'quantity' => 1]]))->assertForbidden();
        $this->get('/admin/orders/'.$order.'/edit')->assertForbidden();
        $this->patch('/admin/orders/'.$order.'/status', ['status_id' => $this->statusId('processing'), 'version' => $this->version($order)])->assertForbidden();
        $this->post('/admin/orders/'.$order.'/comments', ['visibility' => 'internal', 'body' => 'x'])->assertForbidden();
        $this->post('/admin/orders/'.$order.'/notifications/1/retry')->assertForbidden();
        $this->get('/admin/order-statuses')->assertForbidden();
        $this->post('/admin/order-statuses/order', ['ids' => DB::table('order_statuses')->pluck('id')->map(strval(...))->all()])->assertForbidden();
        $this->get('/admin/order-emails')->assertForbidden();
        $template = (string) DB::table('order_email_templates')->value('id');
        $this->put('/admin/order-emails/'.$this->statusId('open').'/templates/'.$template, ['version' => 1, 'subject' => 'x', 'body' => '<p>x</p>'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->orders->changeStatus((string) $viewer->id, $order, null, $this->statusId('processing'));
    }

    public function test_order_lines_keep_the_brand_they_were_sold_under(): void
    {
        $brand = DB::table('brands')->insertGetId(['code' => 'acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_translations')->insert(['brand_id' => $brand, 'locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Acme']);
        $branded = $this->product(name: 'Branded');
        $branded->forceFill(['brand_id' => $brand])->save();
        $plain = $this->product(name: 'Plain');
        $order = $this->create([['product_id' => (string) $branded->id, 'quantity' => 1], ['product_id' => (string) $plain->id, 'quantity' => 1]]);
        $lines = fn () => DB::table('order_items')->where('order_id', $order)->orderBy('position')->get(['brand_id', 'brand_name'])->map(fn ($l) => [$l->brand_id === null ? null : (int) $l->brand_id, $l->brand_name])->all();
        self::assertSame([[$brand, 'Acme'], [null, null]], $lines());
        // A later brand change on the product does not rewrite the sale.
        $branded->forceFill(['brand_id' => null])->save();
        self::assertSame([[$brand, 'Acme'], [null, null]], $lines());
    }

    public function test_listing_filters_sorts_and_paginates_in_the_database(): void
    {
        $product = $this->product();
        $a = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]], ['first_name' => 'Alpha', 'email' => 'alpha@example.test']);
        $b = $this->create([['product_id' => (string) $product->id, 'quantity' => 5]], ['first_name' => 'Beta', 'email' => 'beta@example.test', 'phone' => '+30 690 123 4567']);
        $c = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]], ['first_name' => 'Gamma', 'email' => 'gamma@example.test']);
        $this->orders->changeStatus($this->id(), $b, null, $this->statusId('processing'));
        DB::table('orders')->where('id', $a)->update(['created_at' => now()->subDays(10)]);
        $ids = fn (string $query): array => collect($this->get('/admin/orders?'.$query)->assertOk()->viewData('page')['props']['orders']['data'])->pluck('id')->all();
        self::assertSame([$c, $b, $a], $ids(''));
        self::assertSame([$b], $ids('status='.$this->statusId('processing')));
        self::assertSame([$b], $ids('search=690%20123'));
        self::assertSame([$c], $ids('search=gamma'));
        DB::table('orders')->where('id', $b)->update(['invoice_requested' => true]);
        self::assertSame([$b], $ids('invoice=1'));
        self::assertSame([$c, $a], $ids('invoice=0'));
        $this->get('/admin/orders?invoice=yes')->assertSessionHasErrors('invoice');
        self::assertSame([$b, $c], $ids('total_min=30&sort=total&direction=desc'));
        self::assertSame([$a], $ids('created_to='.now()->subDays(5)->toDateString()));
        self::assertSame([$a, $b, $c], $ids('sort=number&direction=asc'));
        self::assertSame([$a, $b, $c], $ids('sort=customer&direction=asc'));
        DB::table('orders')->update(['created_at' => now()]);
        self::assertSame([$c, $b, $a], $ids('sort=created_at'), 'Equal dates fall back to the ID');
        $this->get('/admin/orders?per_page=25&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 0)->where('orders.total', 3));
        $this->get('/admin/orders?sort=drop_table')->assertSessionHasErrors('sort');
    }

    public function test_customer_ip_comes_only_from_the_trusted_request_context(): void
    {
        $request = Request::create('/checkout', 'POST', ['ip_address' => '6.6.6.6'], server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        self::assertSame('203.0.113.7', OrderOrigin::fromRequest($request), 'An untrusted forwarded-for header is ignored');
        self::assertSame('2001:db8::1', OrderOrigin::fromRequest(Request::create('/', server: ['REMOTE_ADDR' => '2001:0db8:0000::0001'])));
        self::assertNull(OrderOrigin::valid('not-an-ip'));
        $product = $this->product();
        $id = $this->orders->create(null, $this->payload([['product_id' => (string) $product->id, 'quantity' => 1]]), 'checkout', OrderOrigin::fromRequest($request));
        self::assertSame('203.0.113.7', DB::table('orders')->where('id', $id)->value('ip_address'));
        self::assertSame('checkout', DB::table('order_status_history')->where('order_id', $id)->value('source'));
        // Staff-created orders never store a submitted value, nor the administrator's own IP.
        $this->post('/admin/orders', [...$this->payload([['product_id' => (string) $product->id, 'quantity' => 1]]), 'ip_address' => '6.6.6.6'], ['REMOTE_ADDR' => '192.0.2.10'])->assertRedirect();
        self::assertNull(DB::table('orders')->orderByDesc('id')->value('ip_address'));
        $this->get('/admin/orders?ip=203.0.113.7')->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.ip_address', '203.0.113.7'));
        self::assertStringNotContainsString('203.0.113.7', implode('', array_map(fn ($m): string => $m->html.$m->text, $this->mail()->sent)));
    }

    public function test_all_customer_sales_apply_without_a_group_and_compete_with_eligible_group_rules(): void
    {
        $product = $this->product('100', 100);
        $global = (string) DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => null, 'kind' => 'percentage', 'amount' => '30']);
        $sales = app(ProductSales::class);
        self::assertSame(['id' => $global, 'price' => '70.00'], $sales->price((string) $product->id, '100', 1, null, 2));
        $guest = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        self::assertSame('70.000000', DB::table('order_items')->where('order_id', $guest)->value('unit_price'));
        $group = (string) DB::table('customer_groups')->insertGetId(['status' => 'active']);
        $specific = (string) DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => $group, 'kind' => 'fixed', 'amount' => '60', 'priority' => 0]);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'global-sale@example.test', 'first_name' => 'Sale', 'last_name' => 'Customer']);
        self::assertSame(['id' => $global, 'price' => '70.00'], $sales->price((string) $product->id, '100', 1, $customer, 2));
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        self::assertSame(['id' => $specific, 'price' => '60.00'], $sales->price((string) $product->id, '100', 1, $customer, 2));
        DB::table('customer_groups')->where('id', $group)->update(['status' => 'disabled']);
        self::assertSame(['id' => $global, 'price' => '70.00'], $sales->price((string) $product->id, '100', 1, $customer, 2));
    }

    public function test_sale_stock_is_reserved_on_creation_released_on_cancel_and_preserves_saved_prices(): void
    {
        DB::table('customer_groups')->insert(['status' => 'active', 'is_default' => true]);
        $product = $this->product('100', 100);
        $sale = (string) DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => DB::table('customer_groups')->where('is_default', true)->value('id'), 'kind' => 'percentage', 'amount' => '30', 'stock_limit' => 2]);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $item = DB::table('order_items')->where('order_id', $order)->sole();
        self::assertSame('70.000000', $item->unit_price);
        self::assertFalse($item->price_overridden);
        $this->assertDatabaseHas('product_sale_allocations', ['item_id' => $item->id, 'sale_id' => $sale, 'quantity' => 2]);
        $other = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        self::assertSame('100.000000', DB::table('order_items')->where('order_id', $other)->value('unit_price'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('cancelled'));
        $this->assertDatabaseHas('product_sale_allocations', ['item_id' => $item->id, 'quantity' => 0]);
        $third = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        self::assertSame('70.000000', DB::table('order_items')->where('order_id', $third)->value('unit_price'));
        $this->invalid(fn () => $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing')), 'items');
        self::assertSame($this->statusId('cancelled'), (string) DB::table('orders')->where('id', $order)->value('status_id'));
        DB::transaction(function () use ($product): void {
            $sales = app(ProductSales::class);
            $sales->lockProducts([(string) $product->id]);
            $sales->sync((string) $product->id, []);
        });
        self::assertFalse((bool) DB::table('product_sales')->where('id', $sale)->value('is_enabled'));
        self::assertSame('70.000000', DB::table('order_items')->where('order_id', $order)->value('unit_price'));
        self::assertSame('70.000000', DB::table('order_items')->where('order_id', $third)->value('unit_price'));
        try {
            DB::transaction(fn () => DB::table('product_sales')->where('id', $sale)->update(['stock_limit' => 0]));
            self::fail('SQL must protect already reserved stock');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
        DB::table('products')->where('id', $product->id)->delete();
        self::assertSame('70.000000', DB::table('order_items')->where('id', $item->id)->value('unit_price'));
        self::assertNull(DB::table('order_items')->where('id', $item->id)->value('product_id'));
        self::assertSame(0, DB::table('product_sale_allocations')->count());
    }

    public function test_sale_order_edits_release_quota_on_overrides_and_roll_back_excess_quantity(): void
    {
        DB::table('customer_groups')->insert(['status' => 'active', 'is_default' => true]);
        $product = $this->product('100', 100);
        $sale = (string) DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => DB::table('customer_groups')->where('is_default', true)->value('id'), 'kind' => 'fixed', 'amount' => '60', 'stock_limit' => 2]);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $item = DB::table('order_items')->where('order_id', $order)->sole();
        $this->invalid(fn () => $this->orders->update($this->id(), $order, $this->payload([['item_id' => (string) $item->id, 'quantity' => 3]], ['version' => $this->version($order)])), 'items');
        self::assertSame(2, (int) DB::table('order_items')->where('id', $item->id)->value('quantity'));
        $this->orders->update($this->id(), $order, $this->payload([['item_id' => (string) $item->id, 'quantity' => 2, 'unit_price' => '100']], ['version' => $this->version($order)]));
        $this->assertDatabaseMissing('product_sale_allocations', ['item_id' => $item->id]);
        self::assertSame('100.000000', DB::table('order_items')->where('id', $item->id)->value('unit_price'));
        self::assertSame(0, (int) DB::table('product_sale_allocations')->where('sale_id', $sale)->sum('quantity'));
    }

    public function test_sale_prices_respect_customer_groups_dates_and_minimum_quantity(): void
    {
        $product = $this->product('100', 100);
        $group = (string) DB::table('customer_groups')->insertGetId(['status' => 'active']);
        $sale = (string) DB::table('product_sales')->insertGetId(['product_id' => $product->id, 'customer_group_id' => $group, 'kind' => 'percentage', 'amount' => '30', 'minimum_quantity' => 3]);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'sale@example.test', 'first_name' => 'Sale', 'last_name' => 'Customer']);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        $sales = app(ProductSales::class);
        self::assertNull($sales->price((string) $product->id, '100', 3, null, 2));
        self::assertNull($sales->price((string) $product->id, '100', 2, $customer, 2));
        self::assertSame(['id' => $sale, 'price' => '70.00'], $sales->price((string) $product->id, '100', 3, $customer, 2));
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 3]], ['customer_id' => $customer]);
        self::assertSame('70.000000', DB::table('order_items')->where('order_id', $order)->value('unit_price'));
        DB::table('product_sales')->where('id', $sale)->update(['ends_on' => '2000-01-01']);
        self::assertNull($sales->price((string) $product->id, '100', 3, $customer, 2));
    }

    public function test_statuses_are_created_renamed_reordered_toggled_and_get_templates(): void
    {
        $statuses = app(OrderStatuses::class);
        $id = $statuses->save($this->id(), null, null, ['code' => 'awaiting_pickup', 'color' => '#AA00ff', 'stock_action' => 'decrease', 'translations' => ['en' => ['name' => 'Awaiting pickup']]]);
        $row = DB::table('order_statuses')->where('id', $id)->sole();
        self::assertSame(['awaiting_pickup', '#aa00ff', 'decrease', true, false], [$row->code, $row->color, $row->stock_action, (bool) $row->is_active, (bool) $row->is_initial]);
        self::assertSame(DB::table('locales')->count(), DB::table('order_email_templates')->where('status_id', $id)->count(), 'One generated template per language');
        $statuses->save($this->id(), $id, 1, ['color' => '#123456', 'stock_action' => 'none', 'code' => 'ignored', 'translations' => ['en' => ['name' => 'Ready for pickup']]]);
        self::assertSame(['awaiting_pickup', 'Ready for pickup'], [DB::table('order_statuses')->where('id', $id)->value('code'), DB::table('order_status_translations')->where('status_id', $id)->value('name')]);
        $statuses->move($this->id(), $id, 'up');
        $order = DB::table('order_statuses')->orderBy('position')->pluck('id')->map(strval(...))->all();
        self::assertSame($id, $order[count($order) - 2]);
        $statuses->setActive($this->id(), $id, (int) DB::table('order_statuses')->where('id', $id)->value('lock_version'), false);
        self::assertFalse((bool) DB::table('order_statuses')->where('id', $id)->value('is_active'));
        $this->invalid(fn () => $statuses->save($this->id(), null, null, ['code' => 'awaiting_pickup', 'color' => '#000000', 'stock_action' => 'none', 'translations' => ['en' => ['name' => 'Dup']]]), 'code');
        $this->invalid(fn () => $statuses->save($this->id(), null, null, ['code' => 'Bad Code', 'color' => 'red', 'stock_action' => 'explode', 'translations' => ['en' => ['name' => '']]]), 'translations.en.name');
        // Moving the initial flag keeps exactly one initial status.
        $statuses->save($this->id(), $this->statusId('processing'), (int) DB::table('order_statuses')->where('code', 'processing')->value('lock_version'), ['color' => '#d97706', 'stock_action' => 'decrease', 'is_initial' => true, 'translations' => ['en' => ['name' => 'Processing']]]);
        self::assertSame(['processing'], DB::table('order_statuses')->where('is_initial', true)->pluck('code')->all());
        $this->get('/admin/order-statuses')->assertOk()->assertInertia(fn (Assert $page) => $page->has('statuses', 9));
    }

    public function test_drag_order_saves_atomically_and_rejects_incomplete_or_duplicate_lists(): void
    {
        $ids = DB::table('order_statuses')->orderBy('position')->pluck('id')->map(strval(...))->all();
        $next = $ids;
        $last = array_pop($next);
        array_unshift($next, $last);
        $before = DB::table('order_statuses')->orderBy('id')->get()->keyBy('id');
        $this->post('/admin/order-statuses/order', ['ids' => $next])->assertRedirect('/admin/order-statuses');
        self::assertSame($next, DB::table('order_statuses')->orderBy('position')->pluck('id')->map(strval(...))->all());
        foreach (DB::table('order_statuses')->get() as $row) {
            self::assertSame((int) $before[$row->id]->lock_version + 1, (int) $row->lock_version);
            foreach (['stock_action', 'is_initial', 'is_active', 'is_final', 'sends_invoice'] as $field) {
                self::assertSame($before[$row->id]->{$field}, $row->{$field});
            }
        }
        $versions = DB::table('order_statuses')->orderBy('id')->pluck('lock_version')->all();
        foreach ([array_slice($next, 1), [...$next, $next[0]], [...array_slice($next, 1), '999999']] as $invalid) {
            $this->post('/admin/order-statuses/order', ['ids' => $invalid])->assertSessionHasErrors('ids');
            self::assertSame($next, DB::table('order_statuses')->orderBy('position')->pluck('id')->map(strval(...))->all());
            self::assertSame($versions, DB::table('order_statuses')->orderBy('id')->pluck('lock_version')->all());
        }
        $this->post('/admin/order-statuses/order', ['ids' => $next])->assertRedirect('/admin/order-statuses');
        self::assertSame($versions, DB::table('order_statuses')->orderBy('id')->pluck('lock_version')->all(), 'An unchanged order does not bump editor versions');
    }

    public function test_used_reference_and_initial_statuses_are_protected(): void
    {
        $statuses = app(OrderStatuses::class);
        $initial = $this->statusId('open');
        $this->invalid(fn () => $statuses->setActive($this->id(), $initial, 1, false), 'status');
        $this->invalid(fn () => $statuses->save($this->id(), $initial, 1, ['color' => '#2563eb', 'stock_action' => 'none', 'is_initial' => false, 'translations' => ['en' => ['name' => 'Open']]]), 'is_initial');
        $this->invalid(fn () => $statuses->delete($this->id(), $this->statusId('failed'), 1), 'status');
        $custom = $statuses->save($this->id(), null, null, ['code' => 'on_hold', 'color' => '#000000', 'stock_action' => 'none', 'translations' => ['en' => ['name' => 'On hold']]]);
        $order = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $this->orders->changeStatus($this->id(), $order, null, $custom);
        $this->orders->changeStatus($this->id(), $order, null, $initial);
        $this->invalid(fn () => $statuses->delete($this->id(), $custom, 1), 'status');
        $this->expectException(QueryException::class);
        DB::table('order_statuses')->where('id', $custom)->delete();
    }

    public function test_a_disabled_status_cannot_be_assigned_but_orders_already_in_it_still_render(): void
    {
        $order = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('incomplete'));
        DB::table('order_statuses')->where('code', 'incomplete')->update(['is_active' => false]);
        DB::table('order_status_translations')->where('status_id', $this->statusId('incomplete'))->update(['name' => 'Renamed incomplete']);
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $page) => $page->where('order.status.name', 'Renamed incomplete')->where('order.status.active', false)->where('order.history.0.to.name', 'Renamed incomplete'));
        $other = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $this->invalid(fn () => $this->orders->changeStatus($this->id(), $other, null, $this->statusId('incomplete')), 'status_id');
        self::assertSame($this->statusId('open'), (string) DB::table('orders')->where('id', $other)->value('status_id'));
    }

    public function test_reference_statuses_are_seeded_once(): void
    {
        self::assertSame(array_keys(OrderEmailDefaults::STATUSES), DB::table('order_statuses')->orderBy('position')->pluck('code')->all());
        $templates = DB::table('order_email_templates')->count();
        OrderEmailDefaults::seedStatuses();
        self::assertSame(8, DB::table('order_statuses')->count());
        self::assertSame($templates, DB::table('order_email_templates')->count());
        self::assertSame(['open'], DB::table('order_statuses')->where('is_initial', true)->pluck('code')->all());
        self::assertSame('Ανοιχτή', DB::table('order_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'el')->where('t.status_id', $this->statusId('open'))->value('t.name'));
    }

    public function test_resaving_or_repeating_a_status_creates_no_history_email_or_stock_change(): void
    {
        $product = $this->product('10', 10);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        self::assertSame(8, $product->fresh()->quantity);
        self::assertFalse($this->orders->changeStatus($this->id(), $order, $this->version($order), $this->statusId('processing')));
        $this->patch('/admin/orders/'.$order.'/status', ['status_id' => $this->statusId('processing'), 'version' => $this->version($order)])->assertSessionHas('status', __('orders.status_unchanged'));
        $editor = $this->get('/admin/orders/'.$order.'/edit')->viewData('page')['props']['order'];
        $this->put('/admin/orders/'.$order, [...$this->payload([]), 'version' => $editor['version'], 'items' => array_map(fn ($i) => ['item_id' => $i['item_id'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price']], $editor['items'])])->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('order_status_history')->where('order_id', $order)->count());
        self::assertCount(2, $this->mail()->sent);
        self::assertSame(8, $product->fresh()->quantity);
        // Status label, colour and order edits never email anyone.
        $statuses = app(OrderStatuses::class);
        $statuses->save($this->id(), $this->statusId('processing'), (int) DB::table('order_statuses')->where('code', 'processing')->value('lock_version'), ['color' => '#000000', 'stock_action' => 'decrease', 'translations' => ['en' => ['name' => 'Packing']]]);
        self::assertCount(2, $this->mail()->sent);
    }

    public function test_stock_actions_are_idempotent_restore_exactly_and_follow_quantity_edits(): void
    {
        $chair = $this->product('10', 10, 'Chair');
        $table = $this->product('50', 4, 'Table');
        $order = $this->create([['product_id' => (string) $chair->id, 'quantity' => 3], ['product_id' => (string) $table->id, 'quantity' => 1]]);
        self::assertSame([10, 4], [$chair->fresh()->quantity, $table->fresh()->quantity], 'Open takes nothing');
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        self::assertSame([7, 3], [$chair->fresh()->quantity, $table->fresh()->quantity]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('completed'));
        self::assertSame([7, 3], [$chair->fresh()->quantity, $table->fresh()->quantity], 'A second decreasing status takes nothing more');
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('incomplete'));
        self::assertSame([7, 3], [$chair->fresh()->quantity, $table->fresh()->quantity], '"No change" keeps what was taken');
        // Quantity edits while stock is held adjust only the difference.
        $items = DB::table('order_items')->where('order_id', $order)->orderBy('position')->get();
        $this->orders->update($this->id(), $order, [...$this->payload([]), 'version' => $this->version($order), 'items' => [['item_id' => (string) $items[0]->id, 'quantity' => 5], ['product_id' => (string) $this->product('5', 2, 'Lamp')->id, 'quantity' => 2]]]);
        self::assertSame([5, 4], [$chair->fresh()->quantity, $table->fresh()->quantity], 'Chair +2 taken, removed table returned');
        self::assertSame(0, ProductRecord::where('sku', 'like', 'SKU-%')->whereHas('translations', fn ($q) => $q->where('name', 'Lamp'))->value('quantity'));
        $this->orders->update($this->id(), $order, [...$this->payload([]), 'version' => $this->version($order), 'items' => [['item_id' => (string) $items[0]->id, 'quantity' => 1]]]);
        self::assertSame(9, $chair->fresh()->quantity);
        self::assertSame(1, (int) DB::table('order_items')->where('id', $items[0]->id)->value('deducted_quantity'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('cancelled'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('failed'));
        self::assertSame(10, $chair->fresh()->quantity, 'Restore returns exactly once');
        self::assertSame(0, (int) DB::table('order_items')->where('order_id', $order)->sum('deducted_quantity'));
        self::assertFalse((bool) DB::table('orders')->where('id', $order)->value('stock_held'));
    }

    public function test_a_transition_without_enough_stock_is_refused_and_changes_nothing(): void
    {
        $scarce = $this->product('10', 1, 'Scarce');
        $plenty = $this->product('10', 9, 'Plenty');
        $order = $this->create([['product_id' => (string) $plenty->id, 'quantity' => 2], ['product_id' => (string) $scarce->id, 'quantity' => 3]]);
        $this->invalid(fn () => $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing')), 'stock_'.DB::table('order_items')->where('product_id', $scarce->id)->value('id'));
        self::assertSame([1, 9], [$scarce->fresh()->quantity, $plenty->fresh()->quantity]);
        self::assertSame($this->statusId('open'), (string) DB::table('orders')->where('id', $order)->value('status_id'));
        self::assertSame(1, DB::table('order_status_history')->where('order_id', $order)->count());
        self::assertCount(1, $this->mail()->sent);
        // Backorders allow the transition but never drive stock below zero.
        $scarce->update(['out_of_stock_actions' => 'B']);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        self::assertSame([0, 7], [$scarce->fresh()->quantity, $plenty->fresh()->quantity]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('cancelled'));
        self::assertSame([1, 9], [$scarce->fresh()->quantity, $plenty->fresh()->quantity]);
        // With inventory tracking off, statuses do not move stock.
        DB::table('general_settings')->update(['track_inventory' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        self::assertSame(1, $scarce->fresh()->quantity);
    }

    public function test_templates_are_sanitized_validated_previewed_and_kept_when_a_status_is_disabled(): void
    {
        $status = $this->statusId('processing');
        $template = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $status)->where('l.code', 'en')->first(['t.id', 't.lock_version']);
        $templates = app(OrderEmailTemplates::class);
        $this->invalid(fn () => $templates->save($this->id(), (string) $template->id, 1, 'Hi {{customer_ip}}', '<p>{{order_number}}</p>'), 'subject');
        $this->invalid(fn () => $templates->save($this->id(), (string) $template->id, 1, 'Hi', '<p>{{ internal_note }} {{ php_info() }}</p>'), 'body');
        $this->invalid(fn () => $templates->save($this->id(), (string) $template->id, 1, "Line\nbreak", '<p>x</p>'), 'subject');
        $this->put('/admin/order-emails/'.$status.'/templates/'.$template->id, ['version' => 1, 'subject' => 'Bad {{nope}}', 'body' => '<p>kept text</p>'])->assertSessionHasErrors('subject');
        $templates->save($this->id(), (string) $template->id, 1, 'Order {{ order_number }} is {{current_status}}', '<p onclick="steal()">Hi <strong>{{customer_first_name}}</strong><script>alert(1)</script><a href="javascript:x()">x</a></p>{{order_items}}');
        $saved = DB::table('order_email_templates')->where('id', $template->id)->sole();
        self::assertStringNotContainsString('script', $saved->body_html);
        self::assertStringNotContainsString('onclick', $saved->body_html);
        self::assertStringNotContainsString('javascript', $saved->body_html);
        self::assertStringContainsString('{{customer_first_name}}', $saved->body_html);
        $before = count($this->mail()->sent);
        $preview = $templates->preview($this->id(), 'status', $status, 'en', ['subject' => 'Order {{order_number}}', 'body' => '<p>{{customer_first_name}} {{grand_total}}</p>']);
        self::assertSame('Order 100123', $preview['subject']);
        self::assertStringContainsString('Maria', $preview['html']);
        self::assertCount($before, $this->mail()->sent, 'A preview never sends');
        DB::table('order_statuses')->where('id', $status)->update(['is_active' => false]);
        self::assertSame(1, DB::table('order_email_templates')->where('id', $template->id)->count());
        $this->get('/admin/order-emails/'.$status.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('templates.en.subject', 'Order {{ order_number }} is {{current_status}}')->has('variables', count(OrderEmailDefaults::VARIABLES)));
    }

    public function test_each_transition_sends_its_own_status_template_once(): void
    {
        $order = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        foreach (['processing' => 'Packing {{order_number}}', 'completed' => 'Done {{order_number}} ({{previous_status}} → {{current_status}})'] as $code => $subject) {
            DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $this->statusId($code))->where('l.code', 'en')->update(['subject' => $subject]);
        }
        $this->patch('/admin/orders/'.$order.'/status', ['status_id' => $this->statusId('processing'), 'version' => $this->version($order), 'note' => 'internal remark'])->assertSessionHas('status', __('orders.status_changed'));
        $this->orders->changeStatus($this->id(), $order, $this->version($order), $this->statusId('completed'));
        $subjects = array_map(fn ($m): string => $m->subject, $this->mail()->sent);
        $number = DB::table('orders')->where('id', $order)->value('order_number');
        self::assertSame(['Your order '.$number.' at Test Store', 'Packing '.$number, 'Done '.$number.' (Processing → Completed)', 'Order summary for order '.$number], $subjects, 'Completed also sends the invoice by default');
        self::assertStringNotContainsString('internal remark', implode('', array_map(fn ($m): string => $m->html, $this->mail()->sent)));
        self::assertSame(4, DB::table('order_notifications')->where('order_id', $order)->where('state', 'sent')->count());
        // A stale form cannot change the status.
        $this->invalid(fn () => $this->orders->changeStatus($this->id(), $order, 1, $this->statusId('open')), 'version');
    }

    public function test_failed_delivery_keeps_the_order_and_can_be_retried_without_duplicates(): void
    {
        $this->mail()->accept = false;
        $order = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $notification = DB::table('order_notifications')->where('order_id', $order)->sole();
        self::assertSame(['failed', 1, 'mail_rejected'], [$notification->state, (int) $notification->attempts, $notification->last_error]);
        self::assertSame(1, DB::table('orders')->count());
        // A missing sender is reported, never treated as sent.
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => null]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertFalse(app(OrderNotifier::class)->deliver((string) $notification->id));
        self::assertSame('sender_missing', DB::table('order_notifications')->where('id', $notification->id)->value('last_error'));
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'orders@store.example']);
        app()->forgetInstance(GeneralSettings::class);
        $this->mail()->accept = true;
        $this->post('/admin/orders/'.$order.'/notifications/'.$notification->id.'/retry')->assertSessionHas('status', __('orders.retry_sent'));
        $after = DB::table('order_notifications')->where('id', $notification->id)->sole();
        self::assertSame(['sent', 3], [$after->state, (int) $after->attempts]);
        // A sent email is not sent again by a repeated retry, and nothing else changed.
        $this->post('/admin/orders/'.$order.'/notifications/'.$notification->id.'/retry')->assertSessionHasErrors('notification');
        self::assertFalse(app(OrderNotifier::class)->deliver((string) $notification->id));
        self::assertSame([1, 1, 1], [DB::table('orders')->count(), DB::table('order_status_history')->count(), DB::table('order_notifications')->count()]);
        self::assertCount(2, $this->mail()->sent, 'The failed attempt and the successful retry; a missing sender never reaches the transport');
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('order.history.0.emails.0.state', 'sent')->where('order.history.0.emails.0.attempts', 3));
    }

    public function test_history_is_append_only_and_comments_keep_their_visibility(): void
    {
        $order = $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $this->post('/admin/orders/'.$order.'/comments', ['visibility' => 'internal', 'body' => 'Checked stock'])->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order.'/comments', ['visibility' => 'secret', 'body' => 'x'])->assertSessionHasErrors('visibility');
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('order.comments.0.visibility', 'internal')->where('order.comments.0.body', 'Checked stock'));
        $this->expectException(QueryException::class);
        DB::table('order_status_history')->where('order_id', $order)->update(['note' => 'rewritten']);
    }

    public function test_editing_recalculates_only_when_totals_are_affected_and_final_orders_need_confirmation(): void
    {
        $product = $this->product('10', 10);
        $this->vat($product);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        self::assertSame('12.400000', DB::table('orders')->where('id', $order)->value('total'));
        $item = (string) DB::table('order_items')->where('order_id', $order)->value('id');
        // A tax configuration change does not touch a saved order.
        DB::table('tax_rates')->update(['percent_rate' => '0.5']);
        $this->orders->update($this->id(), $order, [...$this->payload([]), 'first_name' => 'Renamed', 'version' => $this->version($order), 'items' => [['item_id' => $item, 'quantity' => 1, 'unit_price' => '10.00']]]);
        self::assertSame(['12.400000', 'Renamed'], [DB::table('orders')->where('id', $order)->value('total'), DB::table('orders')->where('id', $order)->value('first_name')]);
        // An explicit content change recalculates through the tax service.
        $this->orders->update($this->id(), $order, [...$this->payload([]), 'version' => $this->version($order), 'items' => [['item_id' => $item, 'quantity' => 2, 'unit_price' => '10.00']]]);
        self::assertSame('30.000000', DB::table('orders')->where('id', $order)->value('total'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('completed'));
        $this->invalid(fn () => $this->orders->update($this->id(), $order, [...$this->payload([]), 'version' => $this->version($order), 'items' => [['item_id' => $item, 'quantity' => 3, 'unit_price' => '10.00']]]), 'confirm_final');
        $this->orders->update($this->id(), $order, [...$this->payload([]), 'confirm_final' => true, 'version' => $this->version($order), 'items' => [['item_id' => $item, 'quantity' => 3, 'unit_price' => '10.00']]]);
        self::assertSame(7, $product->fresh()->quantity);
        // Orders keep their currency: a primary-currency change neither converts them nor allows re-pricing them.
        $currencies = app(CurrencyAdministration::class);
        $usd = $currencies->create($this->id(), new SaveCurrencyData('USD', 'US Dollar', '$', 'before', ',', '.', 2, '1.1', 'active'));
        $currencies->makePrimary($this->id(), $usd, 1, 'USD');
        self::assertSame(['EUR', '45.000000'], [DB::table('orders')->where('id', $order)->value('currency_code'), DB::table('orders')->where('id', $order)->value('total')]);
        $this->invalid(fn () => $this->orders->update($this->id(), $order, [...$this->payload([]), 'confirm_final' => true, 'version' => $this->version($order), 'items' => [['item_id' => $item, 'quantity' => 1, 'unit_price' => '10.00']]]), 'items');
        $eur = (string) DB::table('currencies')->where('code', 'EUR')->value('id');
        $this->invalid(fn () => $currencies->delete($this->id(), $eur, (int) DB::table('currencies')->where('id', $eur)->value('lock_version')), 'currency');
    }

    public function test_admin_screens_render_and_quote_totals_without_saving(): void
    {
        $product = $this->product('10', 5);
        $this->vat($product);
        $this->get('/admin/orders/create?product_search=Chair')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Orders/Form')->where('products.0.id', (string) $product->id)->where('statuses.0.code', 'open'));
        $this->from('/admin/orders/create')->post('/admin/orders/quote', $this->payload([['product_id' => (string) $product->id, 'quantity' => 2]]))->assertRedirect('/admin/orders/create')->assertSessionHas('order_quote', fn (array $quote): bool => $quote['total'] === '€24.80' && $quote['tax'] === '€4.80');
        self::assertSame(0, DB::table('orders')->count());
        $this->post('/admin/orders', $this->payload([['product_id' => (string) $product->id, 'quantity' => 2]]))->assertRedirect();
        $order = (string) DB::table('orders')->value('id');
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Orders/Show')->where('order.totals.total', '€24.80')->where('order.history.0.emails.0.state', 'sent'));
        $this->get('/admin/orders/'.$order.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('order.items.0.quantity', 2)->where('order.editable_currency', true));
        $this->get('/admin/order-emails')->assertOk();
        $this->get('/admin/order-statuses/'.$this->statusId('open').'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status.orders', 1));
        $this->postJson('/admin/order-emails/preview', ['kind' => 'status', 'status_id' => $this->statusId('open'), 'locale' => 'en', 'subject' => 'Hi {{customer_name}}', 'body' => '<p>{{order_items}}</p>'])
            ->assertOk()->assertJsonPath('subject', 'Hi Maria Example')->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'SAMPLE-1'))->etc());
        $this->postJson('/admin/order-emails/preview', ['kind' => 'status', 'locale' => 'en', 'subject' => '{{nope}}'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->get('/admin/order-emails/layout')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/OrderEmails/Edit')->where('kind', 'layout')->has('templates.en.header'));
        $this->get('/admin/order-emails/invoice')->assertRedirect('/admin/order-emails/order-summary');
        $this->get('/admin/order-emails/order-summary')->assertOk()->assertInertia(fn (Assert $page) => $page->where('kind', 'invoice')->where('invoiceStatuses', ['Completed']));
    }

    public function test_the_local_demo_creates_one_order_per_status_once(): void
    {
        $this->product('10', 20);
        $seeder = app(OrderDemoSeeder::class);
        self::assertSame(8, $seeder->run($this->id()));
        self::assertSame(0, $seeder->run($this->id()));
        $statuses = DB::table('orders')->join('order_statuses as s', 's.id', '=', 'orders.status_id')->pluck('s.code')->sort()->values()->all();
        self::assertSame(['cancelled', 'completed', 'failed', 'incomplete', 'open', 'processing', 'ready_for_shipment', 'shipped'], $statuses);
        self::assertSame(16, ProductRecord::sole()->quantity, 'Processing, Ready for shipment, Shipped and Completed hold one unit each; Cancelled and Failed returned theirs');
        self::assertSame(3, DB::table('order_status_history as h')->join('orders as o', 'o.id', '=', 'h.order_id')->join('order_statuses as s', 's.id', '=', 'o.status_id')->where('s.code', 'cancelled')->count());
    }

    public function test_the_order_email_sender_is_a_validated_general_setting(): void
    {
        $version = (int) DB::table('general_settings')->where('id', 1)->value('lock_version');
        $this->put('/admin/settings/general', ['version' => $version, 'out_of_stock_action' => 'N', 'order_email_from' => "evil@example.test\r\nBcc: x@example.test", 'order_email_from_name' => "Shop\nBcc: x"])
            ->assertSessionHasErrors(['order_email_from', 'order_email_from_name']);
        $this->put('/admin/settings/general', ['version' => $version, 'out_of_stock_action' => 'N', 'order_email_from' => ' Sales@Shop.Example ', 'order_email_from_name' => 'My Shop', 'order_email_reply_to' => ''])->assertSessionHasNoErrors();
        self::assertSame(['sales@shop.example', 'My Shop', null], array_values((array) DB::table('general_settings')->where('id', 1)->first(['order_email_from', 'order_email_from_name', 'order_email_reply_to'])));
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('settings.order_email_from', 'sales@shop.example'));
        app()->forgetInstance(GeneralSettings::class);
        $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $mail = $this->mail()->sent[0];
        self::assertSame(['sales@shop.example', 'My Shop', null], [$mail->fromAddress, $mail->fromName, $mail->replyTo], 'Company contact addresses never become the sender');
    }

    public function test_the_invoice_is_sent_once_when_an_order_first_enters_an_invoice_status(): void
    {
        $product = $this->product('10', 10, 'Calculator');
        $tax = $this->vat($product, '0.24', true);
        DB::table('taxes')->where('id', $tax)->update(['registration_number' => 'EL123456789']);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('completed'));
        $invoices = array_values(array_filter($this->mail()->sent, fn ($m): bool => str_starts_with($m->subject, 'Order summary for order')));
        self::assertCount(1, $invoices);
        self::assertStringContainsString('ORDER SUMMARY', $invoices[0]->html);
        // The shared header (accent band) wraps the status emails, never the standalone invoice email.
        $status = array_values(array_filter($this->mail()->sent, fn ($m): bool => ! str_starts_with($m->subject, 'Order summary for order')));
        self::assertStringContainsString('background-color:#1d3557', $status[0]->html);
        self::assertStringNotContainsString('background-color:#1d3557', $invoices[0]->html);
        self::assertStringContainsString('Calculator', $invoices[0]->html);
        // Classic invoice lines: price without VAT, the VAT percentage (the tax's own rate) and the price with VAT.
        foreach (['Price excl. VAT', 'VAT %', 'Price incl. VAT', '>0.24%<'] as $expected) {
            self::assertStringContainsString($expected, $invoices[0]->html);
        }
        self::assertStringContainsString('(EL123456789)', $invoices[0]->html, 'The tax registration number is printed');
        self::assertSame(['status', 'invoice'], DB::table('order_notifications')->where('order_id', $order)->where('status_id', $this->statusId('completed'))->orderBy('id')->pluck('kind')->all());
        // Leaving and re-entering an invoice status does not send it again.
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('completed'));
        self::assertSame(1, DB::table('order_notifications')->where('order_id', $order)->where('kind', 'invoice')->count());
        // The merchant chooses the statuses: here Processing sends it and Completed no longer does.
        $statuses = app(OrderStatuses::class);
        foreach (['completed' => false, 'processing' => true] as $code => $flag) {
            $row = DB::table('order_statuses')->where('code', $code)->sole();
            $statuses->save($this->id(), (string) $row->id, (int) $row->lock_version, ['color' => $row->color, 'stock_action' => $row->stock_action, 'sends_invoice' => $flag, 'translations' => ['en' => ['name' => ucfirst($code)]]]);
        }
        $other = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        $this->orders->changeStatus($this->id(), $other, null, $this->statusId('completed'));
        self::assertSame(0, DB::table('order_notifications')->where('order_id', $other)->where('kind', 'invoice')->count());
        $this->orders->changeStatus($this->id(), $other, null, $this->statusId('processing'));
        self::assertSame(1, DB::table('order_notifications')->where('order_id', $other)->where('kind', 'invoice')->count());
        // The printable invoice is the same document, available to order viewers only.
        $this->get('/admin/orders/'.$order.'/invoice')->assertOk()->assertHeader('Content-Security-Policy')->assertSee('ORDER SUMMARY')->assertSee('EL123456789');
        $outsider = $this->staff(['admin.access']);
        $this->actingAs($outsider)->withSession(['password_hash_web' => $outsider->getAuthPassword()])->get('/admin/orders/'.$order.'/invoice')->assertForbidden();
    }

    public function test_every_email_is_wrapped_in_the_editable_header_and_footer_with_the_shop_logo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('logos/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        $media = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'logos/logo.png', 'mime_type' => 'image/png', 'byte_size' => 68, 'width' => 1, 'height' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_settings')->where('id', 1)->update(['logo_media_id' => $media]);
        $layout = DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'en')->first(['t.id', 't.lock_version', 't.header_html']);
        self::assertStringContainsString('{{store_logo}}', $layout->header_html, 'The logo is in the default header');
        $templates = app(OrderEmailTemplates::class);
        $this->invalid(fn () => $templates->saveLayout($this->id(), (string) $layout->id, 1, ['header' => '<div>{{customer_ip}}</div>', 'footer' => 'x']), 'header');
        $this->put('/admin/order-emails/layout/'.$layout->id, ['version' => 1, 'header' => '<div style="background:#123456; background-image:url(https://x.test/a.png)" onmouseover="steal()">{{store_logo}} TOP-BANNER</div><script>alert(1)</script>', 'footer' => '<p style="color:#999">BOTTOM-LINE {{store_name}}</p><iframe src="https://x.test"></iframe>'])->assertSessionHasNoErrors();
        $saved = DB::table('order_email_layouts')->where('id', $layout->id)->sole();
        self::assertSame('<div style="background:#123456">{{store_logo}} TOP-BANNER</div>', $saved->header_html);
        self::assertSame('<p style="color:#999">BOTTOM-LINE {{store_name}}</p>', $saved->footer_html);
        $this->create([['product_id' => (string) $this->product()->id, 'quantity' => 1]]);
        $mail = $this->mail()->sent[0];
        self::assertMatchesRegularExpression('/TOP-BANNER.*Thank you for your order!.*BOTTOM-LINE Test Store/s', $mail->html);
        self::assertStringContainsString('src="cid:store-logo"', $mail->html);
        self::assertSame([['cid' => 'store-logo', 'mime' => 'image/png']], array_map(fn ($i) => ['cid' => $i['cid'], 'mime' => $i['mime']], $mail->inline));
        // The admin preview shows the same email with the logo embedded, without sending anything.
        $before = count($this->mail()->sent);
        $preview = $templates->preview($this->id(), 'layout', null, 'en', ['header' => '<p>NEW-HEADER {{store_logo}}</p>', 'footer' => '<p>NEW-FOOTER</p>']);
        self::assertMatchesRegularExpression('/NEW-HEADER <img src="data:image\/png;base64,.*NEW-FOOTER/s', $preview['html']);
        self::assertCount($before, $this->mail()->sent);
    }

    public function test_test_emails_send_unsaved_content_with_sample_data_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('logos/email.png', 'email-logo');
        $logo = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'logos/email.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1, 'height' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_settings')->where('id', 1)->update(['logo_email_media_id' => $logo]);
        config(['mail.local_inbox_url' => null]);
        $this->post('/admin/order-emails/test', ['kind' => 'invoice', 'locale' => 'en', 'recipient' => 'Support@Example.com', 'subject' => 'Test invoice {{order_number}}', 'body' => '<p>{{invoice}}</p>'])
            ->assertSessionHas('status', __('orders.test_sent', ['email' => 'support@example.com']));
        // On a development installation the message says where captured mail can be read.
        config(['mail.local_inbox_url' => 'http://localhost:8025']);
        $this->post('/admin/order-emails/test', ['kind' => 'status', 'locale' => 'en', 'recipient' => 'support@example.com'])
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'http://localhost:8025'));
        array_pop($this->mail()->sent);
        $mail = $this->mail()->sent[0];
        self::assertSame(['support@example.com', 'Test invoice 100123'], [$mail->to, $mail->subject]);
        self::assertStringContainsString('ORDER SUMMARY', $mail->html);
        self::assertSame(0, DB::table('orders')->count() + DB::table('order_notifications')->count());
        $this->post('/admin/order-emails/test', ['kind' => 'status', 'locale' => 'en', 'recipient' => "x@example.test\r\nBcc: y@example.test"])->assertSessionHasErrors('recipient');
        $this->artisan('cms:order-emails-test', ['recipient' => 'support@example.com'])->assertSuccessful();
        self::assertCount(1 + DB::table('order_statuses')->count() + 1, $this->mail()->sent, 'One email per status plus the invoice');
        foreach ($this->mail()->sent as $sent) {
            self::assertStringContainsString('src="cid:store-logo"', $sent->html);
            self::assertSame('email-logo', $sent->inline[0]['content']);
        }
        self::assertSame(['support@example.com'], array_values(array_unique(array_map(fn ($m) => $m->to, $this->mail()->sent))));
        DB::table('general_settings')->update(['order_email_from' => null]);
        app()->forgetInstance(GeneralSettings::class);
        $this->artisan('cms:order-emails-test', ['recipient' => 'support@example.com'])->assertFailed();
        $this->artisan('cms:order-emails-test', ['recipient' => 'support@example.com', '--from' => 'shop@example.com'])->assertSuccessful();
        self::assertSame('shop@example.com', end($this->mail()->sent)->fromAddress);
    }

    public function test_unchanged_generated_bodies_are_upgraded_but_edited_ones_are_kept(): void
    {
        $legacy = (new \ReflectionClassConstant(OrderEmailDefaults::class, 'LEGACY_BODIES'))->getValue()['en'];
        $open = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'en')->where('t.status_id', $this->statusId('open'))->value('t.id');
        $failed = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'en')->where('t.status_id', $this->statusId('failed'))->value('t.id');
        DB::table('order_email_templates')->where('id', $open)->update(['body_html' => $legacy[0]]);
        DB::table('order_email_templates')->where('id', $failed)->update(['body_html' => '<p>My own text</p>']);
        OrderEmailDefaults::upgradeUnchangedBodies();
        self::assertStringContainsString('Thank you for your order!', DB::table('order_email_templates')->where('id', $open)->value('body_html'));
        self::assertSame('<p>My own text</p>', DB::table('order_email_templates')->where('id', $failed)->value('body_html'));
    }

    public function test_ready_for_shipment_has_localized_emails_is_protected_and_takes_stock_only_once(): void
    {
        $ready = $this->statusId('ready_for_shipment');
        $row = DB::table('order_statuses')->where('id', $ready)->sole();
        self::assertTrue((bool) $row->is_active);
        self::assertTrue((bool) $row->is_system);
        self::assertFalse((bool) $row->is_final);
        self::assertSame('decrease', $row->stock_action);
        $codes = DB::table('order_statuses')->orderBy('position')->orderBy('id')->pluck('code')->all();
        self::assertSame('ready_for_shipment', $codes[array_search('shipped', $codes, true) - 1]);
        self::assertSame(DB::table('locales')->count(), DB::table('order_email_templates')->where('status_id', $ready)->count());
        $this->invalid(fn () => app(OrderStatuses::class)->delete($this->id(), $ready, (int) $row->lock_version), 'status');
        $this->get('/admin/order-emails/'.$ready.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('templates.en.subject', 'Your order {{order_number}} is ready for shipment')->where('templates.el.subject', 'Η παραγγελία σου {{order_number}} είναι έτοιμη για αποστολή'));
        $product = $this->product('10', 10);
        foreach (['en', 'el'] as $locale) {
            $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]], ['locale' => $locale]);
            $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
            $quantity = $product->refresh()->quantity;
            $this->orders->changeStatus($this->id(), $order, null, $ready);
            self::assertSame($quantity, $product->refresh()->quantity);
            $mail = end($this->mail()->sent);
            self::assertSame(str_replace('{{order_number}}', DB::table('orders')->where('id', $order)->value('order_number'), __('orders.mail_ready_for_shipment_subject', [], $locale)), $mail->subject);
            self::assertStringContainsString(__('orders.mail_ready_for_shipment_title', [], $locale), $mail->html);
            $this->orders->changeStatus($this->id(), $order, null, $this->statusId('shipped'));
            self::assertSame($quantity, $product->refresh()->quantity);
        }
        $direct = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        $this->orders->changeStatus($this->id(), $direct, null, $ready);
        self::assertSame(5, $product->refresh()->quantity);
    }

    public function test_ready_for_shipment_upgrade_preserves_existing_status_order_and_edited_templates(): void
    {
        $migration = require database_path('migrations/2026_11_21_000200_add_ready_for_shipment_order_status.php');
        $migration->down();
        $before = DB::table('order_statuses')->orderBy('position')->orderBy('id')->pluck('code')->all();
        DB::table('order_email_templates')->where('status_id', $this->statusId('shipped'))->update(['subject' => 'Merchant shipping message']);
        $migration->up();
        $migration->up();
        $after = DB::table('order_statuses')->orderBy('position')->orderBy('id')->pluck('code')->all();
        self::assertSame($before, array_values(array_diff($after, ['ready_for_shipment'])));
        self::assertSame('ready_for_shipment', $after[array_search('shipped', $after, true) - 1]);
        self::assertSame(1, DB::table('order_statuses')->where('code', 'ready_for_shipment')->count());
        self::assertSame(['Merchant shipping message'], DB::table('order_email_templates')->where('status_id', $this->statusId('shipped'))->distinct()->pluck('subject')->all());
    }

    public function test_shipping_an_order_emails_the_shipped_template_and_keeps_stock_taken_once(): void
    {
        $product = $this->product('10', 5);
        $order = $this->create([['product_id' => (string) $product->id, 'quantity' => 2]]);
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('processing'));
        $this->orders->changeStatus($this->id(), $order, null, $this->statusId('shipped'));
        self::assertSame(3, $product->fresh()->quantity, 'Already taken by Processing; Shipped takes nothing more');
        $mail = end($this->mail()->sent);
        self::assertSame('Your order '.DB::table('orders')->where('id', $order)->value('order_number').' is on its way', $mail->subject);
        self::assertStringContainsString('Your order is on its way!', $mail->html);
        // Shipped straight from Open takes the stock itself.
        $direct = $this->create([['product_id' => (string) $product->id, 'quantity' => 1]]);
        $this->orders->changeStatus($this->id(), $direct, null, $this->statusId('shipped'));
        self::assertSame(2, $product->fresh()->quantity);
        self::assertSame('Απεστάλη', DB::table('order_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'el')->where('t.status_id', $this->statusId('shipped'))->value('t.name'));
    }
}
