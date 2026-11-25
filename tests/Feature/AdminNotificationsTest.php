<?php

namespace Tests\Feature;

use App\Customer\Infrastructure\CallRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use App\Notification\Infrastructure\NotificationStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $first;

    private UserRecord $second;

    private AdminNotifications $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync');
        $this->first = $this->staff(super: true);
        $this->second = $this->staff(['admin.access', 'orders.view', 'orders.update']);
        $this->notifications = app(AdminNotifications::class);
    }

    public function test_recipients_idempotency_and_deleted_tombstone(): void
    {
        $viewer = $this->staff(['admin.access', 'orders.view']);
        $unrelated = $this->staff();
        $inactive = $this->staff(super: true);
        DB::table('users')->where('id', $inactive->id)->update(['is_active' => false]);
        $unverified = $this->staff(super: true);
        DB::table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        $customer = UserRecord::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'long customer password']);
        $this->notifications->orderPlaced('7', '100007');
        $this->notifications->orderPlaced('7', '100007');
        $this->assertDatabaseCount('admin_notifications', 2);
        foreach ([$viewer, $unrelated, $inactive, $unverified, $customer] as $user) {
            $this->assertDatabaseMissing('admin_notifications', ['user_id' => $user->id]);
        }
        $id = (string) DB::table('admin_notifications')->where('user_id', $this->first->id)->value('id');
        $this->notifications->change((string) $this->first->id, 'delete', $id);
        $this->notifications->orderPlaced('7', '100007');
        self::assertSame(0, $this->notifications->listing((string) $this->first->id)['unread']);
        $this->assertDatabaseCount('admin_notifications', 2);
    }

    public function test_failed_admin_sign_ins_notify_security_staff_once_per_burst(): void
    {
        $target = $this->staff();
        for ($i = 0; $i < 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4'])->post('/admin/login', ['email' => $target->email, 'password' => 'wrong password'])->assertSessionHasErrors('email');
        }
        // Only account-security staff (super administrators) receive it; order staff do not see it.
        self::assertSame(1, DB::table('admin_notifications')->where('type', 'security.login_failed')->count());
        $this->assertDatabaseHas('admin_notifications', ['type' => 'security.login_failed', 'user_id' => $this->first->id, 'resource_number' => '3|8.8.4.4']);
        $item = $this->notifications->listing((string) $this->first->id)['items'][0];
        self::assertSame('security.login_failed', $item['type']);
        self::assertSame('/admin/account-security/'.$target->id, $item['url']);
        self::assertStringContainsString('8.8.4.4', $item['message']);
        self::assertSame([], $this->notifications->listing((string) $this->second->id)['items']);
    }

    public function test_reviews_and_call_requests_reach_only_staff_who_handle_them(): void
    {
        $moderator = $this->staff(['admin.access', 'reviews.view', 'reviews.moderate']);
        $caller = $this->staff(['admin.access', 'call-requests.view']);
        self::assertTrue($this->notifications->eligible($moderator));
        $this->notifications->reviewSubmitted('5', 4);
        app(CallRequests::class)->submit(['name' => 'Maria P', 'phone' => '+30 690 000 0000']);
        $call = (string) DB::table('call_requests')->value('id');
        self::assertSame(['review.pending'], array_column($this->notifications->listing((string) $moderator->id)['items'], 'type'));
        $item = $this->notifications->listing((string) $caller->id)['items'][0];
        self::assertSame(['call.requested', '/admin/call-requests/'.$call.'/edit'], [$item['type'], $item['url']]);
        self::assertStringContainsString('Maria P', $item['message']);
        self::assertSame(['call.requested', 'review.pending'], array_column($this->notifications->listing((string) $this->first->id)['items'], 'type'));
        self::assertSame([], $this->notifications->listing((string) $this->second->id)['items']);
    }

    public function test_rollback_removes_notifications_and_revision(): void
    {
        DB::beginTransaction();
        $this->notifications->orderPlaced('8', '100008');
        $this->assertDatabaseCount('admin_notifications', 2);
        DB::rollBack();
        $this->assertDatabaseCount('admin_notifications', 0);
        $this->assertDatabaseCount('admin_notification_inboxes', 0);
    }

    public function test_actions_and_listing_are_recipient_scoped_and_persist_across_sessions(): void
    {
        $this->notifications->orderPlaced('7', '100007');
        $this->notifications->orderPlaced('8', '100008');
        $this->actingAs($this->first);
        $list = $this->getJson('/admin/notifications')->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'items')->json();
        $id = $list['items'][0]['id'];
        $this->postJson('/admin/notifications/'.$id, ['action' => 'read'])->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/admin/notifications/'.$id, ['action' => 'read'])->assertOk()->assertJsonPath('unread', 1);
        $other = (string) DB::table('admin_notifications')->where('user_id', $this->second->id)->value('id');
        $this->postJson('/admin/notifications/'.$other, ['action' => 'delete'])->assertNotFound();
        $this->postJson('/admin/notifications/'.$id, ['action' => 'delete'])->assertOk()->assertJsonCount(1, 'items');
        $this->postJson('/admin/notifications/read-all', ['action' => 'read'])->assertOk()->assertJsonPath('unread', 0);
        $this->flushSession();
        $this->actingAs($this->second)->getJson('/admin/notifications')->assertOk()->assertJsonPath('unread', 2);
        $this->post('/admin/logout')->assertRedirect();
        $this->flushSession();
        $this->post('/admin/login', ['email' => $this->first->email, 'password' => 'a long test passphrase'])->assertSessionHasNoErrors()->assertRedirect();
        $this->getJson('/admin/notifications')->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(1, 'items');
    }

    public function test_missing_order_is_historical_and_pagination_is_stable_for_equal_timestamps(): void
    {
        $this->freezeTime();
        for ($i = 1; $i <= 25; $i++) {
            $this->notifications->orderPlaced((string) $i, (string) (100000 + $i));
        }
        $this->actingAs($this->first);
        $page = $this->getJson('/admin/notifications')->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('unread', 25)->assertJsonPath('items.0.url', null)->json();
        $next = $this->getJson('/admin/notifications?before='.$page['next'])->assertOk()->assertJsonCount(5, 'items')->json();
        self::assertSame([], array_intersect(array_column($page['items'], 'id'), array_column($next['items'], 'id')));
        $this->getJson('/admin/notifications?before=invalid')->assertUnprocessable();
    }

    public function test_unauthorized_and_revoked_users_cannot_use_any_endpoint(): void
    {
        $this->getJson('/admin/notifications')->assertUnauthorized();
        $this->getJson('/admin/notifications/stream')->assertUnauthorized();
        $this->actingAs($this->staff());
        $this->getJson('/admin/notifications')->assertForbidden();
        $this->getJson('/admin/notifications/stream')->assertForbidden();
        $this->postJson('/admin/notifications/read-all', ['action' => 'read'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->second);
        $this->second->roles()->detach();
        $this->getJson('/admin/notifications')->assertForbidden();
    }

    public function test_stream_has_sse_headers_and_reconciles_old_current_and_future_cursors(): void
    {
        config(['admin_notifications.lifetime_seconds' => 0]);
        $this->notifications->orderPlaced('7', '100007');
        $this->actingAs($this->first);
        foreach (['0', '1', '999'] as $cursor) {
            $response = $this->withHeader('Last-Event-ID', $cursor)->get('/admin/notifications/stream');
            $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')->assertHeader('X-Accel-Buffering', 'no');
            $content = $response->streamedContent();
            self::assertStringContainsString("retry: 10000\n\n", $content);
            self::assertStringContainsString("id: 1\nevent: notifications.sync\ndata: {\"revision\":\"1\"}\n\n", $content);
            self::assertStringNotContainsString('100007', $content);
        }
        $this->withHeader('Last-Event-ID', '-1')->getJson('/admin/notifications/stream')->assertUnprocessable();
        self::assertSame("id: 2\nevent: notification.created\ndata: {\"revision\":\"2\"}\n\n", NotificationStream::frame('notification.created', '2', ['revision' => '2']));
    }

    public function test_stream_rechecks_permissions_after_response_is_constructed(): void
    {
        config(['admin_notifications.lifetime_seconds' => 0]);
        $response = $this->actingAs($this->second)->get('/admin/notifications/stream')->assertOk();
        $this->second->roles()->detach();
        self::assertStringContainsString('event: stream.revoked', $response->streamedContent());
    }
}
