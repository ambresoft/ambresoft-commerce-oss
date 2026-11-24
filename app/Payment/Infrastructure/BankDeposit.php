<?php

namespace App\Payment\Infrastructure;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\PaymentMethod;

/**
 * Bank deposit: a core offline method. The store's bank accounts (IBANs) are written in the method's customer-facing
 * description per language. Staff record the payment once the deposit arrives; choosing it never marks an order paid.
 */
final class BankDeposit implements PaymentMethod
{
    public const ID = 'bank_deposit';

    public function id(): string
    {
        return self::ID;
    }

    public function provider(): string
    {
        return 'Core';
    }

    public function defaultText(string $locale): array
    {
        return ['name' => __('payments.bank_deposit_name', [], $locale), 'description' => __('payments.bank_deposit_description', [], $locale)];
    }

    public function settings(): array
    {
        return [];
    }

    public function validate(array $values): array
    {
        return [];
    }

    public function supports(PaymentAmount $amount, array $values): bool
    {
        return true;
    }
}
