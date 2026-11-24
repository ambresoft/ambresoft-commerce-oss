<?php

namespace App\Storefront\Infrastructure;

use App\Order\Infrastructure\OrderQueries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Order tracking by order number (/order-status). Order numbers run in sequence, so the page shows only what tracking
 * needs (number, date, status and its history), never names, addresses, products or amounts. A guest order is shown
 * to anyone with its number; an order of a registered customer only to that customer, signed in: a visitor who is not
 * signed in is asked to sign in, and any other customer is told the order was not found.
 */
final class OrderTracking
{
    public const NUMBER = '/^[A-Za-z0-9-]{1,40}$/D';

    /** @return array{state:'found'|'not_found'|'login',order:array<string,mixed>|null} */
    public function lookup(string $number, ?string $customer): array
    {
        $order = preg_match(self::NUMBER, $number) === 1 ? DB::table('orders')->where('order_number', $number)->first(['id', 'order_number', 'customer_id', 'status_id', 'created_at']) : null;
        if ($order === null || ($order->customer_id !== null && $customer !== null && (string) $order->customer_id !== $customer)) {
            return ['state' => 'not_found', 'order' => null];
        }
        if ($order->customer_id !== null && $customer === null) {
            return ['state' => 'login', 'order' => null];
        }
        $statuses = collect(app(OrderQueries::class)->statuses())->keyBy('id');
        $status = fn ($id): ?array => isset($statuses[(string) $id]) ? ['name' => $statuses[(string) $id]['name'], 'color' => $statuses[(string) $id]['color']] : null;
        $history = DB::table('order_status_history')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get(['to_status_id', 'created_at']);

        return ['state' => 'found', 'order' => [
            'number' => $order->order_number, 'created_at' => CarbonImmutable::parse($order->created_at)->toIso8601String(), 'status' => $status($order->status_id),
            'timeline' => $history->map(fn (object $h): array => ['status' => $status($h->to_status_id), 'at' => CarbonImmutable::parse($h->created_at)->toIso8601String()])->filter(fn (array $h): bool => $h['status'] !== null)->values()->all(),
            // The owner signed in: the full order (products, addresses, amounts) is in their account.
            'account_url' => $order->customer_id === null ? null : '/account/orders/'.$order->id,
        ]];
    }
}
