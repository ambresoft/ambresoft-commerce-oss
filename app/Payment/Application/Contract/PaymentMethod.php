<?php

namespace App\Payment\Application\Contract;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Command\SettingField;

/**
 * A way for customers to pay, registered by core (cash on delivery) or by an add-on through its manifest. A plain
 * method is offline: staff record the money when it is collected. Methods that hand the customer to an external
 * provider implement RedirectPaymentMethod. Implementations hold no state between calls; the CMS stores settings.
 */
interface PaymentMethod
{
    /** Stable identifier stored with settings and orders, e.g. `cod` or `stripe_checkout`. */
    public function id(): string;

    /** Who supplies the method, shown to administrators (not translated), e.g. "Core" or "Stripe Checkout". */
    public function provider(): string;

    /**
     * Customer-facing name and description in one locale, used until an administrator edits them.
     *
     * @return array{name: string, description: string}
     */
    public function defaultText(string $locale): array;

    /**
     * Method-specific settings rendered by the Payment methods editor.
     *
     * @return list<SettingField>
     */
    public function settings(): array;

    /**
     * Method-specific checks of complete submitted settings (secrets included).
     *
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => localized message
     */
    public function validate(array $values): array;

    /**
     * Whether the method can take this amount in this currency (the amount is "0" when only listing choices).
     *
     * @param  array<string, string>  $values
     */
    public function supports(PaymentAmount $amount, array $values): bool;
}
