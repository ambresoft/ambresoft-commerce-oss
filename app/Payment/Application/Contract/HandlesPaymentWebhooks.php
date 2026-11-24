<?php

namespace App\Payment\Application\Contract;

use App\Payment\Application\Command\PaymentWebhook;
use App\Payment\Application\Command\PaymentWebhookEvent;

/**
 * A redirect method whose provider also confirms payments asynchronously through signed notifications. The CMS exposes
 * one public endpoint per method (`POST /payments/webhooks/{method id}`), hands the raw request to webhook(), and
 * itself stores each event once, finds the attempt, applies the result exactly once and answers the provider.
 * The method must verify the provider's signature before trusting anything in the body and must never write payment
 * or order data. Webhook delivery and replay are the provider's concern; the CMS makes processing idempotent.
 */
interface HandlesPaymentWebhooks extends RedirectPaymentMethod
{
    /**
     * @param  array<string, string>  $values  The method's decrypted settings (signing secret included)
     *
     * @throws PaymentWebhookRejected When the signature is missing/invalid, the body is malformed or the method is not configured
     */
    public function webhook(PaymentWebhook $webhook, array $values): PaymentWebhookEvent;
}
