<?php

namespace App\Payment\Infrastructure;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\PaymentMethod;

/**
 * Cash on delivery: a core offline method. Choosing it never marks an order paid; staff record the payment when the
 * money has actually been collected. No credentials or external services are involved.
 */
final class CashOnDelivery implements PaymentMethod
{
    public const ID = 'cod';

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
        return ['name' => __('payments.cod_name', [], $locale), 'description' => __('payments.cod_description', [], $locale)];
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
