<?php

namespace App\Payment\Presentation\Http;

use App\Audit\Infrastructure\AuditContext;
use App\Payment\Infrastructure\PaymentWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, session-less endpoint for provider notifications (`POST /payments/webhooks/{method}`). It is registered
 * outside the browser middleware group on purpose: there is no session, cookie or CSRF token; authenticity is the
 * provider's signature, verified by the payment method over the raw body. Responses carry no payment data.
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentWebhooks $webhooks, AuditContext $audit, string $method): JsonResponse
    {
        $audit->start();
        try {
            $headers = [];
            foreach ($request->headers->all() as $name => $values) {
                $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }
            $result = $webhooks->handle($method, $request->getContent(), $headers);
        } finally {
            $audit->clear();
        }

        return response()->json(['received' => $result['status'] === 200], $result['status'])->header('Cache-Control', 'no-store');
    }
}
