<?php

namespace Ambresoft\CartReminders\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Registered only while the add-on is enabled; restart the scheduler after enabling or disabling it. */
final class CartRemindersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cart-reminders');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(CartReminders::class)->sendDue())->everyFifteenMinutes()->name('ambresoft-cart-reminders')->withoutOverlapping();
        });
    }
}
