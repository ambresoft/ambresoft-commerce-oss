<?php

namespace App\Payment\Infrastructure;

use App\Payment\Application\Command\PaymentWebhook;
use App\Payment\Application\Contract\HandlesPaymentWebhooks;
use App\Payment\Application\Contract\PaymentWebhookRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives verified provider notifications for payment methods that implement HandlesPaymentWebhooks. The method
 * verifies the signature and translates the event; this service stores each event once (unique per method and event
 * ID), checks the attempt against its own records, applies the result through OrderPayments and answers with an HTTP
 * status the provider understands: 2xx once the event is durably recorded or already known, 4xx for notifications that
 * cannot be trusted, 5xx for transient problems so the provider redelivers. Processing is one database transaction:
 * a failure leaves no event row and no partial payment change.
 */
final class PaymentWebhooks
{
    public const MAX_BODY_BYTES = 1048576;

    public function __construct(private PaymentSettings $settings, private OrderPayments $payments) {}

    /**
     * @param  array<string, string>  $headers  Lower-case header name => value
     * @return array{status: int, outcome: string}
     */
    public function handle(string $code, string $body, array $headers): array
    {
        $row = $this->settings->row($code);
        $method = $row === null ? null : $this->settings->method($row);
        // Unknown method, or its add-on is disabled: ask the provider to retry later instead of losing a payment.
        if ($row === null || ! $method instanceof HandlesPaymentWebhooks || ! $this->settings->ownerEnabled($row)) {
            return ['status' => 503, 'outcome' => 'unavailable'];
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'outcome' => 'too_large'];
        }
        try {
            $event = $method->webhook(new PaymentWebhook($body, $headers), $this->settings->values($method, $row));
        } catch (PaymentWebhookRejected $e) {
            Log::warning('payment_webhook_rejected', ['method' => $code, 'reason' => $e->reason]);

            return ['status' => $e->reason === PaymentWebhookRejected::NOT_CONFIGURED ? 503 : 400, 'outcome' => $e->reason];
        }
        try {
            return DB::transaction(function () use ($row, $event): array {
                $stored = DB::table('payment_webhook_events')->insertOrIgnore([
                    'payment_method_id' => $row->id, 'event_id' => $event->id, 'event_type' => $event->type, 'outcome' => 'ignored', 'received_at' => now(),
                ]);
                if ($stored === 0) {
                    // Redelivery: the first delivery was fully processed (or is committing right now).
                    return ['status' => 200, 'outcome' => 'duplicate'];
                }
                $paymentId = null;
                $outcome = 'ignored';
                if ($event->result !== null && $event->providerReference !== null) {
                    $attempt = DB::table('order_payments')->where('payment_method_id', $row->id)->where('kind', 'online')->where('provider_reference', $event->providerReference)->first();
                    if ($attempt === null) {
                        $outcome = 'unmatched';
                        Log::warning('payment_webhook_unmatched', ['method' => $row->code, 'event' => $event->id, 'type' => $event->type]);
                    } elseif ($event->reference === null || ! hash_equals((string) $attempt->reference, $event->reference)) {
                        // The provider's metadata is never enough on its own: it must agree with our own record.
                        $paymentId = (string) $attempt->id;
                        $outcome = 'mismatch';
                        Log::warning('payment_webhook_mismatch', ['method' => $row->code, 'event' => $event->id, 'payment_id' => $attempt->id]);
                    } else {
                        $paymentId = (string) $attempt->id;
                        $outcome = $this->payments->applyProviderResult($paymentId, $event->result) === $attempt->state ? 'unchanged' : 'applied';
                    }
                }
                DB::table('payment_webhook_events')->where('payment_method_id', $row->id)->where('event_id', $event->id)->update(['payment_id' => $paymentId, 'outcome' => $outcome]);

                return ['status' => 200, 'outcome' => $outcome];
            });
        } catch (Throwable $e) {
            Log::error('payment_webhook_failed', ['method' => $code, 'event' => $event->id, 'type' => $e::class]);

            return ['status' => 500, 'outcome' => 'error'];
        }
    }
}
