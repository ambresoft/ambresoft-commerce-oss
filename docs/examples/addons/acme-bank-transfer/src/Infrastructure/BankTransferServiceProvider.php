<?php

namespace Acme\BankTransfer\Infrastructure;

use Illuminate\Support\ServiceProvider;

/** Registered only while the add-on is enabled; it only loads the add-on's own translations. */
final class BankTransferServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'acme-bank-transfer');
    }
}
