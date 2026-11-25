<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\Contract\PasswordResetterInterface;
use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Infrastructure\Auth\BrandedMailChannel;
use App\Identity\Infrastructure\Auth\LaravelPasswordResetter;
use App\Identity\Infrastructure\Auth\LaravelRegistrationNotifier;
use App\Identity\Infrastructure\Persistence\EloquentUserRepository;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Infrastructure\Security\AccountSecurity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && $event->user instanceof UserRecord && Auth::guard('web')->viaRemember()) {
                app(AccountSecurity::class)->record(request(), $event->user, 'login');
            }
        });
        Event::listen(Registered::class, function (Registered $event): void {
            $request = request();
            if ($event->user instanceof UserRecord && $request->routeIs('register.store', 'admin.register.store')) {
                app(AccountSecurity::class)->record($request, $event->user, 'registration');
            }
        });
        Event::listen(Failed::class, function (Failed $event): void {
            if ($event->guard === 'web' && $event->user instanceof UserRecord && isset($event->credentials['password'])) {
                app(AccountSecurity::class)->record(request(), $event->user, 'password_failed');
            }
        });
    }

    public function register(): void
    {
        $this->app->bind(MailChannel::class, BrandedMailChannel::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(RegistrationNotifierInterface::class, LaravelRegistrationNotifier::class);
        $this->app->bind(PasswordResetterInterface::class, LaravelPasswordResetter::class);
    }
}
