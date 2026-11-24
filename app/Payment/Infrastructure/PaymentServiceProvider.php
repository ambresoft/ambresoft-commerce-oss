<?php

namespace App\Payment\Infrastructure;

use App\Order\Application\Contract\OrderPaymentMethods;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoints;
use Illuminate\Support\ServiceProvider;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentMethodRegistry::class);
        $this->app->bind(OrderPaymentMethods::class, PaymentMethodChoices::class);
        $this->app->make(PaymentMethodRegistry::class)->add(CashOnDelivery::ID, PaymentMethodRegistry::CORE, fn (): CashOnDelivery => new CashOnDelivery);
        $this->app->make(PaymentMethodRegistry::class)->add(BankDeposit::ID, PaymentMethodRegistry::CORE, fn (): BankDeposit => new BankDeposit);
        // Published before the module host loads enabled add-ons (it is registered last).
        $this->app->singletonIf(ModuleExtensionPoints::class);
        $this->app->make(ModuleExtensionPoints::class)->add('payment-methods', new PaymentMethodExtensionPoint($this->app, $this->app->make(PaymentMethodRegistry::class)));
    }
}
