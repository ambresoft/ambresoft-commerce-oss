<?php

namespace App\Payment\Application\Contract;

use App\Payment\Application\Command\PaymentCheck;

/**
 * Optional capability of a redirect method: end an unfinished provider payment (for example a hosted checkout session)
 * once the CMS has cancelled or replaced its attempt, so the old page can no longer be paid. Best effort: the CMS
 * ignores failures, and a late payment is still recorded when the provider reports it.
 */
interface ExpiresProviderPayments extends RedirectPaymentMethod
{
    /** @param array<string, string> $values */
    public function expire(PaymentCheck $check, array $values): void;
}
