<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\Coupons;
use App\Marketing\Infrastructure\GiftCards;
use App\Order\Application\Event\OrderPlaced;
use App\Payment\Infrastructure\PaymentSettings;
use App\Settings\Infrastructure\CheckoutSettings;
use App\Shipping\Infrastructure\ShippingMethods;
use App\Storefront\Infrastructure\CheckoutOptions;
use App\Storefront\Infrastructure\InvoiceVerification;
use App\Storefront\Presentation\Http\CheckoutController;
use App\Storefront\Presentation\Http\StorefrontLocaleController;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** The single-page checkout: guests and customers, server-decided amounts, idempotent placing and the account switch. */
final class CheckoutTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    /** Where the Checkout layout's main content block carries the checkout (its own header comes first). */
    private const BLOCK = 'storefront.regions.content.1.blocks.0.content.checkout.';

    private CategoryRecord $category;

    private UserRecord $staff;

    private string $courier;

    private string $cod;

    private string $bank;

    private ?string $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials();
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        app()->setLocale('en');
        $this->staff = $this->staff(super: true);
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => $this->locale(), 'name' => 'Audio', 'slug' => 'audio']);
        app(PaymentSettings::class)->ensure();
        DB::table('payment_methods')->whereIn('code', ['cod', 'bank_deposit'])->update(['is_active' => true]);
        DB::table('payment_methods')->where('code', 'cod')->update(['fee' => '2']);
        $this->cod = (string) DB::table('payment_methods')->where('code', 'cod')->value('id');
        $this->bank = (string) DB::table('payment_methods')->where('code', 'bank_deposit')->value('id');
        $greece = app(RateAreaAdministration::class)->create((string) $this->staff->id, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR'], []));
        $this->courier = app(ShippingMethods::class)->save((string) $this->staff->id, null, null, [
            'translations' => ['en' => ['name' => 'Courier', 'description' => '', 'delivery_time' => '']], 'calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true,
            'rates' => [['rate_area_id' => $greece, 'weight_from' => '0', 'weight_to' => '', 'base' => '3.50', 'per_unit' => '0']],
        ]);
        $this->region = app(ShippingGeography::class)->regionOptions()['GR'][0]['code'] ?? null;
    }

    private function locale(): int
    {
        return (int) DB::table('locales')->where('code', 'en')->value('id');
    }

    /** @param array<string, mixed> $attributes */
    private function product(array $attributes = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'CHK-'.Str::upper(Str::random(6)), 'price' => '10', 'quantity' => 50, 'weight' => '1', 'category_id' => $this->category->id, 'status' => 'active', ...$attributes]);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => 'Speaker '.$p->id, 'slug' => 'speaker-'.$p->id]);

        return $p;
    }

    /** A guest cart holding these products; returns its cookie value. */
    private function cart(array $products): string
    {
        $uuid = null;
        foreach ($products as $id => $quantity) {
            $response = ($uuid === null ? $this : $this->withCookie('cart', $uuid))->postJson('/cart/items', ['product_id' => $id, 'quantity' => $quantity])->assertOk();
            $uuid ??= $response->getCookie('cart')?->getValue();
        }

        return (string) $uuid;
    }

    /** @return array{UserRecord, int} */
    private function customer(string $email = 'ria@example.test'): array
    {
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => $email, 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $id = (int) DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $email, 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $id];
    }

    /** @return array<string, ?string> */
    private function address(array $changes = []): array
    {
        return array_replace(['first_name' => 'Maria', 'last_name' => 'Guest', 'line1' => 'Ermou', 'street_number' => '12', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR', 'region_code' => $this->region], $changes);
    }

    /** @return array<string, mixed> */
    private function payload(array $changes = []): array
    {
        return array_replace([
            'submission_key' => (string) Str::uuid(), 'email' => ' Maria@Example.test ', 'first_name' => 'Maria', 'last_name' => 'Guest', 'phone' => '+30 210 123 4567',
            'shipping' => $this->address(), 'billing_same' => true, 'shipping_method_id' => $this->courier, 'payment_method_id' => $this->cod,
        ], $changes);
    }

    private function confirmation(TestResponse $response): TestResponse
    {
        $location = (string) $response->headers->get('Location');
        self::assertStringContainsString('/checkout/complete/', $location);

        return $this->get($location);
    }

    public function test_a_guest_checks_out_and_the_server_decides_every_amount(): void
    {
        $product = $this->product();
        $uuid = $this->cart([$product->id => 2]);
        $this->withCookie('cart', $uuid)->get('/checkout')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Checkout')->where('storefront.regions.content.0.blocks.0.template', 'checkout_header')->where('storefront.regions.content.0.blocks.0.content.checkout_header.name', fn (string $name) => $name !== '')
            ->where(self::BLOCK.'account_required', false)->where(self::BLOCK.'contact', null)
            ->where(self::BLOCK.'quote.needs_shipping', true)->where(self::BLOCK.'quote.shipping_methods', [])
            ->where(self::BLOCK.'quote.payment_methods.0.fee', '2.00')->where(self::BLOCK.'quote.totals.total', '20.00'));

        // The quote follows the address and the chosen methods: the courier's charge and the cash-on-delivery fee.
        $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'billing_same' => true, 'payment_method_id' => $this->cod])->assertOk()
            ->assertJsonPath('quote.shipping_methods.0.id', $this->courier)->assertJsonPath('quote.shipping_methods.0.amount', '3.50')
            ->assertJsonPath('quote.shipping_method_id', $this->courier)->assertJsonPath('quote.payment_method_id', $this->cod)
            ->assertJsonPath('quote.totals.shipping', '3.50')->assertJsonPath('quote.totals.payment_fee', '2.00')->assertJsonPath('quote.totals.total', '25.50');

        // Prices, charges, statuses, customers and notes sent by the browser are never used.
        $payload = $this->payload([
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '0.01']], 'shipping_amount' => '0', 'total' => '1',
            'status_id' => DB::table('order_statuses')->where('code', 'completed')->value('id'), 'customer_id' => $this->customer('other@example.test')[1], 'internal_note' => 'free', 'customer_note' => 'Ring twice',
        ]);
        $response = $this->withCookie('cart', $uuid)->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $order = DB::table('orders')->sole();
        self::assertSame(['maria@example.test', null, '20.000000', '3.500000', '2.000000', '25.500000', null, 'Courier'], [$order->email, $order->customer_id, $order->subtotal, $order->shipping_total, $order->payment_fee, $order->total, $order->internal_note, $order->shipping_method_name]);
        self::assertSame('open', DB::table('order_statuses')->where('id', $order->status_id)->value('code'));
        self::assertSame(2, (int) DB::table('order_items')->where('order_id', $order->id)->value('quantity'));
        self::assertSame('checkout', DB::table('order_status_history')->where('order_id', $order->id)->value('source'));
        self::assertNotNull($order->ip_address);
        // The house number joins the street line of the address snapshot, as staff orders from saved addresses do.
        self::assertSame('Ermou 12', DB::table('order_addresses')->where('order_id', $order->id)->where('type', 'billing')->value('line1'));
        self::assertSame(0, DB::table('cart_items')->count(), 'The ordered lines left the cart');

        // The confirmation is behind a signed link: never by order ID alone.
        $this->confirmation($response)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where(self::BLOCK.'placed.number', $order->order_number)->where(self::BLOCK.'placed.totals.total', '25.50')->where(self::BLOCK.'placed.totals.due', '25.50')
            ->where(self::BLOCK.'placed.payment.pay_url', null)->where(self::BLOCK.'placed.payment.instructions', fn (string $text) => $text !== '')
            ->where(self::BLOCK.'placed.address.lines.0', 'Ermou 12')->where(self::BLOCK.'placed.note', 'Ring twice'));
        $this->get('/checkout/complete/'.$order->id)->assertForbidden();

        // A repeated or retried submission answers with the same order.
        $this->withCookie('cart', $uuid)->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame(1, DB::table('orders')->count());
        $this->assertDatabaseCount('admin_notifications', 1);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'resource_id' => (string) $order->id, 'resource_number' => $order->order_number, 'read_at' => null]);
    }

    public function test_payment_and_delivery_methods_show_their_uploaded_logo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('test/logo.png', 'png');
        $media = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'test/logo.png', 'mime_type' => 'image/png', 'byte_size' => 3, 'width' => 512, 'height' => 512, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_methods')->where('id', $this->bank)->update(['image_media_id' => $media]);
        DB::table('shipping_methods')->where('id', $this->courier)->update(['image_media_id' => $media]);
        $logo = '/storefront/media/payment-method/'.$this->bank.'/'.$media;
        $courierLogo = '/storefront/media/shipping-method/'.$this->courier.'/'.$media;
        $quote = $this->withCookie('cart', $this->cart([$this->product()->id => 1]))->postJson('/checkout/quote', ['shipping' => $this->address()])->json('quote');
        $methods = collect($quote['payment_methods'])->keyBy('id');

        self::assertSame($courierLogo, $quote['shipping_methods'][0]['image']['url']);
        $this->get($courierLogo)->assertOk();
        DB::table('shipping_methods')->where('id', $this->courier)->update(['is_active' => false]);
        $this->get($courierLogo)->assertNotFound();
        self::assertSame($logo, $methods[$this->bank]['image']['url']);
        self::assertNull($methods[$this->cod]['image']);
        $this->get($logo)->assertOk();
        // Only the method's own, active picture is served.
        $this->get('/storefront/media/payment-method/'.$this->cod.'/'.$media)->assertNotFound();
        DB::table('payment_methods')->where('id', $this->bank)->update(['is_active' => false]);
        $this->get($logo)->assertNotFound();
    }

    public function test_a_placed_order_is_announced_once_without_personal_data(): void
    {
        Event::fake([OrderPlaced::class]);
        $product = $this->product();
        $uuid = $this->cart([$product->id => 2]);
        $payload = $this->payload();

        $this->withCookie('cart', $uuid)->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->withCookie('cart', $uuid)->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();

        $order = DB::table('orders')->sole();
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $e): bool => $e->orderId === (string) $order->id && $e->orderNumber === $order->order_number
            && $e->currency === 'EUR' && $e->total === '25.5' && $e->shipping === '3.5'
            && $e->items === [['product_id' => (string) $product->id, 'sku' => $product->sku, 'name' => 'Speaker '.$product->id, 'variation' => null, 'quantity' => 2, 'total' => '20']]);
    }

    public function test_checkout_notifications_rollback_with_order_and_no_recipient_does_not_block_checkout(): void
    {
        $product = $this->product();
        $uuid = $this->cart([$product->id => 1]);
        DB::beginTransaction();
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('admin_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('admin_notifications', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->staff->roles()->detach();
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_validation_stock_restrictions_and_delivery_are_checked_on_the_server(): void
    {
        $product = $this->product(['quantity' => 5, 'out_of_stock_actions' => 'N']);
        $uuid = $this->cart([$product->id => 3]);
        $post = fn (array $changes) => $this->withCookie('cart', $uuid)->post('/checkout', $this->payload($changes));

        $post(['email' => 'not an email', 'phone' => '', 'shipping' => $this->address(['postal_code' => '', 'country_code' => 'ZZ'])])
            ->assertSessionHasErrors(['email', 'phone', 'shipping.postal_code', 'shipping.country_code']);
        $this->assertDatabaseCount('admin_notifications', 0);
        // A store with shipping methods needs one that delivers to the address; payment methods the products exclude are refused.
        $post(['shipping_method_id' => null])->assertSessionHasErrors(['shipping_method_id' => __('storefront.checkout_shipping_unavailable')]);
        $post(['shipping' => $this->address(['country_code' => 'IT', 'region_code' => null])])->assertSessionHasErrors('shipping_method_id');
        DB::table('payment_methods')->where('id', $this->bank)->update(['exclude_physical' => true]);
        $post(['payment_method_id' => $this->bank])->assertSessionHasErrors(['payment_method_id' => __('storefront.checkout_payment_unavailable')]);
        // So are methods whose maximum order subtotal the order exceeds (cash on delivery over 500 in Greece).
        DB::table('payment_methods')->where('id', $this->bank)->update(['exclude_physical' => false, 'max_subtotal' => '1']);
        // Placing the order checks the same offered methods.
        self::assertNotContains((string) $this->bank, $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address()])->json('quote.payment_methods.*.id'));
        // A line that can no longer be bought as it stands (stock fell under the quantity) stops the order.
        DB::table('products')->where('id', $product->id)->update(['quantity' => 2]);
        $post([])->assertSessionHasErrors(['cart' => __('storefront.checkout_cart_changed')]);
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(3, (int) DB::table('cart_items')->value('quantity'), 'A refused order leaves the cart untouched');

        // Digital products need no delivery: no shipping method, the address is the billing address.
        DB::table('cart_items')->delete();
        $download = $this->product(['type' => 'digital', 'weight' => null]);
        $digital = $this->cart([$download->id => 1]);
        $this->withCookie('cart', $digital)->postJson('/checkout/quote', ['shipping' => $this->address()])->assertJsonPath('quote.needs_shipping', false)->assertJsonPath('quote.shipping_methods', []);
        // Under its maximum subtotal a method is offered.
        DB::table('payment_methods')->where('id', $this->cod)->update(['exclude_digital' => false, 'max_subtotal' => '100000']);
        $this->withCookie('cart', $digital)->post('/checkout', $this->payload(['shipping_method_id' => null, 'billing_same' => false]))->assertSessionHasNoErrors();
        $order = DB::table('orders')->sole();
        self::assertSame([null, ['billing']], [$order->shipping_method_id, DB::table('order_addresses')->where('order_id', $order->id)->pluck('type')->all()]);
    }

    public function test_saved_delivery_details_survive_checkout_and_later_customer_address_changes(): void
    {
        [$user, $customer] = $this->customer();
        $this->actingAs($user);
        $product = $this->product();
        $uuid = $this->cart([$product->id => 1]);
        $notes = trim(str_repeat('Delivery instruction ', 15));
        $saved = DB::table('customer_addresses')->insertGetId(['customer_id' => $customer, ...$this->address(), 'floor' => '0', 'bell_name' => 'Example bell', 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]);
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload(['shipping_address_id' => $saved, 'shipping' => null]))->assertSessionHasNoErrors()->assertRedirect();
        $order = DB::table('orders')->sole();
        $address = DB::table('order_addresses')->where('order_id', $order->id)->where('type', 'billing')->sole();
        self::assertSame(['0', 'Example bell', $notes], [$address->floor, $address->bell_name, $address->notes]);
        self::assertSame($this->courier, (string) $order->shipping_method_id);
        DB::table('customer_addresses')->where('id', $saved)->update(['floor' => '9', 'bell_name' => 'New name', 'notes' => 'Changed']);
        $this->get('/account/orders/'.$order->id)->assertInertia(fn (Assert $p) => $p
            ->where('storefront.regions.content.0.blocks.0.content.account.order.billing.lines', fn ($lines) => $lines->contains('Floor: 0') && $lines->contains('Name on the doorbell: Example bell') && $lines->contains('Delivery notes: '.$notes)));
        $this->flushSession();
        $this->actingAs($this->staff)->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $p) => $p->where('order.billing.floor', '0')
            ->where('order.billing.notes', $notes)->where('shipping.method.id', $this->courier)->where('shipping.method.name', 'Courier')->where('payment.method.name', 'Cash on delivery'));
    }

    public function test_an_order_placed_before_shipping_was_configured_does_not_acquire_a_later_method(): void
    {
        DB::table('shipping_methods')->update(['is_active' => false]);
        $product = $this->product();
        $uuid = $this->cart([$product->id => 1]);
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload(['shipping_method_id' => null]))->assertSessionHasNoErrors();
        $order = DB::table('orders')->sole();
        DB::table('shipping_methods')->update(['is_active' => true]);
        $this->flushSession();
        $this->actingAs($this->staff)->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $p) => $p->where('shipping.method', null)->where('shipping.charge', '€0.00'));
        self::assertNull(DB::table('orders')->where('id', $order->id)->value('shipping_method_id'));
    }

    public function test_a_store_can_require_an_account_and_customers_use_their_own_addresses(): void
    {
        DB::table('general_settings')->update(['checkout_requires_account' => true]);
        $product = $this->product();
        $uuid = $this->cart([$product->id => 1]);
        $this->withCookie('cart', $uuid)->get('/checkout')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'account_required', true));
        // Enforced where the order is placed, not only on the page.
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasErrors(['account' => __('storefront.checkout_account_required')]);
        self::assertSame(0, DB::table('orders')->count());

        // Signing in from checkout comes back to it, with the guest cart merged into the customer's.
        [$user, $customer] = $this->customer();
        $this->get('/login?return=checkout')->assertOk()->assertSessionHas(CheckoutController::RETURN, true);
        $this->withCookie('cart', $uuid)->post('/login', ['email' => 'ria@example.test', 'password' => 'a shopper passphrase'])->assertRedirect('/checkout');
        $saved = (string) DB::table('customer_addresses')->insertGetId(['customer_id' => $customer, ...$this->address(['first_name' => 'Ria', 'last_name' => 'Stone', 'street_number' => '7']), 'floor' => '2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', $customer)->update(['default_shipping_address_id' => $saved, 'default_billing_address_id' => $saved]);
        $this->get('/checkout')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'account_required', false)
            ->where(self::BLOCK.'contact.email', 'ria@example.test')->where(self::BLOCK.'addresses.0.id', $saved)->where(self::BLOCK.'defaults.shipping', $saved)
            ->where(self::BLOCK.'quote.shipping_method_id', $this->courier)->where(self::BLOCK.'cart.lines.0.id', (string) $product->id));

        // Another customer's address is never usable, even by ID.
        [, $other] = $this->customer('other@example.test');
        $foreign = (string) DB::table('customer_addresses')->insertGetId(['customer_id' => $other, ...$this->address(), 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/checkout', $this->payload(['shipping_address_id' => $foreign, 'shipping' => null]))->assertSessionHasErrors(['shipping_address_id' => __('storefront.checkout_address_invalid')]);

        // A customer orders under their account e-mail, whatever is typed, and may keep a new address.
        $this->post('/checkout', $this->payload(['email' => 'someone@else.test', 'save_address' => true, 'shipping' => $this->address(['line1' => 'Stadiou', 'street_number' => '3', 'floor' => '4', 'bell_name' => 'Stone', 'notes' => 'Ring twice'])]))->assertSessionHasNoErrors();
        $order = DB::table('orders')->sole();
        self::assertSame(['ria@example.test', (string) $customer], [$order->email, (string) $order->customer_id]);
        self::assertTrue(DB::table('customer_addresses')->where('customer_id', $customer)->where('line1', 'Stadiou')->where('street_number', '3')->exists());
        // Floor, doorbell name and delivery notes travel with the order's address.
        self::assertSame(['4', 'Stone', 'Ring twice'], array_values((array) DB::table('order_addresses')->where('order_id', $order->id)->where('type', 'billing')->first(['floor', 'bell_name', 'notes'])));
        self::assertSame(0, DB::table('cart_items')->count());
        $this->get('/account/orders/'.$order->id)->assertOk();
    }

    public function test_the_carts_coupon_and_gift_card_are_used_with_the_order(): void
    {
        $actor = (string) $this->staff->id;
        app(Coupons::class)->save($actor, null, ['name' => 'Summer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $card = app(GiftCards::class)->save($actor, null, ['recipient_name' => 'Ria', 'sender_name' => 'Shop', 'recipient_email' => 'ria@example.test', 'message' => '', 'amount' => '10.00', 'locale' => 'en', 'status' => 'active', 'notify' => false]);
        $product = $this->product(['price' => '40']);
        $uuid = $this->cart([$product->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'SUMMER'])->assertOk();
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => (string) DB::table('gift_cards')->where('id', $card)->value('number')])->assertOk();

        // 40 − 10% + 3.50 courier + 2 fee = 41.50, of which the card pays 10.
        $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'payment_method_id' => $this->cod])
            ->assertJsonPath('quote.totals.discount', '4.00')->assertJsonPath('quote.totals.total', '41.50')->assertJsonPath('quote.totals.gift_card', '10.00')->assertJsonPath('quote.totals.due', '31.50');
        $response = $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasNoErrors();
        $order = DB::table('orders')->sole();
        self::assertSame(['SUMMER', '41.500000'], [$order->coupon_code, $order->total]);
        self::assertSame(['gift_card', 'captured', '10.000000'], array_values((array) DB::table('order_payments')->where('order_id', $order->id)->first(['kind', 'state', 'amount'])));
        self::assertSame('0.000000', DB::table('gift_cards')->where('id', $card)->value('balance'));
        self::assertSame([null, null], array_values((array) DB::table('carts')->where('uuid', $uuid)->first(['coupon_id', 'gift_card_id'])));
        $this->confirmation($response)->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'placed.totals.due', '31.50'));
    }

    public function test_the_checkout_sign_in_window_returns_to_the_checkout_only_when_it_works(): void
    {
        $this->customer();
        // The window posts to /login?return=checkout without ever opening the sign-in page.
        $this->post('/login?return=checkout', ['email' => 'ria@example.test', 'password' => 'not her passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login?return=checkout', ['email' => 'ria@example.test', 'password' => 'a shopper passphrase'])->assertRedirect('/checkout');
        $this->assertAuthenticated();
        // Without the mark, signing in goes to the account as before.
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => 'ria@example.test', 'password' => 'a shopper passphrase'])->assertRedirect('/account');
    }

    public function test_checkout_follows_the_cart_switch_and_an_empty_cart(): void
    {
        $this->get('/checkout')->assertRedirect('/cart');
        $this->post('/checkout', $this->payload())->assertSessionHasErrors(['cart' => __('storefront.checkout_empty')]);
        DB::table('general_settings')->update(['cart_enabled' => false]);
        $this->get('/checkout')->assertNotFound();
        $this->postJson('/checkout/quote', [])->assertNotFound();
        $this->post('/checkout', $this->payload())->assertNotFound();
    }

    public function test_the_checkout_block_asks_to_accept_pages_and_offers_newsletter_and_invoice(): void
    {
        $uuid = $this->cart([$this->product()->id => 1]);
        $page = DB::table('pages')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('page_translations')->insert(['page_id' => $page, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Terms of use', 'content' => '<p>The terms</p>', 'slug' => 'terms-of-use']);
        $list = DB::table('newsletter_lists')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $layout = DB::table('storefront_layouts')->where('page_type', 'checkout')->value('id');
        $block = DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'main_content')->value('id');
        DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => 'required_pages', 'position' => 1, 'page_id' => $page]);
        DB::table('storefront_block_selections')->insert(['block_id' => $block, 'field' => 'newsletter_list', 'position' => 1, 'newsletter_list_id' => $list]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');

        $this->invoiceProviders();
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::GLOBAL.'*' => Http::response($this->globalVat())]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => 'GB123456789'])->assertOk();
        Http::assertSentCount(1);

        // The configured invoice box and page content are offered by the checkout block.
        $html = (string) json_encode($this->withCookie('cart', $uuid)->get('/checkout')->assertOk()->viewData('page'));
        self::assertStringContainsString('"checkout_options":{"pages":[{"id":"'.$page.'","name":"Terms of use","content":"<p>The terms<\\/p>","required":true}]', $html);
        self::assertStringContainsString('"invoice":true', $html);

        // The server refuses an order whose required pages were not accepted.
        $post = fn (array $changes) => $this->withCookie('cart', $uuid)->post('/checkout', $this->payload($changes));
        $post([])->assertSessionHasErrors(['accepted_pages' => __('storefront.checkout_accept_required', ['pages' => 'Terms of use'])]);
        $this->confirmation($post(['accepted_pages' => [(string) $page], 'newsletter' => true, 'invoice_requested' => true, 'invoice_country_code' => 'GB', 'invoice_vat_number' => '123456789']));
        self::assertTrue((bool) DB::table('orders')->sole()->invoice_requested);
        $subscriber = DB::table('newsletter_subscribers')->where('email', 'maria@example.test')->value('id');
        self::assertTrue(DB::table('newsletter_list_subscribers')->where('list_id', $list)->where('subscriber_id', $subscriber)->exists());

        // A signed-in customer already on the list is not asked again; another customer still is.
        $options = fn (string $customer) => app(CheckoutOptions::class)->forBlock(['selections' => ['newsletter_list' => [(string) $list]], 'settings' => [], 'translations' => []], 'en', $customer)['newsletter'];
        self::assertNull($options((string) $this->customer('maria@example.test')[1]));
        self::assertNotNull($options((string) $this->customer('other@example.test')[1]));
    }

    public function test_a_payment_restriction_takes_methods_away_from_that_email_or_phone_only(): void
    {
        $uuid = $this->cart([$this->product()->id => 1]);
        $blacklist = app(CustomerBlacklistAdministration::class);
        // Always the current cart: placing an order or signing in changes which one the shopper has.
        $offered = function (array $contact) use (&$uuid): array {
            return $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'billing_same' => true, 'payment_method_id' => $this->cod, ...$contact])->json('quote.payment_methods.*.id');
        };
        $both = [$this->cod, $this->bank];
        self::assertEqualsCanonicalizing($both, $offered(['email' => 'maria@example.test']));

        // Cash on delivery taken away from this e-mail (any case): the bank deposit stays, other buyers keep both.
        $email = $blacklist->add((string) $this->staff->id, 'email', 'Maria@Example.TEST', 'Refuses cash on delivery parcels', 'payment_methods', [$this->cod]);
        self::assertSame([$this->bank], $offered(['email' => ' maria@example.test ']));
        self::assertEqualsCanonicalizing($both, $offered(['email' => 'someone@example.test', 'phone' => '+30 210 123 4567']));
        // The quote no longer keeps cash on delivery selected, and placing the order with it is refused on the server.
        $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'email' => 'maria@example.test', 'payment_method_id' => $this->cod])->assertJsonPath('quote.payment_method_id', null);
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasErrors(['payment_method_id' => __('storefront.checkout_payment_unavailable')]);
        self::assertSame(0, DB::table('orders')->count());
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload(['payment_method_id' => $this->bank]))->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('orders')->count());

        // By phone, however it is written, including the phone of the delivery address.
        $uuid = $this->cart([$this->product()->id => 1]);
        $blacklist->add((string) $this->staff->id, 'phone', '+30 690 000 0142', 'Refused two parcels', 'payment_methods', [$this->cod]);
        self::assertSame([$this->bank], $offered(['email' => 'other@example.test', 'phone' => '0030 6900000142']));
        self::assertSame([$this->bank], $offered(['email' => 'other@example.test', 'phone' => '+30 210 000 0000', 'shipping' => $this->address(['phone' => '+306900000142'])]));

        // A signed-in customer is matched by the account's e-mail, whatever is typed.
        [$user] = $this->customer('maria@example.test');
        $this->actingAs($user);
        self::assertSame([$this->bank], $offered(['email' => 'not-me@example.test']));
        $this->flushSession();

        // Lifting the entry gives the method back; a full block does not change what checkout offers.
        $blacklist->release((string) $this->staff->id, $email);
        auth()->logout();
        $uuid = $this->cart([$this->product()->id => 1]);
        self::assertEqualsCanonicalizing($both, $offered(['email' => 'maria@example.test']));
        $blacklist->add((string) $this->staff->id, 'email', 'maria@example.test', 'Chargeback fraud');
        self::assertEqualsCanonicalizing($both, $offered(['email' => 'maria@example.test']));
    }

    public function test_courier_value_limits_and_cash_on_delivery_limits_end_to_end(): void
    {
        $actor = (string) $this->staff->id;
        // Prices 100 + 24% VAT added on top: two items are worth 248 once taxed; the coupon and taxes count, shipping does not.
        $area = (string) DB::table('rate_areas')->value('id');
        $vat = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($area, 'percent', '24')]));
        $product = $this->product(['price' => '100']);
        DB::table('product_taxes')->insert(['product_id' => $product->id, 'tax_id' => $vat]);
        $express = app(ShippingMethods::class)->save($actor, null, null, [
            'translations' => ['en' => ['name' => 'Express', 'description' => '', 'delivery_time' => '']], 'calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true,
            'rates' => [['rate_area_id' => $area, 'weight_from' => '0', 'weight_to' => '', 'base' => '6', 'per_unit' => '0']],
        ]);
        $limit = fn (string $method, bool $on, ?string $max) => DB::table('shipping_methods')->where('id', $method)->update(['value_limit_enabled' => $on, 'max_shipment_value' => $max]);
        $uuid = $this->cart([$product->id => 2]);
        $quote = fn (array $input = []): array => $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'billing_same' => true, 'payment_method_id' => $this->bank, ...$input])->assertOk()->json('quote');
        $offered = fn (array $input = []): array => array_column($quote($input)['shipping_methods'], 'id');

        // No limits: both couriers, with their normal charges, and the totals as before.
        $q = $quote(['shipping_method_id' => $express]);
        self::assertEqualsCanonicalizing([$this->courier, $express], array_column($q['shipping_methods'], 'id'));
        self::assertSame(['3.50', '6.00', '6.00', '254.00'], [collect($q['shipping_methods'])->firstWhere('id', $this->courier)['amount'], collect($q['shipping_methods'])->firstWhere('id', $express)['amount'], $q['totals']['shipping'], $q['totals']['total']]);

        // Below, equal to and above the courier's limit (value 248); each courier keeps its own limit.
        $limit($this->courier, true, '300');
        $limit($express, true, '248');
        self::assertEqualsCanonicalizing([$this->courier, $express], $offered());
        $limit($express, true, '247.99');
        self::assertSame([$this->courier], $offered());
        // A switched-off limit is no limit, whatever amount it keeps; zero allows nothing worth more than zero.
        $limit($express, false, '1');
        self::assertEqualsCanonicalizing([$this->courier, $express], $offered());
        $limit($express, true, '0');
        self::assertSame([$this->courier], $offered());

        // A courier picked earlier and now over its limit is no longer selected, and placing the order with it is refused.
        self::assertSame($this->courier, $quote(['shipping_method_id' => $express])['shipping_method_id']);
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload(['shipping_method_id' => $express, 'payment_method_id' => $this->bank]))->assertSessionHasErrors(['shipping_method_id' => __('storefront.checkout_shipping_unavailable')]);

        // A changed cart is measured again: a third item (372) is over the courier's 300 too, so nothing can deliver it.
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$product->id, ['quantity' => 3])->assertOk();
        self::assertSame([], $offered());
        $this->withSession([StorefrontLocaleController::SESSION_KEY => 'el'])->withCookie('cart', $uuid)->post('/checkout', $this->payload(['payment_method_id' => $this->bank]))
            ->assertSessionHasErrors(['shipping_method_id' => trans('storefront.checkout_no_shipping', [], 'el')]);
        self::assertNotSame(trans('storefront.checkout_no_shipping', [], 'en'), trans('storefront.checkout_no_shipping', [], 'el'));
        self::assertSame(0, DB::table('orders')->count());

        // A coupon lowers the shipment value the same way at checkout and when the order is placed: 372 − 30% = 260.40.
        app(Coupons::class)->save($actor, null, ['name' => 'Thirty', 'code' => 'THIRTY', 'type' => 'percentage', 'amount' => '30', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'THIRTY'])->assertOk();
        self::assertSame([$this->courier], $offered());
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload(['shipping_method_id' => $this->courier, 'payment_method_id' => $this->bank]))->assertSessionHasNoErrors();
        $order = DB::table('orders')->sole();
        self::assertSame([$this->courier, '3.500000'], [(string) $order->shipping_method_id, $order->shipping_total]);
    }

    public function test_cash_on_delivery_is_offered_up_to_and_including_its_limit_only(): void
    {
        $uuid = $this->cart([$this->product(['price' => '100'])->id => 2]);
        $methods = fn (): array => $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'payment_method_id' => $this->cod])->json('quote.payment_methods.*.id');
        self::assertContains($this->cod, $methods());
        foreach (['200' => true, '200.01' => true, '199.99' => false] as $max => $offered) {
            DB::table('payment_methods')->where('id', $this->cod)->update(['max_subtotal' => $max]);
            self::assertSame($offered, in_array($this->cod, $methods(), true), 'limit '.$max);
        }
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasErrors(['payment_method_id' => __('storefront.checkout_payment_unavailable')]);
        DB::table('payment_methods')->where('id', $this->cod)->update(['max_subtotal' => null]);
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasNoErrors();
        // The fee is charged as before: 200 + 3.50 courier + 2 fee.
        self::assertSame('205.500000', DB::table('orders')->value('total'));
    }

    public function test_cart_and_quote_requests_do_not_use_up_the_place_order_limit(): void
    {
        $product = $this->product();
        $uuid = $this->cart([$product->id => 1]);
        // Many cart changes and quotes in one minute (typing an address re-quotes) are still followed by a successful order.
        foreach (range(1, 15) as $n) {
            $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$product->id, ['quantity' => 1 + $n % 2])->assertOk();
            $this->withCookie('cart', $uuid)->postJson('/checkout/quote', ['shipping' => $this->address(), 'email' => 'maria'.$n.'@example.test'])->assertOk();
        }
        $this->withCookie('cart', $uuid)->post('/checkout', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
    }

    private function invoiceProviders(): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => ['GR', 'GB'], 'greek_provider' => 'aade', 'international_provider' => 'vatsense', 'aade_username' => 'registry-test-user', 'aade_password' => 'registry-test-password', 'vatsense_key' => 'global-test-key']);
    }

    private function globalVat(): array
    {
        return ['success' => true, 'data' => ['valid' => true, 'company' => ['country_code' => 'GB', 'vat_number' => '123456789', 'company_name' => 'Verified Company']]];
    }

    private function aadeVat(string $active = '1', string $firm = 'ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ'): string
    {
        return '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><rg_ws_public2_result_rtType><error_rec><error_code/></error_rec><basic_rec><afm>094259216</afm><deactivation_flag>'.$active.'</deactivation_flag><firm_flag_descr>'.$firm.'</firm_flag_descr><stop_date/><onomasia>Greek Company</onomasia><normal_vat_system_flag>N</normal_vat_system_flag></basic_rec></rg_ws_public2_result_rtType></env:Body></env:Envelope>';
    }

    public function test_an_invoice_requires_a_recent_matching_verification_and_saves_the_trusted_snapshot_once(): void
    {
        $this->freezeTime();
        $verifiedAt = now()->toIso8601String();
        $this->invoiceProviders();
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::GLOBAL.'*' => Http::response($this->globalVat())]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid);
        $payload = $this->payload(['invoice_requested' => true, 'invoice_country_code' => 'GB', 'invoice_vat_number' => '123456789', 'invoice_details' => ['business_name' => 'Forged name']]);
        $this->post('/checkout', $payload)->assertSessionHasErrors(['invoice_requested' => __('checkout.verify_first')]);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => 'gb 123-456-789'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('verification.business_name', 'Verified Company');
        // A fresh matching lookup reuses only this session's result, without another billable request.
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => '123456789'])->assertOk();
        $this->post('/checkout', [...$payload, 'invoice_vat_number' => '987654321'])->assertSessionHasErrors('invoice_requested');
        $this->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->travel(16)->minutes();
        $this->post('/checkout', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('order_invoice_verifications', ['order_id' => DB::table('orders')->sole()->id, 'provider' => 'vatsense', 'country_code' => 'GB', 'vat_number' => '123456789', 'business_name' => 'Verified Company']);
        self::assertTrue((bool) DB::table('orders')->sole()->invoice_requested);
        $this->actingAs($this->staff)->get('/admin/orders/'.DB::table('orders')->sole()->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.invoice_details.country', 'United Kingdom')->where('order.invoice_details.business_name', 'Verified Company')->where('order.invoice_details.verified_at', $verifiedAt));
        Http::assertSent(fn ($r) => $r->url() === InvoiceVerification::GLOBAL.'?vat_number=GB123456789' && $r->hasHeader('Authorization', 'Basic '.base64_encode('user:global-test-key')));
        Http::assertSentCount(1);
    }

    public function test_verification_expires_and_cannot_move_between_carts_customers_or_settings_versions(): void
    {
        $this->freezeTime();
        $this->invoiceProviders();
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::GLOBAL.'*' => Http::response($this->globalVat())]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => '123456789'])->assertOk();
        $proof = session('checkout.invoice_verification');
        $payload = $this->payload(['invoice_requested' => true, 'invoice_country_code' => 'GB', 'invoice_vat_number' => '123456789']);
        foreach (['cart' => (string) Str::uuid(), 'customer' => '99999', 'version' => 999, 'time' => now()->subMinutes(16)->getTimestamp()] as $field => $value) {
            $this->withSession(['checkout.invoice_verification' => [...$proof, $field => $value]])->post('/checkout', $payload)->assertSessionHasErrors('invoice_requested');
        }
        $this->withSession(['checkout.invoice_verification' => $proof]);
        DB::table('checkout_settings')->increment('lock_version');
        $this->post('/checkout', $payload)->assertSessionHasErrors('invoice_requested');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_greek_registry_checks_require_a_customer_and_an_active_business_not_vat_exemption_status(): void
    {
        $this->invoiceProviders();
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::AADE => Http::response($this->aadeVat(), 200, ['Content-Type' => 'application/soap+xml'])]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.sign_in')]);
        Http::assertNothingSent();
        [$user] = $this->customer();
        $this->actingAs($user);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259217'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.invalid')]);
        Http::assertNothingSent();
        $this->cart([$this->product()->id => 1]);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => 'EL094259216'])->assertOk()->assertJsonPath('verification.provider', 'aade')->assertJsonPath('verification.business_name', 'Greek Company');
        Http::assertSent(fn ($r) => $r->url() === InvoiceVerification::AADE && str_contains($r->body(), '<reg:afm_called_for>094259216</reg:afm_called_for>') && str_contains($r->body(), '<sec:Username>registry-test-user</sec:Username>'));
    }

    #[TestWith(['2', 'ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ'])]
    #[TestWith(['1', 'ΜΗ ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ'])]
    public function test_greek_inactive_and_non_business_records_are_refused(string $active, string $firm): void
    {
        $this->invoiceProviders();
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::AADE => Http::response($this->aadeVat($active, $firm))]);
        [$user] = $this->customer();
        $this->actingAs($user);
        $this->cart([$this->product()->id => 1]);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.invalid')]);
        self::assertNull(session('checkout.invoice_verification'));
        Http::assertSentCount(1);
    }

    #[TestWith(['invalid'])]
    #[TestWith(['outage'])]
    #[TestWith(['connection'])]
    #[TestWith(['malformed'])]
    #[TestWith(['mismatch'])]
    public function test_failed_global_lookups_never_approve_an_invoice_and_receipt_checkout_still_works(string $failure): void
    {
        $this->invoiceProviders();
        Http::preventStrayRequests();
        $result = $this->globalVat();
        if ($failure === 'invalid') {
            $result['data']['valid'] = false;
        }
        if ($failure === 'mismatch') {
            $result['data']['company']['vat_number'] = '987654321';
        }
        if ($failure === 'malformed') {
            unset($result['data']['valid']);
        }
        Http::fake([InvoiceVerification::GLOBAL.'*' => $failure === 'connection' ? Http::failedConnection() : Http::response($result, $failure === 'outage' ? 503 : 200)]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => '123456789'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.'.($failure === 'invalid' ? 'invalid' : 'unavailable'))]);
        self::assertNull(session('checkout.invoice_verification'));
        $this->post('/checkout', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        self::assertFalse((bool) DB::table('orders')->sole()->invoice_requested);
        $this->assertDatabaseCount('order_invoice_verifications', 0);
        Http::assertSentCount(1);
    }

    public function test_invoice_lookup_requires_configuration_a_cart_and_an_open_store_and_is_throttled(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/checkout/invoice/verify', [])->assertNotFound();
        $this->invoiceProviders();
        $this->postJson('/checkout/invoice/verify', [])->assertNotFound();
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', [])->assertUnprocessable()->assertJsonValidationErrors(['country_code', 'vat_number']);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'US', 'vat_number' => '123456789'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.unavailable')]);
        DB::table('general_settings')->update(['cart_enabled' => false]);
        $this->postJson('/checkout/invoice/verify', [])->assertNotFound();
        DB::table('general_settings')->update(['cart_enabled' => true]);
        $this->postJson('/checkout/invoice/verify', [])->assertUnprocessable();
        $this->postJson('/checkout/invoice/verify', [])->assertStatus(429);
        Http::assertNothingSent();
    }

    #[TestWith(['fault'])]
    #[TestWith(['xml'])]
    #[TestWith(['doctype'])]
    #[TestWith(['mismatch'])]
    public function test_untrusted_or_malformed_registry_responses_do_not_create_verification(string $kind): void
    {
        $this->invoiceProviders();
        Http::preventStrayRequests();
        $xml = match ($kind) {
            'fault' => '<Envelope><Fault><Reason>provider problem</Reason></Fault></Envelope>',
            'xml' => '<not valid xml',
            'doctype' => '<!DOCTYPE foo [<!ENTITY external SYSTEM "file:///etc/passwd">]>'.$this->aadeVat(),
            default => str_replace('094259216', '123456789', $this->aadeVat()),
        };
        Http::fake([InvoiceVerification::AADE => Http::response($xml)]);
        [$user] = $this->customer();
        $this->actingAs($user);
        $this->cart([$this->product()->id => 1]);
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertUnprocessable()->assertJsonValidationErrors('vat_number');
        self::assertNull(session('checkout.invoice_verification'));
        Http::assertSentCount(1);
    }

    public function test_disabled_invoice_configuration_hides_the_option_and_ignores_a_forged_checkbox(): void
    {
        Http::preventStrayRequests();
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->get('/checkout')->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.1.blocks.0.content.checkout_options.invoice', false));
        $this->post('/checkout', $this->payload(['invoice_requested' => true, 'invoice_country_code' => 'GB', 'invoice_vat_number' => '123456789']))->assertSessionHasNoErrors()->assertRedirect();
        self::assertFalse((bool) DB::table('orders')->sole()->invoice_requested);
        $this->assertDatabaseCount('order_invoice_verifications', 0);
        Http::assertNothingSent();
    }

    public function test_invoice_country_selection_filters_checkout_rejects_tampering_and_revokes_old_approval(): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => ['GR', 'US']]);
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::VIES => Http::response($this->viesVat())]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->get('/checkout')->assertInertia(fn (Assert $p) => $p->where(self::BLOCK.'invoice_countries', [['code' => 'GR', 'name' => 'Greece']]));
        // DE is covered by VIES but disabled by the shop; US is selected but not covered by VIES.
        foreach (['DE', 'US'] as $country) {
            $this->postJson('/checkout/invoice/verify', ['country_code' => $country, 'vat_number' => '123456789'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.unavailable')]);
        }
        Http::assertNothingSent();
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertOk();
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 2, 'enabled' => true, 'country_codes' => ['DE']]);
        $this->post('/checkout', $this->payload(['invoice_requested' => true, 'invoice_country_code' => 'GR', 'invoice_vat_number' => '094259216']))->assertSessionHasErrors('invoice_requested');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_no_selected_invoice_countries_hides_invoice_requests_and_keeps_receipt_checkout_available(): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => []]);
        Http::preventStrayRequests();
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->get('/checkout')->assertInertia(fn (Assert $p) => $p->where(self::BLOCK.'invoice_countries', [])->where('storefront.regions.content.1.blocks.0.content.checkout_options.invoice', false));
        $this->post('/checkout', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        self::assertFalse((bool) DB::table('orders')->sole()->invoice_requested);
        Http::assertNothingSent();
    }

    private function viesVat(string $country = 'EL', string $vat = '094259216', string $valid = 'true'): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><countryCode>'.$country.'</countryCode><vatNumber>'.$vat.'</vatNumber><requestDate>2026-11-12</requestDate><valid>'.$valid.'</valid><name>EU Company</name></checkVatResponse></soap:Body></soap:Envelope>';
    }

    #[TestWith(['GR', 'EL', '094259216'])]
    #[TestWith(['DE', 'DE', '123456789'])]
    public function test_free_vies_works_without_any_key_or_customer_login_and_maps_the_greek_prefix(string $country, string $prefix, string $vat): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => ['GR', 'DE']]);
        Http::preventStrayRequests();
        Http::fake([InvoiceVerification::VIES => Http::response($this->viesVat($prefix, $vat))]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => $country, 'vat_number' => $prefix.$vat])->assertOk()->assertJsonPath('verification.provider', 'vies')->assertJsonPath('verification.country_code', $country);
        $this->post('/checkout', $this->payload(['invoice_requested' => true, 'invoice_country_code' => $country, 'invoice_vat_number' => $vat]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_invoice_verifications', ['provider' => 'vies', 'country_code' => $country, 'vat_number' => $vat]);
        Http::assertSent(fn ($r) => $r->url() === InvoiceVerification::VIES && ! $r->hasHeader('Authorization') && str_contains($r->body(), '<vat:countryCode>'.$prefix.'</vat:countryCode>'));
        Http::assertSentCount(1);
    }

    #[TestWith(['invalid'])]
    #[TestWith(['fault'])]
    #[TestWith(['mismatch'])]
    #[TestWith(['malformed'])]
    #[TestWith(['connection'])]
    public function test_failed_free_vies_checks_do_not_approve_or_fall_back_to_a_paid_provider(string $kind): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => ['GR', 'DE']]);
        Http::preventStrayRequests();
        $body = match ($kind) {
            'invalid' => $this->viesVat(valid: 'false'),
            'fault' => '<Envelope><Fault><faultstring>MS_UNAVAILABLE</faultstring></Fault></Envelope>',
            'mismatch' => $this->viesVat('DE'),
            'malformed' => $this->viesVat(valid: ''),
            default => '',
        };
        Http::fake([InvoiceVerification::VIES => $kind === 'connection' ? Http::failedConnection() : Http::response($body)]);
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.'.($kind === 'invalid' ? 'invalid' : 'unavailable'))]);
        self::assertNull(session('checkout.invoice_verification'));
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GB', 'vat_number' => '123456789'])->assertUnprocessable()->assertJsonValidationErrors(['vat_number' => __('checkout.unavailable')]);
        Http::assertSentCount(1);
    }

    public function test_merchant_can_choose_the_existing_free_checksum_check_with_its_limited_assurance_recorded(): void
    {
        app(CheckoutSettings::class)->save((string) $this->staff->id, ['version' => 1, 'enabled' => true, 'country_codes' => ['GR'], 'greek_provider' => 'local']);
        Http::preventStrayRequests();
        $uuid = $this->cart([$this->product()->id => 1]);
        $this->withCookie('cart', $uuid)->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259217'])->assertUnprocessable();
        $this->postJson('/checkout/invoice/verify', ['country_code' => 'GR', 'vat_number' => '094259216'])->assertOk()->assertJsonPath('verification.provider', 'local')->assertJsonPath('verification.business_name', null);
        $this->post('/checkout', $this->payload(['invoice_requested' => true, 'invoice_country_code' => 'GR', 'invoice_vat_number' => '094259216']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_invoice_verifications', ['provider' => 'local', 'country_code' => 'GR', 'vat_number' => '094259216', 'business_name' => null]);
        Http::assertNothingSent();
    }
}
