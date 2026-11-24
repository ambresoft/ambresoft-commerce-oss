<?php

namespace Tests;

use App\Order\Application\Contract\OrderMailTransport;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\RecordingMailTransport;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Runs before any test's RefreshDatabase setup can mutate a database.
        self::assertTrue($app->environment('testing'));
        self::assertSame('pgsql', $app->make('config')->get('database.default'));
        self::assertSame('cms_test', $app->make('db')->connection()->getDatabaseName());
        // No test can reach a real mail system: order emails are recorded instead.
        $app->singleton(OrderMailTransport::class, RecordingMailTransport::class);

        return $app;
    }
}
