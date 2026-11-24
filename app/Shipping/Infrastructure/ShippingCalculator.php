<?php

namespace App\Shipping\Infrastructure;

use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\CarrierQuote;
use App\Shipping\Application\Command\Destination;
use App\Shipping\Application\Command\RateRequest;
use App\Shipping\Application\Contract\QuotesRates;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Contract\ShippingProviderRejected;
use App\Shipping\Application\Contract\ShippingProviderUnavailable;
use App\Shipping\Domain\Service\ManualRates;
use App\Shipping\Domain\Service\ShipmentValue;
use App\Shipping\Domain\Service\ShippingWeights;
use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Domain\Model\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calculates whether a shipping method can serve an order and what it charges, always on the server from stored data.
 *
 * Availability: active (an order's current method may be recalculated while disabled), customer group, destination
 * (manual: a rate area with rates; carrier: the optional area restriction and the provider's own rules), weight
 * limits on the physical weight, a usable provider and account for carrier rates, and the order currency.
 * Charge: manual rate or carrier quote (gross or net + surcharge) for the chargeable weight, plus per-product shipping
 * costs of charged items. Free-shipping products add neither weight nor cost when the method honours free shipping;
 * an order made only of them ships free. Carrier quotes are cached per exact context (method, account, settings,
 * destination, weights, value, currency, payment method) and never reused across contexts.
 */
final class ShippingCalculator
{
    private const QUOTE_MINUTES = 15;

    public function __construct(private CarrierSettings $carriers) {}

    /**
     * @param  array{lines: list<array{product_id: ?string, quantity: int}>, address: ?array<string, mixed>, currency: string, decimals: int, customer_id: ?string, payment_method_code: ?string, subtotal: string, shipment_value?: string}  $order
     * @return array{available: bool, reason: ?string, message: ?string, amount: string, tax_ids: list<string>, details: array<string, mixed>}
     */
    public function evaluate(object $method, array $order, bool $current = false): array
    {
        $unavailable = fn (string $reason, ?string $message = null): array => ['available' => false, 'reason' => $reason, 'message' => $message, 'amount' => '0', 'tax_ids' => [], 'details' => []];
        if (! $method->is_active && ! $current) {
            return $unavailable('inactive');
        }
        $groups = DB::table('shipping_method_customer_groups')->where('method_id', $method->id)->pluck('group_id')->all();
        if ($groups !== [] && ($order['customer_id'] === null || ! DB::table('customer_group_members')->where('customer_id', $order['customer_id'])->whereIn('group_id', $groups)->exists())) {
            return $unavailable('customer_group');
        }
        $country = strtoupper(trim((string) ($order['address']['country_code'] ?? '')));
        if ($country === '') {
            return $unavailable('address_required');
        }
        $address = new Address($country, $order['address']['region_code'] ?? null);
        $settings = app(GeneralSettings::class)->all();
        $grams = (string) $settings['grams_per_weight_unit'];
        $weights = ShippingWeights::measure($this->lines($order['lines']), (bool) $method->free_shipping_applies);
        if (($method->min_weight !== null && Decimal::compare($weights['physical'], Decimal::trim($method->min_weight)) < 0)
            || ($method->max_weight !== null && Decimal::compare($weights['physical'], Decimal::trim($method->max_weight)) > 0)) {
            return $unavailable('weight_limit');
        }
        // The courier's maximum shipment value; an active limit with no value to measure refuses rather than allows.
        $limited = (bool) ($method->value_limit_enabled ?? false) && $method->max_shipment_value !== null;
        if ($limited && (! isset($order['shipment_value']) || ! ShipmentValue::allowed((string) $order['shipment_value'], true, Decimal::trim($method->max_shipment_value)))) {
            return $unavailable('value_limit');
        }
        $scale = $order['decimals'];
        $allFree = $weights['chargeable_items'] === 0;
        $areas = app(TaxConfiguration::class)->areas();
        $details = [
            'calculation' => $method->calculation, 'weight_unit' => (string) $settings['weight_unit'], 'physical_weight' => $weights['physical'],
            'chargeable_weight' => $weights['chargeable'], 'free_shipping_only' => $allFree, 'freight' => Decimal::round($weights['freight'], $scale),
        ];
        if ($method->calculation === 'manual') {
            $rates = [];
            foreach (DB::table('shipping_method_rates')->where('method_id', $method->id)->get() as $rate) {
                $specificity = isset($areas[(string) $rate->rate_area_id]) ? $areas[(string) $rate->rate_area_id]->specificity($address) : null;
                if ($specificity !== null) {
                    $rates[] = ['area_id' => (string) $rate->rate_area_id, 'specificity' => $specificity, 'from' => Decimal::trim($rate->weight_from),
                        'to' => $rate->weight_to === null ? null : Decimal::trim($rate->weight_to), 'base' => Decimal::trim($rate->base), 'per_unit' => Decimal::trim($rate->per_unit)];
                }
            }
            $rate = ManualRates::select($rates, $weights['chargeable'], $scale);
            if ($rate === null) {
                return $unavailable('no_rate');
            }
            $amount = $allFree ? Decimal::round('0', $scale) : $rate['amount'];
            $details['rate'] = [...$rate, 'area_name' => $areas[$rate['area_id']]->name ?? ''];
        } else {
            $restricted = DB::table('shipping_method_areas')->where('method_id', $method->id)->pluck('rate_area_id')->map(strval(...))->all();
            if ($restricted !== [] && array_filter($restricted, fn (string $id): bool => isset($areas[$id]) && $areas[$id]->specificity($address) !== null) === []) {
                return $unavailable('area_restricted');
            }
            $provider = $this->carriers->provider($method->provider);
            if (! $provider instanceof QuotesRates) {
                return $unavailable('provider_disabled');
            }
            $account = $this->carriers->usableAccount($provider, $method->account_id === null ? null : (string) $method->account_id);
            if ($account === null) {
                return $unavailable('account_incomplete');
            }
            $details += ['provider' => $provider->id(), 'provider_name' => $provider->name(), 'account_id' => $account->id, 'account_name' => $account->name];
            if ($allFree) {
                $amount = Decimal::round('0', $scale);
            } else {
                $quote = $this->quote($method, $provider, $account, $order, $weights, $grams);
                if (! $quote->available) {
                    return $unavailable($quote->reason ?? 'carrier_unavailable', $quote->message);
                }
                if ($quote->currency !== $order['currency']) {
                    return $unavailable('currency');
                }
                $base = $method->carrier_amount === 'net' ? $quote->net : $quote->gross;
                $amount = Decimal::round(Decimal::add($base, Decimal::trim($method->carrier_surcharge)), $scale);
                $details['carrier'] = ['net' => $quote->net, 'tax' => $quote->tax, 'gross' => $quote->gross, 'currency' => $quote->currency, 'basis' => $method->carrier_amount,
                    'surcharge' => Decimal::trim($method->carrier_surcharge), 'details' => $quote->details];
            }
        }
        $amount = Decimal::round(Decimal::add($amount, $details['freight']), $scale);

        return [
            'available' => true, 'reason' => null, 'message' => null, 'amount' => $amount,
            'tax_ids' => DB::table('shipping_method_taxes')->where('method_id', $method->id)->orderBy('tax_id')->pluck('tax_id')->map(strval(...))->all(),
            'details' => [...$details, 'amount' => $amount, 'promotion_discountable' => Decimal::subtract($amount, Decimal::add($details['freight'], $details['carrier']['surcharge'] ?? '0')), 'calculated_at' => now()->toIso8601String()],
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array{physical: string, chargeable: string, chargeable_known: bool, chargeable_items: int, freight: string}  $weights
     */
    private function quote(object $method, QuotesRates&ShippingProvider $provider, CarrierAccount $account, array $order, array $weights, string $grams): CarrierQuote
    {
        $address = $order['address'] ?? [];
        $destination = new Destination(strtoupper((string) $address['country_code']), $address['region_code'] ?? null, $address['postal_code'] ?? null, $address['city'] ?? null, $address['line1'] ?? null, $address['line2'] ?? null);
        $request = new RateRequest($destination, $weights['chargeable_known'] ? ShippingWeights::toKilograms($weights['chargeable'], $grams) : null, $weights['chargeable_items'],
            Decimal::trim($order['subtotal']), $order['currency'], $order['decimals'], $order['payment_method_code']);
        $settingsVersion = (int) DB::table('shipping_carrier_settings')->where('provider', $provider->id())->value('lock_version');
        $accountVersion = (int) DB::table('shipping_carrier_accounts')->where('id', $account->id)->value('lock_version');
        $key = 'cms-shipping-quote:'.hash('sha256', json_encode([$method->id, $method->lock_version, $account->id, $accountVersion, $settingsVersion, (array) $destination, $request->itemsWeightKg, $request->itemCount, $request->orderValue, $request->currency, $request->paymentMethodCode], JSON_THROW_ON_ERROR));
        $cached = Cache::get($key);
        if ($cached instanceof CarrierQuote) {
            return $cached;
        }
        try {
            $quote = $provider->quote($request, $account, $this->carriers->settings($provider));
        } catch (ShippingProviderRejected $e) {
            return CarrierQuote::unavailable('carrier_error', $e->getMessage());
        } catch (ShippingProviderUnavailable) {
            return CarrierQuote::unavailable('carrier_unavailable');
        }
        if ($quote->available) {
            Cache::put($key, $quote, now()->addMinutes(self::QUOTE_MINUTES));
        }

        return $quote;
    }

    /**
     * @param  list<array{product_id: ?string, quantity: int}>  $lines
     * @return list<array{weight: ?string, quantity: int, free_shipping: bool, freight: ?string}>
     */
    private function lines(array $lines): array
    {
        $ids = array_values(array_filter(array_map(fn (array $l): ?string => $l['product_id'], $lines)));
        $products = DB::table('products')->whereIn('id', $ids)->get(['id', 'weight', 'free_shipping', 'shipping_cost'])->keyBy(fn (object $p): string => (string) $p->id);

        return array_map(function (array $line) use ($products): array {
            $product = $line['product_id'] === null ? null : ($products[$line['product_id']] ?? null);

            return [
                'weight' => $product?->weight === null ? null : Decimal::trim((string) $product->weight), 'quantity' => (int) $line['quantity'],
                'free_shipping' => (bool) ($product->free_shipping ?? false), 'freight' => $product?->shipping_cost === null ? null : Decimal::trim((string) $product->shipping_cost),
            ];
        }, $lines);
    }
}
