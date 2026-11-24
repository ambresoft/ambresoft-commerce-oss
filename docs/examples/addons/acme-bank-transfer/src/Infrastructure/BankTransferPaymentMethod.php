<?php

namespace Acme\BankTransfer\Infrastructure;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Command\SettingField;
use App\Payment\Application\Contract\PaymentMethod;

/**
 * An offline method: the customer transfers the amount and staff record the payment once it arrives. It only
 * implements the published PaymentMethod contract; settings are stored and rendered by the CMS.
 */
final class BankTransferPaymentMethod implements PaymentMethod
{
    public function id(): string
    {
        return 'acme.bank-transfer';
    }

    public function provider(): string
    {
        return 'Acme';
    }

    public function defaultText(string $locale): array
    {
        return ['name' => __('acme-bank-transfer::bank.name', [], $locale), 'description' => __('acme-bank-transfer::bank.description', [], $locale)];
    }

    public function settings(): array
    {
        return [
            new SettingField('account_holder', 'text', 'acme-bank-transfer::bank.account_holder', required: true, maxLength: 120),
            new SettingField('iban', 'text', 'acme-bank-transfer::bank.iban', 'acme-bank-transfer::bank.iban_hint', true, maxLength: 34),
        ];
    }

    public function validate(array $values): array
    {
        $iban = strtoupper(str_replace(' ', '', $values['iban'] ?? ''));

        return $iban !== '' && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/D', $iban) !== 1 ? ['iban' => __('acme-bank-transfer::bank.iban_invalid')] : [];
    }

    public function supports(PaymentAmount $amount, array $values): bool
    {
        return true;
    }
}
