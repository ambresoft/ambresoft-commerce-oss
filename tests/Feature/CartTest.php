<?php

namespace Tests\Feature;

use App\Cart\Infrastructure\Carts;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductPackages;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Marketing\Infrastructure\Coupons;
use App\Marketing\Infrastructure\GiftCards;
use App\Marketing\Infrastructure\Promotions;
use App\Order\Infrastructure\OrderPricing;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Infrastructure\CartPage;
use App\Storefront\Infrastructure\ProductPackage;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    /** Where the Cart layout's main content block carries the cart. */
    private const BLOCK = 'storefront.regions.content.0.blocks.0.content.cart.';

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // JSON calls carry the browser's cookies, as the storefront's fetch calls do.
        $this->withCredentials();
        Cache::flush();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        app()->setLocale('en');
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => $this->locale(), 'name' => 'Audio', 'slug' => 'audio']);
    }

    public function test_package_picker_only_returns_products_after_a_submitted_search(): void
    {
        $product = $this->product();
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/product-packages')->assertOk()->assertInertia(fn (Assert $page) => $page->has('candidates', 0)->where('productSearch', ''));
        $this->get('/admin/product-packages?search='.urlencode($product->sku))->assertOk()->assertInertia(fn (Assert $page) => $page->has('candidates', 1)->where('candidates.0.id', (string) $product->id)->where('productSearch', $product->sku));
        $this->get('/admin/product-packages?search=%20%20')->assertOk()->assertInertia(fn (Assert $page) => $page->has('candidates', 0)->where('productSearch', ''));
    }

    public function test_reusable_packages_assign_edit_and_add_atomically_without_changing_buy_with(): void
    {
        $main = $this->product();
        $accessory = $this->product();
        $manual = $this->product();
        DB::table('product_companions')->insert(['product_id' => $main->id, 'companion_id' => $manual->id, 'position' => 1]);
        $packages = app(ProductPackages::class);
        $actor = (string) $this->staff(super: true)->id;
        $names = app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l) => [$l->code => 'Accessories'])->all();
        $id = $packages->save($actor, null, ['translations' => $names, 'items' => [(string) $accessory->id]]);
        $packages->assign($actor, $id, [], (string) $this->category->id);
        self::assertSame($id, $packages->assigned((string) $main->id));
        self::assertSame($id, $packages->assigned((string) $manual->id));
        $packages->assign($actor, $id, [(string) $main->id]);
        self::assertSame(3, DB::table('product_package_assignments')->count());
        $cart = app(Carts::class)->create(null);
        $storefront = app(ProductPackage::class);
        $storefront->add($cart, (string) $main->id, null);
        self::assertSame(2, DB::table('cart_items')->where('cart_id', $cart->id)->count());
        self::assertSame(1, DB::table('product_companions')->where('product_id', $main->id)->count());
        $packages->save($actor, $id, ['version' => 1, 'translations' => $names, 'items' => [(string) $main->id, (string) $accessory->id]]);
        self::assertCount(2, $storefront->show((string) $main->id, ['locale' => 'en', 'customer' => null])['items']);
        DB::table('products')->where('id', $accessory->id)->update(['available_from' => now()->addYear()->toDateString()]);
        try {
            $storefront->add($cart, (string) $main->id, null);
            self::fail('Unavailable package must be rejected');
        } catch (ValidationException) {
            self::assertSame(1, (int) DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $main->id)->value('quantity'));
        }
        $packages->assign($actor, null, [(string) $main->id]);
        self::assertNull($packages->assigned((string) $main->id));
        $packages->delete($actor, $id, 2);
        self::assertSame(0, DB::table('product_package_assignments')->count());
        self::assertSame(1, DB::table('product_companions')->count());
    }

    private function locale(): int
    {
        return (int) DB::table('locales')->where('code', 'en')->value('id');
    }

    /** @param array<string, mixed> $attributes */
    private function product(array $attributes = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'CRT-'.Str::upper(Str::random(6)), 'price' => '10', 'quantity' => 50, 'category_id' => $this->category->id, 'status' => 'active', ...$attributes]);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => 'Speaker '.$p->id, 'slug' => 'speaker-'.$p->id]);

        return $p;
    }

    /** A signed-in customer (verified, linked, active profile). */
    private function customer(string $email = 'ria@example.test'): array
    {
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => $email, 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $id = (int) DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $email, 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $id];
    }

    private function cookie(TestResponse $response): ?string
    {
        return $response->getCookie('cart')?->getValue();
    }

    private function promotion(array $changes = []): string
    {
        return app(Promotions::class)->save((string) $this->staff(super: true)->id, null, array_replace([
            'translations' => app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l): array => [$l->code => ['name' => 'Cart 10%', 'description' => '']])->all(),
            'status' => 'active', 'mode' => 'automatic', 'coupon_id' => null, 'starts_at' => null, 'ends_at' => null,
            'priority' => '100', 'stop_other' => false, 'match' => 'all', 'benefit' => 'percentage', 'scope' => 'order', 'amount' => '10',
            'all_products' => true, 'exclude_sale' => false, 'all_shipping' => true, 'conditions' => [], 'products' => [], 'categories' => [],
            'shipping' => [], 'excluded_products' => [], 'excluded_categories' => [], 'excluded_shipping' => [],
        ], $changes));
    }

    public function test_automatic_promotions_apply_without_codes_and_recheck_the_live_cart(): void
    {
        $promotion = $this->promotion(['conditions' => [['type' => 'subtotal', 'minimum' => '100', 'maximum' => null, 'quantity_scope' => 'cart', 'match' => 'any', 'products' => [], 'categories' => [], 'groups' => [], 'shipping' => []]]]);
        $p = $this->product(['price' => '2799', 'list_price' => '3000']);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $p->id])->assertOk()
            ->assertJsonPath('cart.subtotal', '2799.00')->assertJsonPath('cart.promotions.0.name', 'Cart 10%')
            ->assertJsonPath('cart.promotions.0.discount', '279.90')->assertJsonPath('cart.total', '2519.10'));
        $description = $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.total', '2519.10')->json('cart.promotions.0.description');
        self::assertSame('', $description);
        $cart = DB::table('carts')->where('uuid', $uuid)->first();
        $greek = app(CartPage::class)->data($cart, ['locale' => 'el', 'customer' => null]);
        self::assertSame('', $greek['promotions'][0]['description']);
        DB::table('promotion_translations')->where('promotion_id', $promotion)->where('locale_id', $this->locale())->update(['description' => '10% off qualifying carts for our autumn event.']);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.promotions.0.description', '10% off qualifying carts for our autumn event.');
        $greek = app(CartPage::class)->data($cart, ['locale' => 'el', 'customer' => null]);
        self::assertSame('10% off qualifying carts for our autumn event.', $greek['promotions'][0]['description']);
        DB::table('promotion_translations')->where('promotion_id', $promotion)->update(['description' => '   ']);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.promotions.0.description', '');
        DB::table('products')->where('id', $p->id)->update(['price' => '90']);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonCount(0, 'cart.promotions')->assertJsonPath('cart.total', '90.00');
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$p->id, ['quantity' => 2])->assertOk()
            ->assertJsonPath('cart.promotions.0.discount', '18.00')->assertJsonPath('cart.total', '162.00');
        DB::table('general_settings')->update(['cart_codes_enabled' => false]);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'total', '162.00')->where(self::BLOCK.'promotions.0.id', $promotion));
        DB::table('promotions')->where('id', $promotion)->update(['ends_at' => now()->subMinute()]);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonCount(0, 'cart.promotions')->assertJsonPath('cart.total', '180.00');
        DB::table('promotions')->where('id', $promotion)->update(['ends_at' => null, 'status' => 'disabled']);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonCount(0, 'cart.promotions');
        self::assertSame(0, DB::table('order_promotions')->count());
        self::assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_product_promotions_compete_with_quantity_sales_instead_of_discounting_them_twice(): void
    {
        $this->promotion(['scope' => 'products']);
        $p = $this->product(['price' => '100']);
        DB::table('product_sales')->insert(['product_id' => $p->id, 'kind' => 'percentage', 'amount' => '30', 'minimum_quantity' => 2, 'priority' => 1, 'is_enabled' => true]);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $p->id, 'quantity' => 2])->assertOk()
            ->assertJsonPath('cart.lines.0.price', '70.00')->assertJsonPath('cart.subtotal', '140.00')->assertJsonPath('cart.total', '140.00')->assertJsonCount(0, 'cart.promotions'));
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$p->id, ['quantity' => 1])->assertOk()
            ->assertJsonPath('cart.subtotal', '100.00')->assertJsonPath('cart.promotions.0.discount', '10.00')->assertJsonPath('cart.total', '90.00');
        DB::table('products')->where('id', $p->id)->update(['quantity' => 0]);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonCount(0, 'cart.promotions')->assertJsonPath('cart.lines.0.total', null)->assertJsonPath('cart.total', '0.00');
        self::assertSame(0, DB::table('product_sale_allocations')->count());
    }

    public function test_guests_add_products_and_the_cart_follows_them_by_cookie(): void
    {
        $speaker = $this->product(['price' => '19.90']);
        $first = $this->postJson('/cart/items', ['product_id' => $speaker->id])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('quantity', 1)
            ->assertJsonPath('cart.lines.0.id', (string) $speaker->id)->assertJsonPath('cart.subtotal', '19.90');
        $uuid = $this->cookie($first);
        self::assertTrue(Str::isUuid((string) $uuid));
        self::assertSame(1, DB::table('carts')->whereNull('customer_id')->count());

        // The same browser keeps its cart: adding again grows the line; the page and the header show it.
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $speaker->id, 'quantity' => 2])->assertOk()->assertJsonPath('count', 3)->assertJsonPath('cart.subtotal', '59.70');
        self::assertSame(1, DB::table('carts')->count());
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertOk()->assertJsonPath('count', 3)->assertJsonPath('cart.lines.0.quantity', 3);
        $this->withCookie('cart', $uuid)->get('/cart')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Cart')
            ->where(self::BLOCK.'id', $uuid)->where(self::BLOCK.'count', 3)->where(self::BLOCK.'lines.0.quantity', 3)->where(self::BLOCK.'lines.0.total', '59.70')
            ->where('storefront.cart_count', 3));
        // Another browser has its own (empty) cart.
        $this->withCookie('cart', '')->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'id', null)->has(self::BLOCK.'lines', 0)->where('storefront.cart_count', 0));

        // Quantities change exactly; lines are removed.
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$speaker->id, ['quantity' => 5])->assertOk()->assertJsonPath('count', 5);
        $this->withCookie('cart', $uuid)->deleteJson('/cart/items/'.$speaker->id)->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'cart.lines');

        // Removing everything at once empties only this cart.
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $speaker->id, 'quantity' => 2])->assertOk();
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $this->product()->id])->assertOk()->assertJsonPath('count', 3);
        $other = $this->cookie($this->withCookie('cart', '')->postJson('/cart/items', ['product_id' => $speaker->id]));
        $this->withCookie('cart', $uuid)->deleteJson('/cart/items')->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'cart.lines');
        $this->withCookie('cart', $other)->getJson('/cart/items')->assertJsonPath('count', 1);
    }

    public function test_quantities_follow_the_products_rules_on_the_server(): void
    {
        // A card sends 1: a product sold from 3 in steps of 3 gets its smallest allowed quantity.
        $boxed = $this->product(['min_qty' => 3, 'qty_step' => 3, 'max_qty' => 9]);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $boxed->id])->assertOk()->assertJsonPath('quantity', 3));
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $boxed->id])->assertOk()->assertJsonPath('quantity', 6);
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$boxed->id, ['quantity' => 7])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$boxed->id, ['quantity' => 12])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$boxed->id, ['quantity' => 9])->assertOk()->assertJsonPath('count', 9);

        // Stock limits what can be added unless the product takes backorders.
        $scarce = $this->product(['quantity' => 2]);
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $scarce->id, 'quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $scarce->id, 'quantity' => 2])->assertOk();
        $backorder = $this->product(['quantity' => 0, 'out_of_stock_actions' => 'B']);
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $backorder->id, 'quantity' => 4])->assertOk();

        // When stock drops below a saved quantity, the line says why and leaves the total.
        DB::table('products')->where('id', $scarce->id)->update(['quantity' => 1]);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'lines.1.id', (string) $scarce->id)
            ->where(self::BLOCK.'lines.1.total', null)->where(self::BLOCK.'lines.1.problem', __('storefront.cart_stock', ['count' => 1]))->where(self::BLOCK.'lines.1.rules.max', 1)
            ->where(self::BLOCK.'subtotal', '130.00'));
    }

    public function test_only_products_the_visitor_may_buy_go_in_the_cart(): void
    {
        $this->postJson('/cart/items', ['product_id' => $this->product(['status' => 'disabled'])->id])->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->postJson('/cart/items', ['product_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->postJson('/cart/items', ['product_id' => $this->product(['available_from' => now()->addMonth()->toDateString()])->id])->assertUnprocessable();
        self::assertSame(0, DB::table('cart_items')->count());
        // Hidden prices offer no purchase.
        $visible = $this->product();
        DB::table('general_settings')->update(['hide_product_prices' => true]);
        $this->postJson('/cart/items', ['product_id' => $visible->id])->assertUnprocessable()->assertJsonValidationErrors('product');
        DB::table('general_settings')->update(['hide_product_prices' => false]);
        // A product that becomes invisible leaves the shown cart and the count, and comes back with it.
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $visible->id])->assertOk());
        DB::table('products')->where('id', $visible->id)->update(['status' => 'disabled']);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'lines', 0)->where('storefront.cart_count', 0));
        DB::table('products')->where('id', $visible->id)->update(['status' => 'active']);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'lines', 1));
    }

    public function test_signing_in_moves_the_guest_cart_into_the_customers_cart(): void
    {
        [$user, $customer] = $this->customer();
        [$a, $b] = [$this->product(), $this->product()];
        // A guest cart becomes the customer's when they have none.
        $guest = $this->cookie($this->postJson('/cart/items', ['product_id' => $a->id, 'quantity' => 2]));
        $response = $this->actingAs($user)->withCookie('cart', $guest)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'count', 2)->where(self::BLOCK.'id', $guest));
        self::assertSame((string) $customer, (string) DB::table('carts')->where('uuid', $guest)->value('customer_id'));
        self::assertSame('', (string) $this->cookie($response));

        // Later another guest cart merges into it: quantities add up and the guest cart is gone.
        auth()->logout();
        $this->flushSession();
        $second = $this->cookie($this->withCookie('cart', '')->postJson('/cart/items', ['product_id' => $a->id, 'quantity' => 1]));
        $this->withCookie('cart', $second)->postJson('/cart/items', ['product_id' => $b->id]);
        self::assertNotSame($guest, $second);
        $this->actingAs($user)->withCookie('cart', $second)->postJson('/cart/items', ['product_id' => $b->id])->assertOk()->assertJsonPath('count', 5);
        self::assertSame(1, DB::table('carts')->count());
        self::assertSame([3, 2], DB::table('cart_items')->orderBy('product_id')->pluck('quantity')->map(fn ($q) => (int) $q)->all());

        // A cookie naming a customer's cart is never a guest's way in.
        auth()->logout();
        $this->flushSession();
        $this->withCookie('cart', $guest)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'id', null)->has(self::BLOCK.'lines', 0));
        $this->withCookie('cart', $guest)->patchJson('/cart/items/'.$a->id, ['quantity' => 1])->assertNotFound();
    }

    public function test_a_coupon_and_a_gift_card_lower_what_is_left_to_pay_without_being_redeemed(): void
    {
        $speaker = $this->product(['price' => '20']);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $speaker->id, 'quantity' => 2])->assertOk());
        $actor = (string) $this->staff(super: true)->id;
        app(Coupons::class)->save($actor, null, ['name' => 'Summer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $card = app(GiftCards::class)->save($actor, null, ['recipient_name' => 'Ria', 'sender_name' => 'Shop', 'recipient_email' => 'ria@example.test', 'message' => 'Enjoy!', 'amount' => '30.00', 'locale' => 'en', 'status' => 'active', 'notify' => false]);
        $number = (string) DB::table('gift_cards')->where('id', $card)->value('number');

        // A code that is neither a coupon nor a gift card is refused and nothing is remembered.
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'NOPE'])->assertJsonValidationErrors('code');
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => ' SUMMER '])->assertOk()
            ->assertJsonPath('cart.subtotal', '40.00')->assertJsonPath('cart.coupon.discount', '4.00')->assertJsonPath('cart.total', '36.00');
        // A gift card pays what its balance covers, is found whatever the case it is typed in, and shows masked.
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => strtolower($number)])->assertOk()
            ->assertJsonPath('cart.gift_card.amount', '30.00')->assertJsonPath('cart.gift_card.code', GiftCardRules::masked($number))->assertJsonPath('cart.total', '6.00');
        $this->assertDatabaseHas('gift_cards', ['id' => $card, 'balance' => 30]);
        self::assertSame(0, DB::table('coupon_redemptions')->count());

        $promotion = $this->promotion();
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.coupon.discount', '4.00')
            ->assertJsonPath('cart.promotions.0.discount', '3.60')->assertJsonPath('cart.gift_card.amount', '30.00')->assertJsonPath('cart.total', '2.40');
        self::assertSame(0, DB::table('order_promotions')->count());
        $this->assertDatabaseHas('gift_cards', ['id' => $card, 'balance' => 30]);
        DB::table('promotions')->where('id', $promotion)->update(['status' => 'disabled']);

        // A coupon that stops applying stays in the cart and says why; the total no longer counts it.
        DB::table('coupons')->update(['status' => 'disabled']);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'coupon.discount', null)
            ->where(self::BLOCK.'coupon.problem', __('coupons.unavailable'))->where(self::BLOCK.'total', '10.00'));

        // With *Allow only one coupon per order* on (the default) a second coupon is refused; off, it replaces the first.
        app(Coupons::class)->save($actor, null, ['name' => 'Winter', 'code' => 'WINTER', 'type' => 'fixed', 'amount' => '5', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'WINTER'])->assertJsonValidationErrors(['code' => __('storefront.cart_one_coupon')]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'SUMMER'])->assertOk();
        DB::table('general_settings')->update(['single_coupon' => false]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'WINTER'])->assertOk()->assertJsonPath('cart.coupon.code', 'WINTER')->assertJsonPath('cart.coupon.discount', '5.00');

        // Switched off, the field goes, entered codes are ignored (but kept) and codes cannot be entered.
        DB::table('general_settings')->update(['cart_codes_enabled' => false]);
        $this->withCookie('cart', $uuid)->get('/cart')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'codes', false)->where(self::BLOCK.'coupon', null)->where(self::BLOCK.'gift_card', null)->where(self::BLOCK.'total', '40.00'));
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'SUMMER'])->assertNotFound();
        DB::table('general_settings')->update(['cart_codes_enabled' => true]);

        $this->withCookie('cart', $uuid)->deleteJson('/cart/codes/coupon')->assertOk()->assertJsonPath('cart.coupon', null);
        $this->withCookie('cart', $uuid)->deleteJson('/cart/codes/gift_card')->assertOk()->assertJsonPath('cart.gift_card', null)->assertJsonPath('cart.total', '40.00');
    }

    public function test_prices_without_tax_follow_the_store_setting(): void
    {
        $actor = (string) $this->staff(super: true)->id;
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Everywhere'], 'active', true));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($area, 'percent', '24')]));
        $taxed = $this->product(['price' => '100']);
        $untaxed = $this->product(['price' => '10']);
        DB::table('product_taxes')->insert(['product_id' => $taxed->id, 'tax_id' => $tax]);
        DB::table('general_settings')->update(['display_catalog_prices_with_tax' => false, 'show_prices_without_tax' => false]);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $taxed->id, 'quantity' => 2])->assertOk());
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $untaxed->id])->assertOk();

        // Off: only the prices with tax.
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.subtotal', '258.00')
            ->assertJsonPath('cart.lines.0.total_without_tax', null)->assertJsonPath('cart.subtotal_without_tax', null);
        // On: the taxed line and the products total also show without tax; the untaxed line has nothing to add.
        DB::table('general_settings')->update(['show_prices_without_tax' => true]);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.lines.0.total', '248.00')->assertJsonPath('cart.lines.0.total_without_tax', '200.00')
            ->assertJsonPath('cart.lines.1.total_without_tax', null)->assertJsonPath('cart.subtotal_without_tax', '210.00')->assertJsonPath('cart.total_without_tax', '210.00');

        $this->promotion();
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertJsonPath('cart.subtotal', '258.00')
            ->assertJsonPath('cart.promotions.0.discount', '25.80')->assertJsonPath('cart.total', '232.20')->assertJsonPath('cart.total_without_tax', '189.00');
        app(Coupons::class)->save($actor, null, ['name' => 'Summer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $this->withCookie('cart', $uuid)->postJson('/cart/codes', ['code' => 'SUMMER'])->assertOk()->assertJsonPath('cart.coupon.discount', '25.80')
            ->assertJsonPath('cart.promotions.0.discount', '23.22')->assertJsonPath('cart.total', '208.98')->assertJsonPath('cart.total_without_tax', '170.10');
    }

    public function test_tax_rounding_and_zero_totals_reconcile_with_the_order_quote(): void
    {
        $actor = (string) $this->staff(super: true)->id;
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Everywhere'], 'active', true));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($area, 'percent', '24')]));
        $a = $this->product(['price' => '0.05']);
        $b = $this->product(['price' => '0.07']);
        foreach ([$a, $b] as $p) {
            DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);
        }
        $promotion = $this->promotion();
        $this->promotion(['amount' => '0.01', 'benefit' => 'fixed', 'priority' => '200']);
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $a->id, 'quantity' => 3])->assertOk());
        $this->withCookie('cart', $uuid)->postJson('/cart/items', ['product_id' => $b->id])->assertOk();
        foreach (['unit_price', 'subtotal'] as $method) {
            DB::table('general_settings')->update(['tax_calculation_method' => $method]);
            $cart = $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertOk()->json('cart');
            $quote = app(OrderPricing::class)->quote([['product_id' => (string) $a->id, 'quantity' => 3], ['product_id' => (string) $b->id, 'quantity' => 1]], [], ['billing' => null, 'shipping' => null], false, 'en', 2);
            self::assertSame($quote['total'], $cart['total']);
            $discount = array_reduce($cart['promotions'], fn (string $sum, array $p): string => Decimal::add($sum, $p['discount']), '0');
            self::assertSame($cart['total'], Decimal::round(Decimal::subtract($cart['subtotal'], $discount), 2));
        }
        DB::table('promotions')->update(['status' => 'disabled']);
        DB::table('promotions')->where('id', $promotion)->update(['status' => 'active', 'amount' => '100']);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertOk()->assertJsonPath('cart.total', '0.00');
    }

    public function test_switching_the_cart_off_closes_it_and_tells_the_theme(): void
    {
        $speaker = $this->product();
        $uuid = $this->cookie($this->postJson('/cart/items', ['product_id' => $speaker->id])->assertOk());
        DB::table('general_settings')->update(['cart_enabled' => false]);
        $this->get('/cart')->assertNotFound();
        $this->get('/checkout')->assertNotFound();
        $this->get('/checkout/payment')->assertNotFound();
        $this->getJson('/cart/items')->assertNotFound();
        $this->postJson('/cart/items', ['product_id' => $speaker->id])->assertNotFound();
        $this->withCookie('cart', $uuid)->patchJson('/cart/items/'.$speaker->id, ['quantity' => 2])->assertNotFound();
        $this->deleteJson('/cart/items/'.$speaker->id)->assertNotFound();
        $this->postJson('/cart/codes', ['code' => 'IGNORED'])->assertNotFound();
        $this->deleteJson('/cart/codes/coupon')->assertNotFound();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefront.cart_enabled', false));
        DB::table('general_settings')->update(['storefront_open' => false]);
        $this->get('/cart')->assertNotFound();
        $this->get('/checkout')->assertNotFound();
        DB::table('general_settings')->update(['cart_enabled' => true, 'storefront_open' => true]);
        $this->withCookie('cart', $uuid)->getJson('/cart/items')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('cart.lines.0.id', (string) $speaker->id);
    }

    public function test_the_cart_page_is_private_and_has_its_own_layout(): void
    {
        self::assertTrue(DB::table('storefront_layouts')->where('page_type', 'cart')->exists());
        $response = $this->get('/cart')->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_staff_with_the_capability_see_every_cart(): void
    {
        [$user] = $this->customer();
        $p = $this->product(['price' => '12.50']);
        $hidden = $this->product();
        $guest = $this->cookie($this->postJson('/cart/items', ['product_id' => $p->id, 'quantity' => 2]));
        $this->actingAs($user)->postJson('/cart/items', ['product_id' => $p->id]);
        $this->postJson('/cart/items', ['product_id' => $hidden->id]);
        DB::table('products')->where('id', $hidden->id)->update(['status' => 'disabled']);
        auth()->logout();
        $this->flushSession();

        session()->forget('password_hash_web');
        $staff = $this->staff(super: true);
        $this->actingAs($staff)->get('/admin/carts')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Carts/Index')->where('carts.total', 2));
        $this->get('/admin/carts?type=guest')->assertInertia(fn (Assert $page) => $page->where('carts.total', 1)->where('carts.data.0.uuid', $guest)->where('carts.data.0.customer', null)->where('carts.data.0.units', 2));
        $this->get('/admin/carts?type=customer&search=ria@')->assertInertia(fn (Assert $page) => $page->where('carts.total', 1)->where('carts.data.0.customer.name', 'Ria Stone')->where('carts.data.0.lines', 2));
        $customerCart = (string) DB::table('carts')->whereNotNull('customer_id')->value('id');
        $this->get('/admin/carts/'.$customerCart)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Carts/Show')
            ->has('cart.items', 1)->where('cart.items.0.id', (string) $p->id)->where('cart.items.0.quantity', 1)->has('cart.hidden', 1)->where('cart.hidden.0.id', (string) $hidden->id));
        $this->get('/admin/carts/999999')->assertNotFound();

        // Without the capability the screens are closed.
        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'products.view']));
        $this->get('/admin/carts')->assertForbidden();
    }
}
