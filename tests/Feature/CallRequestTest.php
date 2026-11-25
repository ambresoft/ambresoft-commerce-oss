<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Customer\Infrastructure\CallRequestQueries;
use App\Customer\Infrastructure\CallRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CallRequestTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @param array<string, mixed> $fields */
    private function create(array $fields = []): string
    {
        return app(CallRequests::class)->save((string) $this->actor->id, null, [...['name' => 'Maria', 'phone' => '+30 (210) 123-4567'], ...$fields]);
    }

    public function test_admin_creation_and_inline_workflow_preserve_contact_and_reject_stale_edits(): void
    {
        $this->post('/admin/call-requests', ['name' => ' Maria ', 'phone' => '+30 (210) 123-4567', 'notes' => 'Call about delivery', 'convenient_time' => '09:00–20:00'])->assertSessionHasNoErrors()->assertRedirect();
        $row = DB::table('call_requests')->sole();
        self::assertSame(['Maria', '302101234567', 'new', 1], [$row->name, $row->phone_digits, $row->status, (int) $row->lock_version]);
        $worker = $this->staff(['admin.access', 'call-requests.view', 'call-requests.manage']);
        $this->patch('/admin/call-requests/'.$row->id, ['version' => 1, 'status' => 'in_progress', 'responsible_id' => $worker->id, 'return_to' => '/admin/call-requests?status=new'])->assertSessionHasNoErrors()->assertRedirect('/admin/call-requests?status=new');
        $this->patch('/admin/call-requests/'.$row->id, ['version' => 1, 'notes' => 'Stale text'])->assertSessionHasErrors('version');
        $this->get('/admin/call-requests/'.$row->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/CallRequests/Form')->where('callRequest.version', 2)->where('callRequest.status', 'in_progress')->where('callRequest.notes', 'Call about delivery')->where('callRequest.convenient_time', '09:00–20:00')->where('callRequest.responsible_id', (string) $worker->id));
        foreach (['completed', 'no_answer', 'new'] as $offset => $status) {
            $this->patch('/admin/call-requests/'.$row->id, ['version' => 2 + $offset, 'status' => $status, 'return_to' => 'https://example.test'])->assertSessionHasNoErrors()->assertRedirect('/admin/call-requests/'.$row->id.'/edit');
        }
        self::assertSame(0, DB::table('orders')->count());
    }

    public function test_list_returns_only_truncated_notes_and_supports_search_filters_and_pagination(): void
    {
        $this->get('/admin/call-requests')->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'created_at')->where('filters.direction', 'desc'));
        $notes = str_repeat('α', 180).' private tail';
        $id = $this->create(['notes' => $notes]);
        $this->get('/admin/call-requests?search=00302101234567')->assertInertia(fn (Assert $page) => $page->component('Admin/CallRequests/Index')->where('requests.total', 1)->where('requests.data.0.id', $id)->where('requests.data.0.notes_preview', str_repeat('α', 160))->where('requests.data.0.notes_truncated', true)->missing('requests.data.0.notes'));
        $this->get('/admin/call-requests/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->where('callRequest.notes', $notes));
        foreach (range(1, 26) as $n) {
            $this->create(['name' => 'Caller '.$n, 'status' => 'completed']);
        }
        $this->get('/admin/call-requests?status=completed&responsible=unassigned&sort=id&direction=asc')->assertInertia(fn (Assert $page) => $page->where('requests.total', 26)->has('requests.data', 25)->where('requests.last_page', 2));
        $this->get('/admin/call-requests?search=Maria')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
        $this->get('/admin/call-requests?search=%25')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $this->get('/admin/call-requests?created_from=2099-01-01')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $this->get('/admin/call-requests?created_from=2026-02-01&created_to=2026-01-01')->assertSessionHasErrors('created_to');
        $this->get('/admin/call-requests?sort=phone;DROP')->assertSessionHasErrors('sort');
    }

    public function test_permissions_are_enforced_for_reads_writes_and_deletion(): void
    {
        $id = $this->create();
        $reader = $this->staff(['admin.access', 'call-requests.view']);
        $this->actingAs($reader)->get('/admin/call-requests')->assertOk();
        $this->get('/admin/call-requests/'.$id.'/edit')->assertOk();
        $this->get('/admin/call-requests/create')->assertForbidden();
        $this->post('/admin/call-requests', ['phone' => '2101234567'])->assertForbidden();
        $this->patch('/admin/call-requests/'.$id, ['version' => 1, 'status' => 'completed'])->assertForbidden();
        $this->delete('/admin/call-requests/'.$id, ['version' => 1])->assertForbidden();
        $this->delete('/admin/call-requests/bulk', ['rows' => [['id' => $id, 'version' => 1]]])->assertForbidden();
        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'orders.view']))->get('/admin/call-requests')->assertForbidden();
        self::assertSame('new', DB::table('call_requests')->where('id', $id)->value('status'));
    }

    public function test_assignment_requires_active_verified_authorized_staff_but_keeps_unavailable_saved_owner(): void
    {
        $worker = $this->staff(['admin.access', 'call-requests.view', 'call-requests.manage']);
        $id = $this->create(['responsible_id' => $worker->id]);
        $worker->is_active = false;
        $worker->save();
        $this->patch('/admin/call-requests/'.$id, ['version' => 1, 'status' => 'in_progress', 'responsible_id' => $worker->id])->assertSessionHasNoErrors();
        $options = app(CallRequestQueries::class)->assignees([(string) $worker->id]);
        self::assertFalse(collect($options)->firstWhere('id', (string) $worker->id)['eligible']);
        $other = $this->create();
        foreach ([$worker, $this->staff(['admin.access', 'call-requests.view'])] as $invalid) {
            $this->patch('/admin/call-requests/'.$other, ['version' => 1, 'responsible_id' => $invalid->id])->assertSessionHasErrors('responsible_id');
        }
        $this->patch('/admin/call-requests/'.$id, ['version' => 2, 'responsible_id' => ''])->assertSessionHasNoErrors();
        self::assertNull(DB::table('call_requests')->where('id', $id)->value('responsible_id'));
    }

    public function test_invalid_input_rolls_back_without_creating_requests(): void
    {
        foreach ([['phone' => ''], ['phone' => 'call me'], ['phone' => '00.'], ['status' => 'wrong'], ['notes' => str_repeat('x', 10001)], ['order_number' => 999999], ['responsible_id' => 999999], ['products' => [['product_id' => 999999, 'quantity' => 1]]]] as $fields) {
            $this->post('/admin/call-requests', [...['phone' => '2101234567'], ...$fields])->assertSessionHasErrors();
        }
        self::assertSame(0, DB::table('call_requests')->count());
        self::assertSame(0, DB::table('call_request_products')->count());
    }

    public function test_phone_validation_follows_the_store_policy(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['phone_validation' => 'digits']);
        $this->post('/admin/call-requests', ['phone' => '+30 210 1234567'])->assertSessionHasErrors('phone');
        $this->post('/admin/call-requests', ['phone' => '302101234567'])->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('call_requests')->count());
    }

    public function test_product_context_is_a_server_sourced_snapshot_without_inventory_changes(): void
    {
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $p = ProductRecord::create(['sku' => 'BOOK-1', 'price' => '10', 'quantity' => 20, 'category_id' => $category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Original book', 'slug' => 'book']);
        $id = $this->create(['products' => [['product_id' => $p->id, 'quantity' => 2, 'name' => 'Forged name']]]);
        $snapshot = DB::table('call_request_products')->sole();
        self::assertSame(['Original book', 'BOOK-1', 2], [$snapshot->name, $snapshot->sku, (int) $snapshot->quantity]);
        self::assertSame(20, $p->fresh()->quantity);
        $p->translations()->update(['name' => 'Renamed book']);
        $p->delete();
        $this->get('/admin/call-requests/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->where('callRequest.products.0.name', 'Original book')->where('callRequest.products.0.available', false));
        $this->patch('/admin/call-requests/'.$id, ['version' => 1, 'status' => 'completed', 'products' => []])->assertSessionHasErrors('products');
        self::assertSame('new', DB::table('call_requests')->where('id', $id)->value('status'));
    }

    public function test_bulk_delete_is_atomic_and_version_checked_and_cascades_context(): void
    {
        $first = $this->create();
        DB::table('call_request_products')->insert(['call_request_id' => $first, 'name' => 'Historical product', 'quantity' => 1, 'position' => 1]);
        $second = $this->create();
        $this->patch('/admin/call-requests/'.$second, ['version' => 1, 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->delete('/admin/call-requests/bulk', ['rows' => [['id' => $first, 'version' => 1], ['id' => $second, 'version' => 1]]])->assertSessionHasErrors('version');
        self::assertSame(2, DB::table('call_requests')->count());
        self::assertSame(1, DB::table('call_request_products')->count());
        $this->delete('/admin/call-requests/bulk', ['rows' => [['id' => $first, 'version' => 1], ['id' => $second, 'version' => 2]]])->assertSessionHasNoErrors()->assertRedirect('/admin/call-requests');
        self::assertSame(0, DB::table('call_requests')->count());
        self::assertSame(0, DB::table('call_request_products')->count());
    }

    public function test_existing_order_number_resolves_server_side_and_linking_does_not_change_the_order(): void
    {
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $product = ProductRecord::create(['sku' => 'CALL-ORDER', 'price' => '10', 'quantity' => 20, 'category_id' => $category->id, 'status' => 'active']);
        $product->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Test item', 'slug' => 'call-order']);
        $status = (string) DB::table('order_statuses')->where('code', 'open')->value('id');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, ['locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Street 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => [['product_id' => (string) $product->id, 'quantity' => 1]], 'status_id' => $status]);
        $before = DB::table('orders')->where('id', $order)->sole();
        $stock = $product->fresh()->quantity;
        $id = $this->create(['order_number' => (string) $before->order_number, 'order_id' => 999999]);
        $this->get('/admin/call-requests/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->where('callRequest.order_id', $order)->where('callRequest.order_number', (string) $before->order_number)->where('callRequest.order_status.id', $status));
        self::assertEquals($before, DB::table('orders')->where('id', $order)->sole());
        self::assertSame($stock, $product->fresh()->quantity);
        $this->patch('/admin/call-requests/'.$id, ['version' => 1, 'order_number' => ''])->assertSessionHasNoErrors();
        self::assertNull(DB::table('call_requests')->where('id', $id)->value('order_id'));
    }

    public function test_audit_captures_workflow_without_private_contact_or_notes(): void
    {
        $id = $this->create(['name' => 'Private caller', 'phone' => '+30 690 123 4999', 'notes' => 'Private details', 'convenient_time' => 'Secret preferred time']);
        $this->patch('/admin/call-requests/'.$id, ['version' => 1, 'status' => 'completed'])->assertSessionHasNoErrors();
        $event = DB::table('audit_logs')->where('entity_type', 'call_request')->where('action', 'call_request.status_changed')->sole();
        self::assertEquals(['before' => 'new', 'after' => 'completed'], json_decode($event->changes, true)['status']);
        $json = json_encode(DB::table('audit_logs')->where('entity_type', 'call_request')->get(), JSON_THROW_ON_ERROR);
        foreach (['Private caller', '690 123 4999', 'Private details', 'Secret preferred time'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function test_database_constraints_and_migration_round_trip_preserve_other_contexts(): void
    {
        $id = $this->create();
        foreach ([['status' => 'bad'], ['lock_version' => 0], ['phone_digits' => 'abc']] as $fields) {
            DB::beginTransaction();
            try {
                DB::table('call_requests')->where('id', $id)->update($fields);
                self::fail('Expected database constraint');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
        $users = DB::table('users')->count();
        $migration = require database_path('migrations/2026_10_16_000500_create_call_requests.php');
        $migration->down();
        self::assertFalse(Schema::hasTable('call_requests'));
        $migration->up();
        self::assertSame($users, DB::table('users')->count());
        self::assertTrue(Schema::hasTable('call_request_products'));
    }
}
