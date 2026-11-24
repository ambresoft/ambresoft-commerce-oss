<?php

namespace Ambresoft\GoogleAnalytics\Infrastructure\Jobs;

use Ambresoft\GoogleAnalytics\Infrastructure\GoogleAnalyticsServiceProvider;
use App\Order\Application\Event\OrderPlaced;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The GA4 purchase, sent server-side through the Measurement Protocol so no script runs on checkout pages (A148). The
 * API secret is read when sending and never stored in the queue.
 */
final class SendPurchase implements ShouldQueue
{
    use Queueable;

    public const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public OrderPlaced $order, public string $clientId, public ?string $sessionId) {}

    public function handle(AddonSettingValues $settings): void
    {
        $values = $settings->values(GoogleAnalyticsServiceProvider::NAME);
        if (($values['measurement_id'] ?? '') === '' || ($values['api_secret'] ?? '') === '') {
            return;
        }
        try {
            $response = Http::timeout(10)->post(self::ENDPOINT.'?'.http_build_query(['measurement_id' => $values['measurement_id'], 'api_secret' => $values['api_secret']]), $this->payload());
        } catch (ConnectionException) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }
        // Google answers 2xx whenever it received the request; anything else is not retried, as Google asks.
        if (! $response->successful()) {
            Log::warning('google_analytics_purchase_refused', ['order' => $this->order->orderId, 'status' => $response->status()]);
        }
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $o = $this->order;
        // GA4 takes numbers; these are reporting values, never amounts the store charges.
        $number = fn (string $amount): float => (float) Decimal::round($amount, $o->decimals);
        $items = array_map(fn (array $i): array => array_filter([
            'item_id' => $i['sku'] ?? $i['product_id'] ?? $i['name'],
            'item_name' => mb_substr($i['name'], 0, 100),
            'item_variant' => $i['variation'] === null ? null : mb_substr($i['variation'], 0, 100),
            'price' => $number(Decimal::divide($i['total'], (string) max(1, $i['quantity']), $o->decimals)),
            'quantity' => $i['quantity'],
        ], fn ($v): bool => $v !== null && $v !== ''), array_slice($o->items, 0, 200));
        $value = array_reduce($o->items, fn (string $sum, array $i): string => Decimal::add($sum, $i['total']), '0');

        return ['client_id' => $this->clientId, 'events' => [['name' => 'purchase', 'params' => array_filter([
            'transaction_id' => $o->orderNumber, 'currency' => $o->currency, 'value' => $number($value), 'tax' => $number($o->tax),
            'shipping' => $number($o->shipping), 'coupon' => $o->coupon, 'session_id' => $this->sessionId, 'engagement_time_msec' => 1, 'items' => $items,
        ], fn ($v): bool => $v !== null)]]];
    }
}
