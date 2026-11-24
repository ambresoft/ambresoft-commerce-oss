<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AdminNotificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, StaffIdentities;

    private UserRecord $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync');
        $this->user = $this->staff(super: true);
        config(['database.connections.notification_race' => config('database.connections.pgsql')]);
        $race = DB::connection('notification_race');
        $race->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', $race->selectOne('select current_database() as name')->name);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('pgsql');
        DB::purge('notification_race');
        parent::tearDown();
    }

    public function test_other_connections_see_only_committed_revisions_and_concurrent_writers_cannot_overtake(): void
    {
        $notifications = app(AdminNotifications::class);
        $uid = (string) $this->user->id;
        $notifications->orderPlaced('1', '100001');
        DB::beginTransaction();
        try {
            $notifications->orderPlaced('2', '100002');
            DB::setDefaultConnection('notification_race');
            self::assertSame(1, (int) $notifications->state($uid)->revision);
            self::assertSame(1, DB::table('admin_notifications')->count());
            try {
                $notifications->orderPlaced('3', '100003');
                self::fail('Concurrent writer must wait for the inbox transaction');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            DB::setDefaultConnection('pgsql');
            DB::commit();
            DB::setDefaultConnection('notification_race');
            self::assertSame(2, (int) $notifications->state($uid)->revision);
            $notifications->orderPlaced('3', '100003');
            $state = $notifications->listing($uid);
            self::assertSame('3', $state['revision']);
            self::assertSame(3, $state['unread']);
            self::assertCount(3, $state['items']);
        } finally {
            DB::setDefaultConnection('pgsql');
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_capacity_limit_returns_retry_without_holding_another_worker(): void
    {
        config(['admin_notifications.max_connections' => 1, 'admin_notifications.lifetime_seconds' => 0]);
        $race = DB::connection('notification_race');
        $race->select('SELECT pg_advisory_lock(2142599, 0)');
        try {
            $response = $this->actingAs($this->user)->get('/admin/notifications/stream')->assertOk();
            self::assertStringContainsString("retry: 10000\n\nevent: stream.busy\ndata: {}", $response->streamedContent());
        } finally {
            $race->select('SELECT pg_advisory_unlock(2142599, 0)');
        }
        $response = $this->get('/admin/notifications/stream')->assertOk();
        self::assertStringContainsString('event: notifications.sync', $response->streamedContent());
        self::assertTrue($race->selectOne('SELECT pg_try_advisory_lock(2142599, 0) AS acquired')->acquired);
        $race->select('SELECT pg_advisory_unlock(2142599, 0)');
    }

    public function test_stream_emits_heartbeats_and_finishes_within_its_lifetime(): void
    {
        config(['admin_notifications.lifetime_seconds' => 4, 'admin_notifications.check_seconds' => 1, 'admin_notifications.heartbeat_seconds' => 3]);
        $response = $this->actingAs($this->user)->get('/admin/notifications/stream')->assertOk();
        $checks = 0;
        DB::connection('pgsql')->beforeExecuting(function (string $query) use (&$checks): void {
            if (! str_starts_with($query, 'select * from "admin_notification_inboxes"')) {
                return;
            }
            $checks++;
            DB::setDefaultConnection('notification_race');
            try {
                if ($checks === 2) {
                    app(AdminNotifications::class)->orderPlaced('9', '100009');
                } elseif ($checks === 3) {
                    app(AdminNotifications::class)->change((string) $this->user->id, 'read');
                }
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        });
        $start = microtime(true);
        $content = $response->streamedContent();
        self::assertStringContainsString(": heartbeat\n\n", $content);
        self::assertStringContainsString("id: 1\nevent: notification.created", $content);
        self::assertStringContainsString("id: 2\nevent: notifications.changed", $content);
        self::assertLessThan(6, microtime(true) - $start);
    }
}
