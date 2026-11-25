<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Infrastructure\PaymentSettings;
use App\Shared\Infrastructure\Modules\AddonManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\TestCase;

/** The bundled Stripe Checkout add-on against a faked Stripe API (no network, no real credentials, no real money). */
final class StripeCheckoutTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private const ADDON = 'ambresoft/stripe-checkout';

    private const METHOD = 'stripe_checkout';

    private const KEY = 'sk_test_51AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    private const WHSEC = 'whsec_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    /** A well-formed but made-up key, built at runtime so no live-key-shaped literal sits in the source. */
    private static function fake(string $prefix, int $length): string
    {
        return $prefix.substr('51AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 0, $length);
    }

    private const ENDPOINT = '/payments/webhooks/stripe_checkout';

    private UserRecord $actor;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'orders@store.example']);
        DB::table('company_settings')->where('id', 1)->update(['name' => 'Test Store']);
        Http::preventStrayRequests();
        ApiRequestor::setHttpClient($this->stripe = new FakeStripeHttpClient);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    private function enableAddon(): void
    {
        $addons = app(AddonManager::class);
        $addons->installBundled(null, self::ADDON);
        $addons->enable(null, self::ADDON);
        $this->get('/admin/payment-methods')->assertOk();
    }

    /** @param array<string, mixed> $changes */
    private function configure(array $changes = []): TestResponse
    {
        return $this->put('/admin/payment-methods/'.self::METHOD, array_replace_recursive([
            'version' => (int) DB::table('payment_methods')->where('code', self::METHOD)->value('lock_version'), 'is_active' => true,
            'paid_status_id' => $this->orderStatus('processing'),
            'translations' => ['en' => ['name' => 'Card payment', 'description' => 'Pay with Stripe'], 'el' => ['name' => 'Κάρτα', 'description' => '']],
            'settings' => ['mode' => 'test', 'secret_key' => self::KEY, 'webhook_secret' => self::WHSEC],
        ], $changes));
    }

    private function ready(): void
    {
        $this->enableAddon();
        $this->configure()->assertSessionHasNoErrors();
    }

    /** Places a Stripe order through the admin and returns [order ID, signed customer link]. */
    private function stripeOrder(string $price = '24.80'): array
    {
        $this->actingAs($this->actor);
        $product = $this->orderProduct($price);
        $this->post('/admin/orders', $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId(self::METHOD)]))->assertSessionHasNoErrors();
        $order = (string) DB::table('orders')->orderByDesc('id')->value('id');
        $link = null;
        $this->get('/admin/orders/'.$order)->assertInertia(function (Assert $page) use (&$link) {
            $page->where('payment.method.kind', 'redirect')->where('payment.method.online', true)->where('payment.status', 'unpaid');
            $link = $page->toArray()['props']['payment']['link'];
        });
        self::assertIsString($link);

        return [$order, $link];
    }

    /** The customer (a guest with only the signed link) continues to Stripe; returns the response of the "Pay" button. */
    private function pay(string $link): TestResponse
    {
        $this->flushSession();
        auth()->guard('web')->logout();

        return $this->post($link);
    }

    private function attempt(): object
    {
        return DB::table('order_payments')->orderByDesc('id')->first();
    }

    /** The URL Stripe sends the customer back to after paying, with its session placeholder filled in. */
    private function successUrl(string $sessionId): string
    {
        return str_replace('{CHECKOUT_SESSION_ID}', $sessionId, (string) $this->stripe->lastCreate()['params']['success_url']);
    }

    private function paymentStatus(string $order): string
    {
        $status = null;
        $this->actingAs($this->actor)->get('/admin/orders/'.$order)->assertInertia(function (Assert $page) use (&$status) {
            $status = $page->toArray()['props']['payment']['status'];
        });
        $this->flushSession();
        auth()->guard('web')->logout();

        return $status;
    }

    /** @param array<string, mixed> $overrides */
    private function sessionData(string $id, array $overrides = []): array
    {
        return [...$this->stripe->sessions[$id], ...$overrides];
    }

    /** @param array<string, mixed> $session */
    private function event(string $type, array $session, ?string $id = null, bool $live = false): array
    {
        return ['id' => $id ?? 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'livemode' => $live, 'created' => time(), 'data' => ['object' => $session]];
    }

    private function signature(string $payload, string $secret = self::WHSEC, ?int $time = null): string
    {
        $time ??= time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    }

    /** Delivers a signed webhook the way Stripe does: raw JSON body, signature header, no cookies, no CSRF token. */
    private function webhook(array $event, ?string $signature = null, ?string $body = null): TestResponse
    {
        $payload = $body ?? json_encode($event, JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== '') {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature ?? $this->signature($payload);
        }
        $this->flushSession();
        auth()->guard('web')->logout();

        return $this->call('POST', self::ENDPOINT, [], [], [], $server, $payload);
    }

    /** Starts a payment and returns [order, link, attempt row, Stripe session ID]. */
    private function started(string $price = '24.80'): array
    {
        [$order, $link] = $this->stripeOrder($price);
        $this->pay($link)->assertRedirect();
        $attempt = $this->attempt();

        return [$order, $link, $attempt, (string) $attempt->provider_reference];
    }

    private function eventRows(): int
    {
        return DB::table('payment_webhook_events')->count();
    }

    public function test_the_addon_is_discovered_bundled_and_its_payment_method_is_managed_separately(): void
    {
        $row = collect(app(AddonManager::class)->overview())->firstWhere('name', self::ADDON);
        self::assertSame(['bundled', 'not_installed', 'Stripe Checkout'], [$row['source'], $row['status'], $row['title']]);
        self::assertStringContainsString('/icon?v=', (string) $row['icon_url']);
        $this->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where('addons', fn ($addons) => collect($addons)->contains(fn ($a) => $a['name'] === self::ADDON && $a['source'] === 'bundled')));

        $this->enableAddon();
        // Installed and enabled, but the customer-facing method stays off until it is configured and switched on.
        $method = DB::table('payment_methods')->where('code', self::METHOD)->first();
        self::assertSame([self::ADDON, false], [$method->owner, (bool) $method->is_active]);
        $names = fn () => $this->get('/admin/orders/create')->original->getData()['page']['props']['paymentMethods'];
        self::assertNotContains('Card payment (Stripe)', array_column($names(), 'name'));
        $this->get('/admin/payment-methods/'.self::METHOD.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('method.issues.0', __('payments.issue_required', ['field' => __('ambresoft-stripe::stripe.secret_key')])));

        $this->configure()->assertSessionHasNoErrors();
        self::assertContains('Card payment', array_column($names(), 'name'));
        // Switching only the payment method off hides it from customers but keeps its configuration and history.
        $this->configure(['is_active' => false])->assertSessionHasNoErrors();
        self::assertNotContains('Card payment', array_column($names(), 'name'));
        self::assertNotNull(DB::table('payment_methods')->where('code', self::METHOD)->value('secrets'));
        self::assertContains('Cash on delivery', array_column($names(), 'name'));
        // Disabling the whole add-on keeps core ordering and the other methods working.
        $this->configure(['is_active' => true])->assertSessionHasNoErrors();
        app(AddonManager::class)->disable((string) $this->actor->id, self::ADDON);
        self::assertNotContains('Card payment', array_column($names(), 'name'));
        self::assertContains('Cash on delivery', array_column($names(), 'name'));
        $this->get('/admin/payment-methods')->assertOk();
        $this->get('/admin/addons')->assertOk();
    }

    public function test_credentials_are_validated_verified_encrypted_and_never_exposed(): void
    {
        $this->enableAddon();
        $this->configure(['settings' => ['secret_key' => 'not-a-key']])->assertSessionHasErrors('settings.secret_key');
        $this->configure(['settings' => ['secret_key' => self::fake('sk_live_', 24)]])->assertSessionHasErrors('settings.secret_key');
        $this->configure(['settings' => ['mode' => 'live']])->assertSessionHasErrors('settings.secret_key');
        $this->configure(['settings' => ['webhook_secret' => 'nope']])->assertSessionHasErrors('settings.webhook_secret');
        self::assertSame(0, $this->stripe->count('get', '/v1/checkout/sessions'), 'Invalid input never reaches Stripe');

        // Stripe rejects the key: stored, but the method stays unavailable and cannot be enabled.
        $this->stripe->failure = 'auth';
        $this->configure()->assertSessionHasErrors('is_active')->assertSessionHas('status', fn (string $s) => str_contains($s, __('payments.verify_rejected')));
        $row = DB::table('payment_methods')->where('code', self::METHOD)->first();
        self::assertSame([false, null], [(bool) $row->is_active, $row->verified_fingerprint]);

        $this->stripe->failure = null;
        $this->configure(['settings' => ['secret_key' => '']])->assertSessionHasNoErrors();
        $row = DB::table('payment_methods')->where('code', self::METHOD)->first();
        self::assertTrue((bool) $row->is_active);
        self::assertNotNull($row->verified_fingerprint);
        foreach ([self::KEY, self::WHSEC] as $secret) {
            self::assertStringNotContainsString($secret, (string) $row->secrets, 'Encrypted at rest');
            self::assertStringNotContainsString($secret, (string) $row->settings);
            self::assertStringNotContainsString($secret, (string) DB::table('audit_logs')->pluck('changes')->implode(' '));
        }
        // The credentials are checked once, when saved, with the key as a bearer token; never on page loads.
        $verifications = $this->stripe->count('get', '/v1/checkout/sessions');
        $this->get('/admin/payment-methods/'.self::METHOD.'/edit')->assertOk()->assertDontSee(self::KEY)->assertDontSee(self::WHSEC)
            ->assertInertia(fn (Assert $page) => $page->where('method.fields.1.key', 'secret_key')->where('method.fields.1.value', '')->where('method.fields.1.set', true)
                ->where('method.fields.2.key', 'webhook_secret')->where('method.fields.2.set', true)->where('method.issues', []));
        $this->get('/admin/payment-methods')->assertOk()->assertDontSee(self::KEY);
        self::assertSame($verifications, $this->stripe->count('get', '/v1/checkout/sessions'));
        self::assertSame('Bearer '.self::KEY, collect($this->stripe->requests)->first(fn ($r) => $r['path'] === '/v1/checkout/sessions')['headers']['authorization']);

        // Changing the mode (a credential) requires the matching key and a new verification.
        $this->configure(['settings' => ['mode' => 'live']])->assertSessionHasErrors('settings.secret_key');
        $this->configure(['settings' => ['mode' => 'live', 'secret_key' => self::fake('sk_live_', 26)]])->assertSessionHasNoErrors();
        self::assertSame($verifications + 1, $this->stripe->count('get', '/v1/checkout/sessions'));
        // Removing the key makes the method unavailable; disabling is always possible.
        $this->configure(['is_active' => false, 'clear' => ['secret_key' => true], 'settings' => ['mode' => 'test']])->assertSessionHasNoErrors();
        $this->get('/admin/orders/create')->assertInertia(fn (Assert $page) => $page->where('paymentMethods', fn ($m) => collect($m)->pluck('name')->doesntContain('Card payment')));
    }

    public function test_a_session_is_opened_for_the_authoritative_amount_and_never_twice(): void
    {
        $this->ready();
        [$order, $link] = $this->stripeOrder();
        $total = (string) DB::table('orders')->where('id', $order)->value('total');

        // Anything the browser submits is ignored: amount and currency come from the stored order.
        $response = $this->pay($link.'&amount=1&currency=USD');
        $response->assertStatus(403);
        $this->flushSession();
        $response = $this->post($link, ['amount' => '0.01', 'currency' => 'USD', 'total' => '1', 'status' => 'paid']);
        $attempt = $this->attempt();
        $create = $this->stripe->lastCreate();
        $response->assertRedirect('https://checkout.stripe.com/c/pay/'.$attempt->provider_reference);
        $params = $create['params'];
        self::assertSame('payment', $params['mode']);
        self::assertSame($attempt->reference, $params['client_reference_id']);
        // The customer is not asked again for the e-mail they gave at checkout.
        self::assertSame((string) DB::table('orders')->where('id', $order)->value('email'), $params['customer_email']);
        self::assertSame(1, (int) $params['line_items'][0]['quantity']);
        self::assertSame('eur', $params['line_items'][0]['price_data']['currency']);
        self::assertSame((int) round((float) $total * 100), (int) $params['line_items'][0]['price_data']['unit_amount']);
        self::assertSame(2480, (int) $params['line_items'][0]['price_data']['unit_amount']);
        self::assertSame($attempt->reference, $params['metadata']['payment_reference']);
        // Stripe is never asked to calculate taxes, discounts or promotions a second time.
        foreach (['automatic_tax', 'allow_promotion_codes', 'discounts', 'customer', 'shipping_options', 'payment_method_types'] as $unused) {
            self::assertArrayNotHasKey($unused, $params);
        }
        self::assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        self::assertStringContainsString('/pay/return/'.$attempt->id.'?', $params['success_url']);
        self::assertStringContainsString('/pay/cancel/'.$attempt->id.'?', $params['cancel_url']);
        self::assertSame('cms-create-'.$attempt->reference, $create['headers']['idempotency-key']);
        self::assertSame('Bearer '.self::KEY, $create['headers']['authorization']);
        self::assertSame(['created', '24.800000', 'EUR', null], [$attempt->state, $attempt->amount, $attempt->currency_code, $attempt->capture_reference]);
        self::assertStringStartsWith('cs_', (string) $attempt->provider_reference);

        // Double click, refresh or an unchanged retry: the open session is reused, no second session exists.
        $this->post($link)->assertRedirect('https://checkout.stripe.com/c/pay/'.$attempt->provider_reference);
        self::assertSame(1, $this->stripe->count('post', '/v1/checkout/sessions'));
        self::assertSame(1, DB::table('order_payments')->count());

        // A concurrent request that finds an attempt still being opened must not open another one.
        DB::table('order_payments')->where('id', $attempt->id)->update(['redirect_url' => null, 'created_at' => now()]);
        $this->post($link)->assertOk()->assertSee(__('payments.page_outcome_pending'));
        self::assertSame(1, $this->stripe->count('post', '/v1/checkout/sessions'));

        // Only the outstanding amount is charged after a partial payment recorded by staff.
        DB::table('order_payments')->where('id', $attempt->id)->update(['state' => 'cancelled', 'detail' => 'expired', 'completed_at' => now()]);
        $this->actingAs($this->actor)->post('/admin/orders/'.$order.'/payments', ['amount' => '10.00', 'note' => 'deposit'])->assertSessionHasNoErrors();
        $this->pay($link)->assertRedirect();
        self::assertSame(1480, (int) $this->stripe->lastCreate()['params']['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('14.800000', $this->attempt()->amount);
    }

    public function test_unsupported_amounts_and_provider_failures_leave_the_order_payable_without_exposing_details(): void
    {
        $this->ready();
        $method = app(PaymentSettings::class)->method(app(PaymentSettings::class)->row(self::METHOD));
        self::assertTrue($method->supports(new PaymentAmount('24.80', 'EUR', 2), []));
        self::assertTrue($method->supports(new PaymentAmount('0', 'EUR', 2), []));
        self::assertFalse($method->supports(new PaymentAmount('24.805', 'EUR', 3), []), 'Never rounded');
        self::assertFalse($method->supports(new PaymentAmount('100', 'HUF', 0), []));
        self::assertFalse($method->supports(new PaymentAmount('100', 'XXX', 2), []));
        self::assertFalse($method->supports(new PaymentAmount('1000000.00', 'EUR', 2), []));

        [$order, $link] = $this->stripeOrder();
        // Stripe unreachable: nothing decided, no session, the customer sees a retry message and nothing is charged.
        $this->stripe->failure = 'connection';
        $this->pay($link)->assertOk()->assertSee(__('payments.page_outcome_error'));
        self::assertSame(['failed', 'provider_unavailable', null], [$this->attempt()->state, $this->attempt()->detail, $this->attempt()->provider_reference]);
        self::assertSame('unpaid', $this->paymentStatus($order));
        // Stripe refuses the request (here: too small) or the key was revoked: failed attempts with safe codes, retry possible.
        $this->stripe->failure = 'invalid';
        $this->pay($link)->assertOk()->assertSee(__('payments.page_outcome_error'))->assertDontSee('Amount must be');
        self::assertSame('provider_rejected', $this->attempt()->detail);
        $this->stripe->failure = 'auth';
        $this->pay($link)->assertOk()->assertSee(__('payments.page_outcome_error'))->assertDontSee('sk_test');
        self::assertSame('credentials_rejected', $this->attempt()->detail);
        // Recovered: a new attempt and a new session; the earlier failures stay as history.
        $this->stripe->failure = null;
        $this->pay($link)->assertRedirect();
        self::assertSame([4, 3, 'created'], [DB::table('order_payments')->count(), DB::table('order_payments')->where('state', 'failed')->count(), $this->attempt()->state]);
        self::assertSame(0, DB::table('order_payments')->where('state', 'captured')->count());
    }

    public function test_returning_from_stripe_is_never_proof_and_a_verified_payment_is_recorded_once(): void
    {
        $this->ready();
        [$order, $link, $attempt, $session] = $this->started();
        $return = $this->successUrl($session);
        $stock = (int) DB::table('products')->value('quantity');
        $history = DB::table('order_status_history')->where('order_id', $order)->count();

        // The customer is back but Stripe has no completed payment: nothing is paid, whatever the URL says.
        $this->get($return)->assertOk()->assertSee(__('payments.page_outcome_failed'));
        self::assertSame(['created', 'unpaid'], [$this->attempt()->state, $this->paymentStatus($order)]);
        // Forged, unsigned or foreign links are refused.
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $return))->assertForbidden();
        $this->get(str_replace($session, 'cs_test_forged', $return))->assertNotFound();
        $this->get(substr($return, 0, strpos($return, '&session_id=')))->assertNotFound();
        self::assertSame('unpaid', $this->paymentStatus($order));

        // Stripe confirms payment: the server reads the session, verifies and records it exactly once.
        $this->stripe->pay($session, 'pi_test_card1');
        $this->get($return)->assertRedirectContains('/checkout/complete/');
        $attempt = $this->attempt();
        self::assertSame(['captured', 'pi_test_card1', null], [$attempt->state, $attempt->capture_reference, $attempt->detail]);
        self::assertSame('paid', $this->paymentStatus($order));
        self::assertSame($this->orderStatus('processing'), (string) DB::table('orders')->where('id', $order)->value('status_id'));
        self::assertSame($history + 1, DB::table('order_status_history')->where('order_id', $order)->count());
        self::assertSame($stock - 1, (int) DB::table('products')->value('quantity'));

        // Refresh / back button / the webhook arriving afterwards change nothing and trigger nothing twice.
        $this->get($return)->assertRedirectContains('/checkout/complete/');
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session)))->assertOk();
        self::assertSame(1, DB::table('order_payments')->where('state', 'captured')->count());
        self::assertSame($history + 1, DB::table('order_status_history')->where('order_id', $order)->count());
        self::assertSame($stock - 1, (int) DB::table('products')->value('quantity'));
        $this->get($link)->assertOk()->assertSee(__('payments.page_outcome_paid'))->assertDontSee('<form', false);
        $this->post($link)->assertOk()->assertSee(__('payments.page_outcome_paid'));
        self::assertSame(1, DB::table('order_payments')->count());
    }

    public function test_the_customer_cancelling_ends_the_attempt_expires_the_session_and_allows_a_retry(): void
    {
        $this->ready();
        [$order, $link, $attempt, $session] = $this->started();
        $cancel = (string) $this->stripe->lastCreate()['params']['cancel_url'];
        $this->get($cancel)->assertOk()->assertSee(__('payments.page_outcome_cancelled'));
        self::assertSame(['cancelled', 'buyer_cancelled'], [$this->attempt()->state, $this->attempt()->detail]);
        self::assertSame('expired', $this->stripe->sessions[$session]['status'], 'The old payment page can no longer be paid');
        self::assertSame('unpaid', $this->paymentStatus($order));
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $cancel))->assertForbidden();

        // A new attempt and session for the same order; the order is neither duplicated nor deleted.
        $this->pay($link)->assertRedirect();
        self::assertSame([2, 1, 'created'], [DB::table('order_payments')->count(), DB::table('orders')->count(), $this->attempt()->state]);
        self::assertNotSame($session, $this->attempt()->provider_reference);
        // A stale open attempt is replaced and its session ended too.
        $stale = $this->attempt();
        DB::table('order_payments')->where('id', $stale->id)->update(['created_at' => now()->subHours(2)]);
        $this->pay($link)->assertRedirect();
        self::assertSame('expired', $this->stripe->sessions[$stale->provider_reference]['status']);
        self::assertSame('cancelled', DB::table('order_payments')->where('id', $stale->id)->value('state'));
    }

    public function test_delayed_payment_methods_stay_pending_until_stripe_confirms(): void
    {
        $this->ready();
        [$order, $link, $attempt, $session] = $this->started();
        $stock = (int) DB::table('products')->value('quantity');
        // The customer finished Checkout with a delayed method (e.g. bank debit): complete, but not paid yet.
        $this->stripe->sessions[$session] = $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_test_delayed', 'payment_intent_status' => 'processing']);
        $confirmation = $this->get($this->successUrl($session))->assertRedirectContains('/checkout/complete/')->headers->get('Location');
        // The confirmation shows the payment as pending and does not offer to pay a second time.
        $this->get($confirmation)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('storefront.regions.content.1.blocks.0.content.checkout.placed.payment.state', 'pending')
            ->where('storefront.regions.content.1.blocks.0.content.checkout.placed.payment.pay_url', null));
        self::assertSame(['pending', 'pi_test_delayed'], [$this->attempt()->state, $this->attempt()->capture_reference]);
        self::assertSame(['pending', $stock], [$this->paymentStatus($order), (int) DB::table('products')->value('quantity')]);
        $this->get($link)->assertOk()->assertSee(__('payments.page_outcome_pending'))->assertDontSee('<form', false);
        $completed = $this->event('checkout.session.completed', $this->sessionData($session));
        $this->webhook($completed)->assertOk();
        self::assertSame('pending', $this->attempt()->state, 'A completed but unpaid session is not a payment');

        // The money arrives: recorded once, with the usual one-time order effects.
        $succeeded = $this->event('checkout.session.async_payment_succeeded', $this->sessionData($session, ['payment_status' => 'paid']));
        $this->webhook($succeeded)->assertOk()->assertJson(['received' => true]);
        self::assertSame(['captured', 'paid', $stock - 1], [$this->attempt()->state, $this->paymentStatus($order), (int) DB::table('products')->value('quantity')]);
        self::assertSame($this->orderStatus('processing'), (string) DB::table('orders')->where('id', $order)->value('status_id'));
        $this->webhook($succeeded)->assertOk();
        $this->webhook($this->event('checkout.session.async_payment_succeeded', $this->sessionData($session, ['payment_status' => 'paid'])))->assertOk();
        self::assertSame([1, $stock - 1, 1], [DB::table('order_payments')->where('state', 'captured')->count(), (int) DB::table('products')->value('quantity'), DB::table('order_status_history')->where('order_id', $order)->where('source', 'system')->count()]);

        // A delayed payment that fails ends the attempt unpaid, and the customer may pay again with a new session.
        [$order2, $link2, , $session2] = $this->started();
        $this->stripe->sessions[$session2] = $this->sessionData($session2, ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_test_bounce', 'payment_intent_status' => 'processing']);
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session2)))->assertOk();
        self::assertSame('pending', $this->attempt()->state);
        $this->webhook($this->event('checkout.session.async_payment_failed', $this->sessionData($session2)))->assertOk();
        self::assertSame(['failed', 'async_payment_failed', 'unpaid'], [$this->attempt()->state, $this->attempt()->detail, $this->paymentStatus($order2)]);
        $this->pay($link2)->assertRedirect();
        self::assertSame('created', $this->attempt()->state);
    }

    public function test_webhooks_require_a_valid_signature_and_a_well_formed_event(): void
    {
        $this->ready();
        [$order, , , $session] = $this->started();
        $event = $this->event('checkout.session.completed', $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1']));
        $payload = json_encode($event);

        $this->webhook($event, '')->assertStatus(400);
        $this->webhook($event, 'garbage')->assertStatus(400);
        $this->webhook($event, $this->signature($payload, 'whsec_someoneElsesSecretValue123456789'))->assertStatus(400);
        $this->webhook($event, $this->signature($payload, self::WHSEC, time() - 3600))->assertStatus(400);
        $this->webhook($event, $this->signature($payload).'0')->assertStatus(400);
        // A body altered after signing, and a signed body that is not an event, are both refused.
        $this->webhook($event, $this->signature($payload), str_replace('pi_test_1', 'pi_test_2', $payload))->assertStatus(400);
        $this->webhook($event, $this->signature('not json'), 'not json')->assertStatus(400);
        $this->webhook($event, $this->signature('[]'), '[]')->assertStatus(400);
        self::assertSame([0, 'created', 'unpaid'], [$this->eventRows(), $this->attempt()->state, $this->paymentStatus($order)]);

        // Valid: accepted without a session, cookie or CSRF token, and without echoing any payment data.
        $response = $this->webhook($event)->assertOk()->assertExactJson(['received' => true]);
        self::assertSame([], $response->headers->getCookies());
        self::assertStringNotContainsString(self::KEY, $response->getContent());
        self::assertSame(['captured', 'pi_test_1'], [$this->attempt()->state, $this->attempt()->capture_reference]);

        // Without a stored signing secret nothing can be verified: the provider is asked to retry later.
        DB::table('payment_methods')->where('code', self::METHOD)->update(['secrets' => app(PaymentSettings::class)->encrypt(['secret_key' => self::KEY])]);
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session)))->assertStatus(503);
        $this->post('/payments/webhooks/unknown_method', [])->assertStatus(503);
        $this->get(self::ENDPOINT)->assertStatus(405);
    }

    public function test_events_are_applied_once_in_any_order_and_late_money_is_never_lost(): void
    {
        $this->ready();
        [$order, , $attempt, $session] = $this->started();
        $stock = (int) DB::table('products')->value('quantity');
        $paid = $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_late']);
        $completed = $this->event('checkout.session.completed', $paid, 'evt_completed');

        // The session expires at Stripe first (the CMS ends the attempt), then a late payment event arrives.
        $this->webhook($this->event('checkout.session.expired', $this->sessionData($session, ['status' => 'expired'])))->assertOk();
        self::assertSame(['cancelled', 'session_expired', 'unpaid'], [$this->attempt()->state, $this->attempt()->detail, $this->paymentStatus($order)]);
        $this->webhook($completed)->assertOk();
        self::assertSame(['captured', 'late_capture', 'paid'], [$this->attempt()->state, $this->attempt()->detail, $this->paymentStatus($order)]);
        self::assertSame($stock - 1, (int) DB::table('products')->value('quantity'));

        // Replays and out-of-order events can neither duplicate nor undo the recorded payment.
        $rows = $this->eventRows();
        $this->webhook($completed)->assertOk()->assertJson(['received' => true]);
        self::assertSame($rows, $this->eventRows(), 'The same event ID is stored once');
        $this->webhook($this->event('checkout.session.async_payment_failed', $paid))->assertOk();
        $this->webhook($this->event('checkout.session.expired', $this->sessionData($session, ['status' => 'expired'])))->assertOk();
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session, ['payment_status' => 'unpaid'])))->assertOk();
        self::assertSame(['captured', 1, $stock - 1], [$this->attempt()->state, DB::table('order_payments')->where('state', 'captured')->count(), (int) DB::table('products')->value('quantity')]);
        self::assertSame(1, DB::table('order_status_history')->where('order_id', $order)->where('source', 'system')->count());
        self::assertSame('applied', DB::table('payment_webhook_events')->where('event_id', 'evt_completed')->value('outcome'));
        self::assertSame('pi_test_late', DB::table('order_payments')->where('id', $attempt->id)->value('capture_reference'), 'The PaymentIntent stays available for refunds');

        // A second payment for an order that is already paid is recorded (the money moved) and flagged for staff.
        [$order3, $link3] = $this->stripeOrder();
        $this->pay($link3)->assertRedirect();
        $first = $this->attempt();
        DB::table('order_payments')->where('id', $first->id)->update(['state' => 'cancelled', 'detail' => 'buyer_cancelled']);
        $this->actingAs($this->actor)->post('/admin/orders/'.$order3.'/payments', ['amount' => '24.80', 'note' => 'cash'])->assertSessionHasNoErrors();
        $this->webhook($this->event('checkout.session.completed', $this->sessionData((string) $first->provider_reference, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_dup'])))->assertOk();
        self::assertSame(['captured', 'duplicate_payment'], [DB::table('order_payments')->where('id', $first->id)->value('state'), DB::table('order_payments')->where('id', $first->id)->value('detail')]);
    }

    public function test_events_must_match_our_own_records_amount_and_currency(): void
    {
        $this->ready();
        [$order, , $attempt, $session] = $this->started();
        $paid = $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_x']);

        // Metadata alone is not authority: a reference that is not this attempt's changes nothing.
        $this->webhook($this->event('checkout.session.completed', [...$paid, 'client_reference_id' => (string) Str::uuid()]))->assertOk();
        $this->webhook($this->event('checkout.session.completed', [...$paid, 'client_reference_id' => null]))->assertOk();
        // A session we never opened is acknowledged (retrying cannot help) but ignored.
        $this->webhook($this->event('checkout.session.completed', [...$paid, 'id' => 'cs_test_unknown']))->assertOk();
        self::assertSame(['created', 'unpaid'], [$this->attempt()->state, $this->paymentStatus($order)]);
        self::assertEqualsCanonicalizing(['mismatch', 'mismatch', 'unmatched'], DB::table('payment_webhook_events')->pluck('outcome')->all());

        // The wrong amount or currency is never recorded as payment.
        $this->webhook($this->event('checkout.session.completed', [...$paid, 'amount_total' => 100]))->assertOk();
        self::assertSame(['failed', 'verification_failed', 'unpaid'], [$this->attempt()->state, $this->attempt()->detail, $this->paymentStatus($order)]);
        [$order2, , , $session2] = $this->started();
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session2, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_y', 'currency' => 'usd'])))->assertOk();
        self::assertSame(['failed', 'verification_failed', 'unpaid'], [$this->attempt()->state, $this->attempt()->detail, $this->paymentStatus($order2)]);
        self::assertSame(0, DB::table('order_payments')->where('state', 'captured')->count());

        // Test events never act on a live configuration (and vice versa); other event types are stored and ignored.
        [$order3, , , $session3] = $this->started();
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session3, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_z']), live: true))->assertOk();
        $this->webhook($this->event('charge.succeeded', ['id' => 'ch_1', 'object' => 'charge']))->assertOk();
        $this->webhook($this->event('checkout.session.completed', [...$this->sessionData($session3), 'mode' => 'subscription', 'payment_status' => 'paid']))->assertOk();
        self::assertSame(['created', 'unpaid'], [$this->attempt()->state, $this->paymentStatus($order3)]);
    }

    public function test_a_transient_failure_returns_an_error_so_stripe_retries_and_the_retry_applies_once(): void
    {
        $this->ready();
        [$order, , , $session] = $this->started();
        $stock = (int) DB::table('products')->value('quantity');
        $event = $this->event('checkout.session.completed', $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_retry']), 'evt_retry');

        // A database outage while applying the event (simulated by a trigger that refuses payment updates).
        DB::unprepared('CREATE FUNCTION test_payment_outage() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'simulated outage\'; END $$');
        DB::unprepared('CREATE TRIGGER test_payment_outage BEFORE UPDATE ON order_payments FOR EACH ROW EXECUTE FUNCTION test_payment_outage()');
        $this->webhook($event)->assertStatus(500);
        // Nothing was acknowledged or half-applied: no event row, still open.
        self::assertSame([0, 'created', 'unpaid'], [$this->eventRows(), $this->attempt()->state, $this->paymentStatus($order)]);
        DB::unprepared('DROP TRIGGER test_payment_outage ON order_payments');
        DB::unprepared('DROP FUNCTION test_payment_outage()');

        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        self::assertSame([1, 'captured', $stock - 1], [$this->eventRows(), $this->attempt()->state, (int) DB::table('products')->value('quantity')]);
        self::assertSame(1, DB::table('order_status_history')->where('order_id', $order)->where('source', 'system')->count());
    }

    public function test_disabling_the_method_keeps_history_and_late_payments_while_a_disabled_addon_defers_webhooks(): void
    {
        $this->ready();
        [$order, $link, , $session] = $this->started();
        $paid = $this->event('checkout.session.completed', $this->sessionData($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_off']));
        // The administrator switches the payment method off while a customer is paying: the order and attempt stay.
        $this->actingAs($this->actor)->configure(['is_active' => false])->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('order_payments')->count());
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('payment.method.name', 'Card payment')->where('payment.link', null));
        $this->webhook($paid)->assertOk();
        self::assertSame(['captured', 'paid'], [$this->attempt()->state, $this->paymentStatus($order)]);

        // With the whole add-on disabled the CMS cannot verify events, so Stripe is told to retry later; history stays.
        $this->actingAs($this->actor)->configure(['is_active' => true])->assertSessionHasNoErrors();
        [, , , $session2] = $this->started();
        app(AddonManager::class)->disable((string) $this->actor->id, self::ADDON);
        $this->webhook($this->event('checkout.session.completed', $this->sessionData($session2, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_later'])))->assertStatus(503);
        self::assertSame(1, DB::table('order_payments')->where('state', 'captured')->count());
        self::assertSame(2, DB::table('order_payments')->count());
        self::assertSame(1, $this->eventRows());
    }

    public function test_no_secret_reaches_customers_pages_or_logs_and_the_other_payment_methods_are_untouched(): void
    {
        $this->ready();
        [$order, $link] = $this->stripeOrder();
        $page = $this->get($link);
        $page->assertOk();
        foreach ([self::KEY, self::WHSEC, 'sk_test_', 'whsec_'] as $secret) {
            self::assertStringNotContainsString($secret, $page->getContent());
            self::assertStringNotContainsString($secret, json_encode($page->headers->all()));
        }
        $redirect = $this->pay($link);
        self::assertStringNotContainsString(self::KEY, (string) $redirect->headers->get('Location'));
        $this->actingAs($this->actor)->get('/admin/orders/'.$order)->assertDontSee(self::KEY)->assertDontSee(self::WHSEC);
        // The staff view shows safe payment references only.
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('payment.records.0.provider_reference', fn ($v) => str_starts_with((string) $v, 'cs_'))->missing('payment.records.0.secrets'));
        // Cash on delivery still works exactly as before next to Stripe.
        $product = $this->orderProduct('10.00');
        $this->post('/admin/orders', $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('cod')]))->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('orders')->count());
        self::assertSame(1, DB::table('order_payments')->count());
    }
}
