<?php

namespace Tests\Feature;

use App\Order\Infrastructure\OrderAdministration;
use App\Storefront\Infrastructure\LayoutRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StorefrontCallRequestTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private string $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => true]);
        $this->form = (string) DB::table('storefront_blocks')->where('type', 'identity')->value('id');
    }

    private function enable(bool $enabled = true): void
    {
        DB::table('storefront_blocks')->where('id', $this->form)->update(['settings' => json_encode(['show_call_request' => $enabled])]);
        Cache::flush();
    }

    public function test_callback_is_off_by_default_and_visitors_cannot_set_staff_workflow_fields(): void
    {
        $header = json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null)), true)['regions']['header'][0]['blocks'][0]['content'];
        self::assertFalse($header['display']['show_call_request']);
        self::assertNull($header['call_request_form']);
        $input = ['form' => $this->form, 'name' => ' Caller ', 'phone' => '+30 210 1234567', 'call_from' => '09:00', 'call_to' => '20:00'];
        $this->postJson('/call-requests', $input)->assertNotFound();
        $this->enable();
        $header = json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null)), true)['regions']['header'][0]['blocks'][0]['content'];
        self::assertTrue($header['display']['show_call_request']);
        self::assertSame($this->form, $header['call_request_form']);
        $this->postJson('/call-requests', [...$input, 'status' => 'completed', 'responsible_id' => 99999, 'created_by' => 99999, 'order_number' => 99999, 'order_id' => 99999, 'notes' => 'Forged note', 'products' => [['product_id' => 99999]]])->assertOk()->assertExactJson(['status' => 'ok']);
        $call = DB::table('call_requests')->sole();
        self::assertSame(['Caller', '302101234567', '09:00–20:00', 'new'], [$call->name, $call->phone_digits, $call->convenient_time, $call->status]);
        self::assertNull($call->responsible_id);
        self::assertNull($call->created_by);
        self::assertNull($call->order_id);
        self::assertSame('99999', (string) $call->order_number);
        self::assertNull($call->notes);
        self::assertSame(0, DB::table('call_request_products')->count());
        $audit = DB::table('audit_logs')->where('entity_type', 'call_request')->where('status', 'success')->sole();
        self::assertNull($audit->entity_label);
        self::assertStringNotContainsString('302101234567', $audit->changes);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->staff(['admin.access', 'call-requests.view']))->get('/admin/call-requests')->assertOk()->assertInertia(fn ($p) => $p->where('requests.total', 1)->where('requests.data.0.convenient_time', '09:00–20:00'));
    }

    public function test_optional_order_reference_links_known_orders_without_disclosing_existence_or_changing_them(): void
    {
        $this->enable();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $actor = $this->staff(super: true);
        $product = $this->orderProduct('10');
        $orderId = app(OrderAdministration::class)->create((string) $actor->id, $this->orderInput((string) $product->id));
        $before = DB::table('orders')->where('id', $orderId)->sole();
        $input = ['form' => $this->form, 'phone' => '2101234567'];
        $this->postJson('/call-requests', [...$input, 'order_number' => (string) $before->order_number, 'order_id' => 99999])->assertOk()->assertExactJson(['status' => 'ok']);
        $call = DB::table('call_requests')->sole();
        self::assertSame($orderId, (string) $call->order_id);
        self::assertSame((string) $before->order_number, (string) $call->order_number);
        self::assertEquals($before, DB::table('orders')->where('id', $orderId)->sole());
        $this->postJson('/call-requests', [...$input, 'order_number' => '999999'])->assertOk()->assertExactJson(['status' => 'ok']);
        $unknown = DB::table('call_requests')->where('order_number', '999999')->sole();
        self::assertNull($unknown->order_id);
        foreach (['bad', '-1', '9223372036854775808'] as $invalid) {
            $this->postJson('/call-requests', [...$input, 'order_number' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('order_number');
        }
        self::assertSame(2, DB::table('call_requests')->count());
    }

    public function test_phone_and_time_validation_reject_bad_input_and_allow_an_optional_range(): void
    {
        $this->enable();
        foreach ([
            [['phone' => 'wrong'], 'phone'],
            [['call_from' => '25:00', 'call_to' => '20:00'], 'call_from'],
            [['call_from' => '20:00', 'call_to' => '09:00'], 'call_to'],
            [['call_from' => '09:00'], 'call_to'],
        ] as [$invalid, $field]) {
            $this->postJson('/call-requests', ['form' => $this->form, 'phone' => '2101234567', ...$invalid])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        self::assertSame(0, DB::table('call_requests')->count());
        DB::table('general_settings')->where('id', 1)->update(['phone_validation' => 'digits']);
        $this->postJson('/call-requests', ['form' => $this->form, 'phone' => '+30 210 1234567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/call-requests', ['form' => $this->form, 'phone' => '2101234567'])->assertOk();
        self::assertNull(DB::table('call_requests')->sole()->convenient_time);
    }

    public function test_honeypot_closed_store_and_disabled_or_missing_forms_create_nothing(): void
    {
        $this->enable();
        $input = ['form' => $this->form, 'phone' => '2101234567'];
        $this->postJson('/call-requests', [...$input, 'website' => 'bot'])->assertOk();
        $this->postJson('/call-requests', [...$input, 'form' => (string) Str::uuid()])->assertNotFound();
        $this->enable(false);
        $this->postJson('/call-requests', $input)->assertNotFound();
        $this->enable();
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => false]);
        $this->postJson('/call-requests', $input)->assertNotFound();
        self::assertSame(0, DB::table('call_requests')->count());
    }

    public function test_page_override_forms_are_accepted_only_while_assigned_and_enabled(): void
    {
        $original = (array) DB::table('storefront_blocks')->where('id', $this->form)->first();
        $placement = DB::table('storefront_placements')->where('block_id', $this->form)->value('id');
        $this->form = (string) Str::uuid();
        $layout = DB::table('storefront_layouts')->where('page_type', 'homepage')->value('id');
        DB::table('storefront_blocks')->insert([...$original, 'id' => $this->form, 'layout_id' => $layout, 'settings' => json_encode(['show_call_request' => true])]);
        $input = ['form' => $this->form, 'phone' => '2101234567'];
        $this->postJson('/call-requests', $input)->assertNotFound();
        DB::table('storefront_overrides')->insert(['layout_id' => $layout, 'placement_id' => $placement, 'block_id' => $this->form, 'enabled' => true]);
        $this->postJson('/call-requests', $input)->assertOk();
        DB::table('storefront_overrides')->where('block_id', $this->form)->update(['enabled' => false]);
        $this->postJson('/call-requests', $input)->assertNotFound();
        self::assertSame(1, DB::table('call_requests')->count());
    }

    public function test_public_submissions_keep_real_csrf_protection(): void
    {
        $this->enable();
        $this->app->instance('env', 'production');
        $this->postJson('/call-requests', ['form' => $this->form, 'phone' => '2101234567'])->assertStatus(419);
        self::assertSame(0, DB::table('call_requests')->count());
    }

    public function test_public_submissions_are_rate_limited(): void
    {
        $this->enable();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/call-requests', ['form' => $this->form, 'website' => 'bot'])->assertOk();
        }
        $this->postJson('/call-requests', ['form' => $this->form, 'phone' => '2101234567'])->assertStatus(429);
        self::assertSame(0, DB::table('call_requests')->count());
    }
}
