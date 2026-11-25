<?php

namespace App\Shipping\Infrastructure;

use App\Order\Application\Contract\OrderShipping;
use Illuminate\Support\Facades\DB;

/** Answers the Order context: shipping choices, server-calculated charges and payment dependencies. */
final class ShippingCharges implements OrderShipping
{
    public function __construct(private ShippingCalculator $calculator, private ShippingQueries $queries) {}

    public function choices(string $locale): array
    {
        $restrictions = DB::table('shipping_method_payment_methods')->get()->groupBy('method_id');

        return DB::table('shipping_methods')->where('is_active', true)->orderBy('position')->orderBy('id')->get(['id', 'calculation'])
            ->map(fn (object $m): array => [
                'id' => (string) $m->id, 'name' => $this->queries->name((string) $m->id, $locale), 'calculation' => $m->calculation,
                'payment_method_ids' => collect($restrictions[$m->id] ?? [])->pluck('payment_method_id')->map(strval(...))->values()->all(),
            ])->all();
    }

    public function quote(string $methodId, array $order, string $locale, bool $current = false): array
    {
        $method = preg_match('/^[1-9]\d{0,18}$/D', $methodId) === 1 ? DB::table('shipping_methods')->where('id', $methodId)->first() : null;
        if ($method === null) {
            return ['available' => false, 'reason' => 'missing', 'message' => __('shipping.reason_missing'), 'amount' => '0', 'tax_ids' => [], 'name' => '', 'details' => []];
        }
        $code = $order['payment_method_id'] === null ? null : DB::table('payment_methods')->where('id', $order['payment_method_id'])->value('code');
        $result = $this->calculator->evaluate($method, [...$order, 'payment_method_code' => $code], $current);
        $name = mb_substr($this->queries->name($methodId, $locale), 0, 100);
        if (! $result['available']) {
            // A carrier's own explanation is more precise than the generic reason; a carrier error keeps both.
            $detail = trim((string) ($result['message'] ?? ''));
            $result['message'] = $detail === '' ? __('shipping.reason_'.$result['reason'])
                : ($result['reason'] === 'carrier_error' ? __('shipping.reason_carrier_error').' '.$detail : $detail);
            // The method's taxes still apply to a staff-entered charge.
            $result['tax_ids'] = DB::table('shipping_method_taxes')->where('method_id', $methodId)->orderBy('tax_id')->pluck('tax_id')->map(strval(...))->all();
        }

        return [...$result, 'name' => $name];
    }

    public function allowsPayment(string $methodId, ?string $paymentMethodId): bool
    {
        $allowed = DB::table('shipping_method_payment_methods')->where('method_id', $methodId)->pluck('payment_method_id')->map(strval(...))->all();

        return $allowed === [] || ($paymentMethodId !== null && in_array($paymentMethodId, $allowed, true));
    }
}
