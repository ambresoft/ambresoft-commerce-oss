<?php

namespace App\Payment\Infrastructure;

use Illuminate\Support\Facades\URL;

/**
 * Signed URLs of the customer payment page and of the provider return/cancel endpoints. Signatures use the application
 * key; a changed or expired URL is refused. The payment page shows only the order number and amount due.
 */
final class PaymentLinks
{
    public const PAGE_DAYS = 30;

    public function payUrl(string $orderId): string
    {
        return URL::temporarySignedRoute('payments.pay', now()->addDays(self::PAGE_DAYS), ['order' => $orderId]);
    }

    public function returnUrl(string $paymentId): string
    {
        return URL::temporarySignedRoute('payments.return', now()->addDay(), ['payment' => $paymentId]);
    }

    public function cancelUrl(string $paymentId): string
    {
        return URL::temporarySignedRoute('payments.cancel', now()->addDay(), ['payment' => $paymentId]);
    }
}
