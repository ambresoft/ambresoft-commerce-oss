<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderCancellation;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Order\Infrastructure\OrderStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** Customers cancel their own order while its status allows it and nothing is paid; staff decide which statuses allow it. */
final class OrderCancellationTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->staffUser = $this->staff(super: true);
    }

    /** @return array{UserRecord, string} */
    private function customer(string $email = 'ria@example.test'): array
    {
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => $email, 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $id = (string) DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => $email, 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $id];
    }

    private function order(?string $customer = null, string $status = 'open'): string
    {
        $product = $this->orderProduct('20');

        return app(OrderAdministration::class)->create(null, $this->orderInput((string) $product->id, ['customer_id' => $customer, 'status_id' => $this->orderStatus($status)]), 'checkout');
    }

    private function statusOf(string $id): string
    {
        return (string) DB::table('order_statuses')->where('id', DB::table('orders')->where('id', $id)->value('status_id'))->value('code');
    }

    public function test_statuses_allow_cancelling_before_shipping_and_staff_can_change_it(): void
    {
        self::assertSame(['open', 'processing'], DB::table('order_statuses')->where('customer_can_cancel', true)->orderBy('position')->pluck('code')->all());
        $row = DB::table('order_statuses')->where('code', 'processing')->first();
        app(OrderStatuses::class)->save((string) $this->staffUser->id, (string) $row->id, (int) $row->lock_version, ['color' => $row->color, 'stock_action' => $row->stock_action, 'customer_can_cancel' => false, 'translations' => ['en' => ['name' => 'Processing']]]);
        self::assertFalse((bool) DB::table('order_statuses')->where('code', 'processing')->value('customer_can_cancel'));
        $order = $this->order(status: 'processing');
        self::assertFalse(app(OrderCancellation::class)->allowed($order));
        $this->actingAs($this->staffUser)->get('/admin/order-statuses/'.$row->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('status.customer_can_cancel', false));
    }

    public function test_a_customer_cancels_their_own_order_from_the_account(): void
    {
        [$user, $customer] = $this->customer();
        $order = $this->order($customer);
        $this->actingAs($user)->get('/account/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.0.blocks.0.content.account.order.can_cancel', true));

        // Another customer's order is never theirs to cancel.
        [$other] = $this->customer('other@example.test');
        $this->flushSession();
        $this->actingAs($other)->post('/account/orders/'.$order.'/cancel')->assertNotFound();
        $this->flushSession();
        self::assertSame('open', $this->statusOf($order));

        $this->actingAs($user)->post('/account/orders/'.$order.'/cancel')->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame('cancelled', $this->statusOf($order));
        self::assertSame('customer', DB::table('order_status_history')->where('order_id', $order)->orderByDesc('id')->value('source'));
        // Repeating it changes nothing; the page no longer offers it.
        $this->actingAs($user)->post('/account/orders/'.$order.'/cancel')->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('order_status_history')->where('order_id', $order)->count());
        $this->actingAs($user)->get('/account/orders/'.$order)->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.0.blocks.0.content.account.order.can_cancel', false));
    }

    public function test_shipped_or_paid_orders_cannot_be_cancelled_by_the_customer(): void
    {
        [$user, $customer] = $this->customer();
        $shipped = $this->order($customer, 'shipped');
        $this->actingAs($user)->post('/account/orders/'.$shipped.'/cancel')->assertSessionHasErrors(['order' => __('orders.cancel_not_allowed')]);
        $paid = $this->order($customer);
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $paid, 'kind' => 'manual', 'state' => 'captured', 'amount' => '1', 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->post('/account/orders/'.$paid.'/cancel')->assertSessionHasErrors(['order' => __('orders.cancel_paid')]);
        self::assertSame(['shipped', 'open'], [$this->statusOf($shipped), $this->statusOf($paid)]);
    }

    public function test_status_emails_carry_a_signed_cancel_link_that_guests_confirm(): void
    {
        $order = $this->order();
        $open = $this->orderStatus('open');
        $html = app(OrderEmailRenderer::class)->forOrder($order, null, $open, now()->toIso8601String())['html']['cancel_link'];
        self::assertStringContainsString('/orders/'.$order.'/cancel?expires=', html_entity_decode($html));
        // A status that does not allow cancelling never shows the link.
        self::assertSame('', app(OrderEmailRenderer::class)->forOrder($order, null, $this->orderStatus('shipped'), now()->toIso8601String())['html']['cancel_link']);

        $link = app(OrderCancellation::class)->url($order);
        $this->get('/orders/'.$order.'/cancel')->assertForbidden();
        // Opening the link only asks; mail scanners following it cancel nothing.
        $this->get($link)->assertOk()->assertSee(__('orders.cancel_confirm'))->assertHeader('Content-Security-Policy');
        self::assertSame('open', $this->statusOf($order));
        $this->post($link)->assertOk()->assertSee(__('orders.cancel_done'));
        self::assertSame('cancelled', $this->statusOf($order));
        $this->get($link)->assertOk()->assertSee(__('orders.cancel_done'))->assertDontSee('<form', false);
    }
}
