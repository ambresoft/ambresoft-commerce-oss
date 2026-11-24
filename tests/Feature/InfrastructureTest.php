<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_migrations_and_isolated_redis_connection(): void
    {
        self::assertSame('cms_test', DB::selectOne('select current_database() as name')->name);
        self::assertTrue(Schema::hasTable('failed_jobs'));
        self::assertTrue(Schema::hasTable('users'));
        self::assertTrue(Schema::hasTable('products'));
        self::assertTrue(Schema::hasTable('categories'));
        // Customer profiles and Phase 9 order management.
        self::assertTrue(Schema::hasTable('customers'));
        self::assertTrue(Schema::hasTable('orders'));

        $redis = Redis::connection();
        $key = 'foundation:'.bin2hex(random_bytes(8));

        try {
            $redis->setex($key, 10, 'connected');
            self::assertSame('connected', $redis->get($key));
        } finally {
            $redis->del($key);
        }
    }
}
