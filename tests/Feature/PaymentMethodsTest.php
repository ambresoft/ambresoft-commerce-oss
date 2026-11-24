<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Payment\Domain\Service\PaymentRules;
use App\Payment\Infrastructure\OrderPayments;
use App\Payment\Infrastructure\PaymentMethodRestrictions;
use App\Shared\Infrastructure\Modules\AddonManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class PaymentMethodsTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'orders@store.example']);
        // Stored rows of registered methods are created when the list is first opened.
        $this->get('/admin/payment-methods')->assertOk();
    }

    /** @param array<string, mixed> $changes */
    private function saveCod(array $changes = []): TestResponse
    {
        $version = (int) DB::table('payment_methods')->where('code', 'cod')->value('lock_version');

        return $this->put('/admin/payment-methods/cod', array_replace([
            'version' => $version, 'is_active' => true, 'paid_status_id' => '',
            'translations' => ['en' => ['name' => 'Pay at the door', 'description' => 'Cash or card to the courier.'], 'el' => ['name' => 'Πληρωμή στην πόρτα', 'description' => '']],
        ], $changes));
    }

    /** A real 1×1 PNG (the test image needs no GD). */
    private function png(string $name = 'image.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
    }

    public function test_cash_on_delivery_is_a_core_method_and_never_an_addon(): void
    {
        self::assertSame(PaymentMethodRegistry::CORE, app(PaymentMethodRegistry::class)->owner('cod'));
        $this->get('/admin/payment-methods')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/PaymentMethods/Index')
            ->where('methods.0.code', 'cod')->where('methods.0.owner', 'core')->where('methods.0.owner_title', null)
            ->where('methods.0.kind', 'offline')->where('methods.0.active', true)->where('methods.0.issues', [])->where('methods.0.name', 'Cash on delivery'));
        // Cash on delivery has no manifest or lifecycle: the add-on list never contains it.
        $this->get('/admin/addons')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Addons/Index')
            ->where('addons', fn ($addons) => collect($addons)->pluck('name')->doesntContain('cod') && collect($addons)->every(fn ($a) => ! in_array('cod', $a['contributes']['payment-methods'] ?? [], true))));
        // The stored row belongs to core and default names exist in every language.
        self::assertSame('core', DB::table('payment_methods')->where('code', 'cod')->value('owner'));
        self::assertSame('Αντικαταβολή', DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'el')->where('t.method_id', $this->methodId('cod'))->value('t.name'));
    }

    public function test_cod_names_status_after_payment_order_and_enabled_state_are_configurable(): void
    {
        $this->saveCod(['paid_status_id' => $this->orderStatus('processing')])->assertSessionHasNoErrors()->assertRedirect('/admin/payment-methods/cod/edit');
        $this->get('/admin/payment-methods/cod/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/PaymentMethods/Form')
            ->where('method.translations.en.name', 'Pay at the door')->where('method.translations.el.name', 'Πληρωμή στην πόρτα')
            ->where('method.paid_status_id', $this->orderStatus('processing'))->where('method.fields', [])->where('method.kind', 'offline'));
        $this->saveCod(['translations' => ['en' => ['name' => '', 'description' => '']]])->assertSessionHasErrors('translations.en.name');
        $this->saveCod(['paid_status_id' => '999999'])->assertSessionHasErrors('paid_status_id');
        // A stale version is refused instead of overwriting someone else's change.
        $this->put('/admin/payment-methods/cod', ['version' => 1, 'translations' => ['en' => ['name' => 'X']]])->assertSessionHasErrors('version');

        // Display order: a second registered method (the bundled Stripe Checkout add-on) can be moved above it.
        $addons = app(AddonManager::class);
        $addons->installBundled(null, 'ambresoft/stripe-checkout');
        $addons->enable(null, 'ambresoft/stripe-checkout');
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->where('methods.0.code', 'cod')->where('methods.1.code', 'bank_deposit')->where('methods.2.code', 'stripe_checkout'));
        $this->post('/admin/payment-methods/order', ['codes' => ['stripe_checkout', 'cod', 'bank_deposit']])->assertRedirect('/admin/payment-methods');
        // A list that is not exactly the listed methods is refused.
        $this->post('/admin/payment-methods/order', ['codes' => ['stripe_checkout', 'cod']])->assertSessionHasErrors('codes');
        $this->post('/admin/payment-methods/order', ['codes' => ['stripe_checkout', 'cod', 'cod']])->assertSessionHasErrors('codes');
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->where('methods.0.code', 'stripe_checkout')->where('methods.1.code', 'cod')->where('methods.2.code', 'bank_deposit'));

        // Disabling affects new orders only: the existing order keeps its method and stays readable.
        $product = $this->orderProduct('12.50');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('cod')]));
        $version = (int) DB::table('payment_methods')->where('code', 'cod')->value('lock_version');
        $this->patch('/admin/payment-methods/cod/active', ['version' => $version, 'active' => false])->assertSessionHasNoErrors();
        $this->get('/admin/orders/create')->assertInertia(fn (Assert $page) => $page->where('paymentMethods', []));
        try {
            app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('cod')]));
            self::fail('A disabled method was assigned.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payment_method_id', $e->errors());
        }
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment.method.name', 'Pay at the door')->where('payment.status', 'unpaid'));
        $this->get('/admin/orders/'.$order.'/edit')->assertInertia(fn (Assert $page) => $page->where('paymentMethods.0.id', $this->methodId('cod')));
    }

    public function test_cod_orders_stay_unpaid_until_staff_record_the_collected_money(): void
    {
        $this->saveCod(['paid_status_id' => $this->orderStatus('processing')])->assertSessionHasNoErrors();
        $product = $this->orderProduct('24.80', 5);
        $this->get('/admin/orders/create')->assertInertia(fn (Assert $page) => $page->where('paymentMethods.0', ['id' => $this->methodId('cod'), 'name' => 'Pay at the door']));
        $this->post('/admin/orders', $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('cod')]))->assertSessionHasNoErrors();
        $order = (string) DB::table('orders')->value('id');
        self::assertSame([$this->methodId('cod'), 'Pay at the door'], [(string) DB::table('orders')->value('payment_method_id'), DB::table('orders')->value('payment_method_name')]);
        // Placing the order with cash on delivery records no payment and does not mark it paid.
        self::assertSame(0, DB::table('order_payments')->count());
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'unpaid')->where('payment.outstanding_raw', '24.80')
            ->where('payment.can_record', true)->where('payment.link', null)->where('payment.method.kind', 'offline'));

        $this->post('/admin/orders/'.$order.'/payments', ['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '30.00'])->assertSessionHasErrors('amount');
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '10.001'])->assertSessionHasErrors('amount');
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '10.00', 'note' => 'Courier, part 1'])->assertSessionHasNoErrors();
        self::assertSame($this->orderStatus('open'), (string) DB::table('orders')->value('status_id'), 'A partial payment does not move the order');
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'partially_paid')->where('payment.outstanding_raw', '14.80'));
        $history = DB::table('order_status_history')->where('order_id', $order)->count();
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '14.80'])->assertSessionHasNoErrors();
        // Fully paid: the configured status is applied once, through the order workflow (stock and history).
        self::assertSame($this->orderStatus('processing'), (string) DB::table('orders')->value('status_id'));
        self::assertSame($history + 1, DB::table('order_status_history')->where('order_id', $order)->count());
        self::assertSame('system', DB::table('order_status_history')->where('order_id', $order)->orderByDesc('id')->value('source'));
        self::assertSame(4, (int) DB::table('products')->where('id', $product->id)->value('quantity'));
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'paid')->where('payment.can_record', false)->has('payment.records', 2)
            ->where('payment.records.0.kind', 'manual')->where('payment.records.0.recorded_by', $this->actor->name));
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '0.01'])->assertSessionHasErrors('amount');
        self::assertSame(2, DB::table('order_payments')->where('state', 'captured')->count());
        // Recorded payments fix the method.
        $version = (int) DB::table('orders')->value('lock_version');
        $this->put('/admin/orders/'.$order, [...$this->orderInput((string) $product->id, ['items' => [['item_id' => DB::table('order_items')->value('id'), 'quantity' => 1]]]), 'version' => $version, 'payment_method_id' => null])
            ->assertSessionHasErrors('payment_method_id');
    }

    public function test_bank_deposit_is_a_core_method_whose_description_carries_the_ibans(): void
    {
        self::assertSame(PaymentMethodRegistry::CORE, app(PaymentMethodRegistry::class)->owner('bank_deposit'));
        // Built in like cash on delivery, but disabled until the store's bank accounts are in its description.
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->where('methods.1.code', 'bank_deposit')->where('methods.1.owner', 'core')
            ->where('methods.1.kind', 'offline')->where('methods.1.active', false)->where('methods.1.issues', [])->where('methods.1.name', 'Bank deposit'));
        self::assertSame('Κατάθεση σε τράπεζα', DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'el')->where('t.method_id', $this->methodId('bank_deposit'))->value('t.name'));
        $this->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where('addons', fn ($addons) => collect($addons)->every(fn ($a) => ! in_array('bank_deposit', $a['contributes']['payment-methods'] ?? [], true))));

        $ibans = "Piraeus Bank: GR16 0172 0120 0050 1234 5678 901\nNational Bank of Greece: GR96 0110 1250 0000 0001 2300 695";
        $this->put('/admin/payment-methods/bank_deposit', [
            'version' => (int) DB::table('payment_methods')->where('code', 'bank_deposit')->value('lock_version'), 'is_active' => true,
            'translations' => ['en' => ['name' => 'Bank deposit', 'description' => "Use your order number as the reference.\n".$ibans], 'el' => ['name' => 'Κατάθεση σε τράπεζα', 'description' => "Αιτιολογία: ο αριθμός παραγγελίας.\n".$ibans]],
        ])->assertSessionHasNoErrors();
        $this->get('/admin/payment-methods/bank_deposit/edit')->assertInertia(fn (Assert $page) => $page->where('method.translations.en.description', "Use your order number as the reference.\n".$ibans)->where('method.fields', []));
        // Offered for new orders; like cash on delivery the order stays unpaid until staff record the deposit.
        $product = $this->orderProduct('40.00');
        $this->get('/admin/orders/create')->assertInertia(fn (Assert $page) => $page->where('paymentMethods.1', ['id' => $this->methodId('bank_deposit'), 'name' => 'Bank deposit']));
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('bank_deposit')]));
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'unpaid')->where('payment.method.name', 'Bank deposit')->where('payment.method.kind', 'offline'));
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '40.00', 'note' => 'Deposit received'])->assertSessionHasNoErrors();
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'paid'));
    }

    public function test_a_method_can_have_an_optional_image(): void
    {
        Storage::fake('local');
        $version = (int) DB::table('payment_methods')->where('code', 'cod')->value('lock_version');
        // Uploads travel as multipart POST with the update method spoofed, like the editor sends them.
        $this->post('/admin/payment-methods/cod', ['_method' => 'put', 'version' => $version, 'translations' => ['en' => ['name' => 'COD']], 'image' => UploadedFile::fake()->createWithContent('cod.txt', 'not an image')])
            ->assertSessionHasErrors('image');
        self::assertSame(0, DB::table('media_files')->count());
        self::assertSame([], Storage::disk('local')->allFiles());

        $this->post('/admin/payment-methods/cod', ['_method' => 'put', 'version' => $version, 'translations' => ['en' => ['name' => 'COD']], 'image' => $this->png('cod.png')])
            ->assertSessionHasNoErrors();
        $media = DB::table('payment_methods')->where('code', 'cod')->value('image_media_id');
        self::assertNotNull($media);
        $this->get('/admin/payment-methods/cod/edit')->assertInertia(fn (Assert $page) => $page->where('method.image.meta.width', 1)
            ->where('method.image.url', '/admin/payment-methods/cod/image?v='.$media));
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->where('methods', fn ($methods) => collect($methods)->firstWhere('code', 'cod')['image_url'] !== null));
        $this->get('/admin/payment-methods/cod/image')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Saving without a file keeps the image; removing it clears the reference.
        $this->saveCod()->assertSessionHasNoErrors();
        self::assertSame($media, DB::table('payment_methods')->where('code', 'cod')->value('image_media_id'));
        $this->saveCod(['remove_image' => true])->assertSessionHasNoErrors();
        self::assertNull(DB::table('payment_methods')->where('code', 'cod')->value('image_media_id'));
        $this->get('/admin/payment-methods/cod/image')->assertNotFound();
    }

    public function test_payment_capabilities_are_enforced_on_the_server(): void
    {
        $product = $this->orderProduct('10.00');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('cod')]));
        $viewer = $this->staff(['admin.access', 'orders.view']);
        $this->flushSession();
        $this->actingAs($viewer);
        $this->get('/admin/payment-methods')->assertForbidden();
        $this->put('/admin/payment-methods/cod', ['version' => 1])->assertForbidden();
        $this->get('/admin/addons')->assertForbidden();
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '10.00'])->assertForbidden();
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment.can_record', false)->where('payment.link', null));
        self::assertSame(0, DB::table('order_payments')->count());

        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'orders.view', 'orders.payments']));
        $this->post('/admin/orders/'.$order.'/payments', ['amount' => '10.00'])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'payment-methods.manage']));
        $this->get('/admin/payment-methods')->assertOk();
        $this->get('/admin/addons')->assertForbidden();
        // Nobody is granted the new capabilities by synchronization.
        self::assertFalse(DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
            ->where('r.is_super_admin', false)->whereIn('p.name', ['addons.manage', 'addons.upload'])->exists());
    }

    public function test_payment_state_rules_are_independent_of_order_status(): void
    {
        self::assertSame('unpaid', PaymentRules::orderStatus([['state' => 'failed', 'amount' => '10'], ['state' => 'created', 'amount' => '10']], '10'));
        self::assertSame('pending', PaymentRules::orderStatus([['state' => 'pending', 'amount' => '10']], '10'));
        self::assertSame('partially_paid', PaymentRules::orderStatus([['state' => 'captured', 'amount' => '4.5']], '10'));
        self::assertSame('paid', PaymentRules::orderStatus([['state' => 'captured', 'amount' => '4.5'], ['state' => 'captured', 'amount' => '5.5']], '10'));
        self::assertSame('0', PaymentRules::outstanding([['state' => 'captured', 'amount' => '12']], '10'));
        self::assertTrue(PaymentRules::canMove('processing', 'captured'));
        self::assertFalse(PaymentRules::canMove('captured', 'failed'));
        self::assertFalse(PaymentRules::canMove('cancelled', 'captured'));
        self::assertTrue(app(OrderPayments::class) instanceof OrderPayments);
    }

    public function test_restrictions_exclude_product_types_and_categories_with_their_subcategories(): void
    {
        $category = fn (?int $parent, int $position): int => DB::table('categories')->insertGetId(['parent_id' => $parent, 'position' => $position, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $adult = $category(null, 1);
        $wines = $category($adult, 1);
        $books = $category(null, 2);
        $product = fn (string $type, int $category): string => (string) DB::table('products')->insertGetId(['sku' => 'R-'.$type.'-'.$category, 'type' => $type, 'price' => '10', 'quantity' => 5, 'category_id' => $category, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $wine = $product('physical', $wines);
        $ebook = $product('digital', $books);
        $restrictions = app(PaymentMethodRestrictions::class);
        self::assertNull($restrictions->forProducts([$wine, $ebook])['cod']);

        // Both types at once would leave the method unusable.
        $this->saveCod(['exclude_physical' => true, 'exclude_digital' => true])->assertSessionHasErrors('exclude_digital');
        $this->saveCod(['exclude_digital' => true, 'excluded_category_ids' => [$adult]])->assertSessionHasNoErrors();
        $this->get('/admin/payment-methods/cod/edit')->assertInertia(fn (Assert $page) => $page->where('method.exclude_digital', true)->where('method.exclude_physical', false)
            ->where('method.excluded_category_ids', [(string) $adult])->where('categoryOptions', fn ($options) => collect($options)->pluck('id')->contains((string) $wines)));
        // A digital product blocks the method; so does a product in a subcategory of an excluded category.
        self::assertSame(['product' => $ebook, 'reason' => 'digital'], $restrictions->forProducts([$ebook])['cod']);
        self::assertSame(['product' => $wine, 'reason' => 'category'], $restrictions->forProducts([$wine])['cod']);
        // An extra category under an excluded one blocks too.
        $book = $product('physical', $books);
        self::assertNull($restrictions->forProducts([$book])['cod']);
        DB::table('product_categories')->insert(['product_id' => $book, 'category_id' => $wines, 'position' => 1]);
        self::assertSame(['product' => $book, 'reason' => 'category'], $restrictions->forProducts([$book])['cod']);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'payment_method_excluded_category')->exists());

        // Fields left out keep their values; an empty list sent by the editor clears the categories.
        $this->saveCod()->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('payment_method_excluded_categories')->count());
        $this->saveCod(['excluded_categories_submitted' => '1'])->assertSessionHasNoErrors();
        self::assertSame(0, DB::table('payment_method_excluded_categories')->count());
        // Deleting a category removes it from every method's exclusions.
        $this->saveCod(['excluded_category_ids' => [$books]])->assertSessionHasNoErrors();
        DB::table('products')->where('category_id', $books)->delete();
        DB::table('categories')->where('id', $books)->delete();
        self::assertSame(0, DB::table('payment_method_excluded_categories')->count());
    }
}
