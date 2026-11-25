<?php

namespace App\Audit\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditContext::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            app(AuditContext::class)->actor((string) $event->user->getAuthIdentifier());
            app(AuditLogger::class)->record(new AuditEvent('auth.login', 'user', (string) $event->user->getAuthIdentifier()));
        });
        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditContext::class)->actor(null);
            app(AuditLogger::class)->record(new AuditEvent('auth.login_failed', 'user', $event->user ? (string) $event->user->getAuthIdentifier() : null, 'failure', ['attempted_email' => $event->credentials['email'] ?? null]));
        });
        foreach ([Verified::class => 'auth.email_verified', PasswordReset::class => 'auth.password_reset_completed', CurrentDeviceLogout::class => 'auth.session_revoked', OtherDeviceLogout::class => 'auth.session_revoked'] as $class => $action) {
            Event::listen($class, function ($event) use ($action): void {
                app(AuditContext::class)->actor((string) $event->user->getAuthIdentifier());
                app(AuditLogger::class)->record(new AuditEvent($action, 'user', (string) $event->user->getAuthIdentifier()));
            });
        }
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => app(AuditContext::class)->start(['source' => 'background_job']));
        foreach ([JobProcessed::class => 'job.completed', JobExceptionOccurred::class => 'job.failed'] as $class => $action) {
            Event::listen($class, function ($e) use ($action): void {
                try {
                    app(AuditLogger::class)->record(new AuditEvent($action, 'job', status: $action === 'job.failed' ? 'failure' : 'success', metadata: ['job' => $e->job->resolveName()]));
                } finally {
                    app(AuditContext::class)->clear();
                }
            });
        }
        // Commands that run during install or setup (Composer's package discovery, key generation) must work before a
        // database exists, so they do not open an audit context.
        $withoutDatabase = ['package:discover', 'key:generate', 'list', 'help'];
        Event::listen(CommandStarting::class, function (CommandStarting $e) use ($withoutDatabase): void {
            if (! in_array($e->command, $withoutDatabase, true)) {
                app(AuditContext::class)->start();
            }
        });
        Event::listen(CommandFinished::class, function (CommandFinished $e) use ($withoutDatabase): void {
            if (in_array($e->command, $withoutDatabase, true)) {
                return;
            }
            try {
                if (($e->command !== 'cms:product-files-cleanup' || $e->exitCode !== 0) && in_array($e->command, ['cms:permissions-sync', 'cms:admin-grant', 'cms:features-demo', 'cms:category-import-prune', 'cms:product-files-cleanup'], true)) {
                    app(AuditLogger::class)->record(new AuditEvent($e->exitCode === 0 ? 'command.completed' : 'command.failed', 'command', status: $e->exitCode === 0 ? 'success' : 'failure', metadata: ['command' => $e->command]));
                }
            } finally {
                app(AuditContext::class)->clear();
            }
        });
    }
}
