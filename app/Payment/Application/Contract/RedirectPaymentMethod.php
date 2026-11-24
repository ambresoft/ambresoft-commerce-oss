<?php

namespace App\Payment\Application\Contract;

use App\Payment\Application\Command\PaymentCheck;
use App\Payment\Application\Command\PaymentRedirect;
use App\Payment\Application\Command\PaymentResult;
use App\Payment\Application\Command\PaymentStart;

/**
 * A method where the customer approves the payment at an external provider and returns. The CMS calls start() for a
 * server-calculated amount, sends the customer to the returned URL, and on return calls complete(), which must
 * capture and verify the payment with the provider server-side. Returning to the shop is never proof of payment.
 * Implementations throw PaymentProviderUnavailable for transport or provider outages (the attempt stays retryable).
 */
interface RedirectPaymentMethod extends PaymentMethod
{
    /**
     * Confirms the credentials with the provider.
     *
     * @param  array<string, string>  $values
     * @return string|null Null when accepted, otherwise a short reason code (e.g. `credentials_rejected`)
     */
    public function verify(array $values): ?string;

    /** @param array<string, string> $values */
    public function start(PaymentStart $start, array $values): PaymentRedirect;

    /**
     * Called when the customer returns: captures (idempotently) and verifies amount, currency and reference.
     *
     * @param  array<string, string>  $values
     */
    public function complete(PaymentCheck $check, array $values): PaymentResult;

    /**
     * Re-reads the provider's state of an earlier attempt (pending captures, interrupted returns).
     *
     * @param  array<string, string>  $values
     */
    public function refresh(PaymentCheck $check, array $values): PaymentResult;
}
