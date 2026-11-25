<?php

namespace App\Order\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\CouponRedemptions;
use App\Marketing\Infrastructure\PromotionApplications;
use App\Notification\Infrastructure\AdminNotifications;
use App\Order\Application\Contract\OrderPaymentMethods;
use App\Order\Application\Contract\OrderShipping;
use App\Order\Domain\Service\OrderStockRules;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Shared\Domain\Service\Decimal;
use App\Shipping\Domain\Service\ShipmentValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single place orders are created, edited and moved between statuses. Every path (admin editor, list quick action,
 * detail page, internal callers, a future checkout) goes through here, so stock, status history and the customer
 * email behave identically. Each operation is one transaction; emails are delivered after it commits.
 */
final class OrderAdministration
{
    public const SOURCES = ['admin', 'system', 'checkout', 'customer'];

    public function __construct(
        private OrderInput $input,
        private OrderPricing $pricing,
        private OrderStock $stock,
        private OrderNotifier $notifier,
        private OrderStockRules $rules,
        private OrderPaymentMethods $paymentMethods,
        private OrderShipping $shipping,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  ?string  $ip  The customer's request IP from OrderOrigin; staff-created orders pass null.
     */
    public function create(?string $actor, array $input, string $source = 'admin', ?string $ip = null): string
    {
        return $this->audited($actor, function () use ($actor, $input, $source, $ip): string {
            $this->authorize($actor, 'orders.create');
            $data = $this->input->validate($input, true);
            $submission = $source === 'checkout' && ! empty($data['submission_key']) ? $this->submission($data['customer_id'] ?? null, $data['email'], $data['submission_key']) : null;
            $fingerprint = $submission === null ? null : hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($submission !== null && ($previous = $this->submitted($submission, $fingerprint)) !== null) {
                return $previous;
            }
            $currency = app(CurrencyRepository::class)->currentPrimary();
            // Shipping may ask a carrier: it is calculated before the transaction, never while rows are locked.
            $shipping = $this->shippingCharge($data, [], null, $currency->code, $currency->decimals);

            return DB::transaction(function () use ($actor, $data, $source, $ip, $currency, $shipping, $submission, $fingerprint): string {
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                if ($submission !== null && ($previous = $this->submitted($submission, $fingerprint)) !== null) {
                    return $previous;
                }
                $currentCurrency = app(CurrencyRepository::class)->currentPrimary();
                if ($currentCurrency->code !== $currency->code || $currentCurrency->decimals !== $currency->decimals) {
                    throw ValidationException::withMessages(['items' => __('orders.currency_changed', ['currency' => $currency->code])]);
                }
                $status = DB::table('order_statuses')->where('id', $data['status_id'])->where('is_active', true)->first()
                    ?? throw ValidationException::withMessages(['status_id' => __('orders.status_unavailable')]);
                $exempt = $this->exempt($data['customer_id'] ?? null);
                $quote = $this->pricing->quote($data['items'], [], ['billing' => $data['billing'], 'shipping' => $data['shipping']], $exempt, $data['locale'], $currency->decimals, isset($data['customer_id']) ? (string) $data['customer_id'] : null, true, $this->shippingInput($shipping), $data['coupon_code'] ?? null, $data['email'], paymentFee: $this->paymentFee($data['payment_method_id'] ?? null));
                $payment = $this->paymentMethod($data['payment_method_id'] ?? null, $quote['total'], $currency->code, $currency->decimals, $data['locale']);
                $this->assertShippingPayment($shipping['id'] ?? null, $payment['payment_method_id']);
                $number = (string) DB::selectOne("SELECT nextval('order_number_seq') AS n")->n;
                $id = (string) DB::table('orders')->insertGetId([
                    'submission_key' => $submission, 'submission_fingerprint' => $fingerprint, 'order_number' => $number, 'customer_id' => $data['customer_id'] ?? null, 'status_id' => $status->id, 'locale_id' => $this->localeId($data['locale']),
                    ...$this->contact($data), 'ip_address' => $ip === null ? null : OrderOrigin::valid($ip),
                    'currency_code' => $currency->code, 'currency_decimals' => $currency->decimals, ...$this->amounts($quote), 'tax_exempt' => $exempt,
                    'customer_note' => $data['customer_note'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'invoice_requested' => (bool) ($data['invoice_requested'] ?? false), ...$payment, ...$this->shippingColumns($shipping, $quote),
                    'stock_held' => false, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->writeAddresses($id, $data['billing'], $data['shipping']);
                $targets = [];
                foreach ($quote['lines'] as $position => $line) {
                    $itemId = (string) DB::table('order_items')->insertGetId([...$this->itemRow($line), ...$this->brandSnapshot($line['product_id']), 'order_id' => $id, 'position' => $position + 1, 'deducted_quantity' => 0]);
                    app(ProductSales::class)->reserve($itemId, $line['sale_id'] ?? null, $status->stock_action === 'restore' ? 0 : $line['quantity']);
                    $targets[$itemId] = $this->rules->target($status->stock_action, false, $line['quantity']);
                }
                $this->writeTaxes($id, $quote['taxes']);
                app(PromotionApplications::class)->save($id, $quote);
                $this->stock->apply($targets);
                DB::table('orders')->where('id', $id)->update(['stock_held' => $this->rules->holds($status->stock_action, false)]);
                app(CouponRedemptions::class)->sync($id, $status->stock_action === 'restore');
                app(PromotionApplications::class)->release($id, $status->stock_action === 'restore');
                $this->transition($id, null, (string) $status->id, $actor, $source, null, $data['email']);
                if ($source === 'checkout') {
                    app(AdminNotifications::class)->orderPlaced($id, $number);
                }

                return $id;
            });
        });
    }

    /** The order a checkout submission already placed, so a retried request can answer with it instead of placing again. */
    public function placed(?string $customerId, string $email, string $submissionKey): ?string
    {
        $id = DB::table('orders')->where('submission_key', $this->submission($customerId, $email, $submissionKey))->value('id');

        return $id === null ? null : (string) $id;
    }

    private function submission(mixed $customerId, string $email, string $key): string
    {
        return hash('sha256', ($customerId ?? strtolower(trim($email))).'|'.$key);
    }

    private function submitted(string $key, string $fingerprint): ?string
    {
        $order = DB::table('orders')->where('submission_key', $key)->first(['id', 'submission_fingerprint']);
        if ($order !== null && ! hash_equals($order->submission_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['submission_key' => __('promotions.submission_changed')]);
        }

        return $order === null ? null : (string) $order->id;
    }

    /**
     * Edits contact, addresses, notes, lines, payment and shipping. Totals are recalculated only when lines, the
     * tax-relevant address, the customer or the shipping (method, charge, delivery address, payment method) change; a
     * re-save without such changes keeps every stored amount and moves no stock.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(string $actor, string $id, array $input): void
    {
        $this->audited($actor, function () use ($actor, $id, $input): void {
            $this->authorize($actor, 'orders.update');
            // The order's saved regions stay valid unchanged, even if Geography no longer lists them.
            $saved = DB::table('order_addresses')->where('order_id', $id)->whereNotNull('region_code')->get(['country_code', 'region_code'])->map(fn (object $a): string => $a->country_code.':'.$a->region_code)->all();
            $data = $this->input->validate($input, false, $saved);
            $stored = DB::table('orders')->where('id', $id)->first() ?? throw ValidationException::withMessages(['version' => __('orders.stale')]);
            // A carrier quote is requested before the transaction; inside it the order version is checked again.
            $shipping = $this->shippingUpdate($data, $stored);

            DB::transaction(function () use ($actor, $id, $data, $shipping): void {
                $order = $this->locked($id, (int) $data['version']);
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                $couponCode = array_key_exists('coupon_code', $data) ? $data['coupon_code'] : $order->coupon_code;
                $couponChanged = (string) $couponCode !== (string) $order->coupon_code || ($order->coupon_id !== null && ((string) ($data['customer_id'] ?? '') !== (string) $order->customer_id || $data['email'] !== $order->email));
                $status = DB::table('order_statuses')->where('id', $order->status_id)->first();
                $items = DB::table('order_items')->where('order_id', $id)->orderBy('position')->get()->keyBy(fn (object $i): string => (string) $i->id);
                $addresses = DB::table('order_addresses')->where('order_id', $id)->get()->keyBy('type');
                $exempt = $this->exempt($data['customer_id'] ?? null);
                // A new payment method brings its own fee; otherwise the order keeps the fee it was charged.
                $paymentChanged = array_key_exists('payment_method_id', $data) && (string) ($data['payment_method_id'] ?? '') !== (string) ($order->payment_method_id ?? '');
                $fee = $paymentChanged ? $this->paymentFee($data['payment_method_id']) : Decimal::trim((string) $order->payment_fee);
                $feeChanged = Decimal::compare($fee ?? '0', Decimal::trim((string) $order->payment_fee)) !== 0;
                if ($couponChanged || $feeChanged || $shipping !== null || $this->contentsChanged($data, $items->all(), $addresses->all()) || $exempt !== (bool) $order->tax_exempt) {
                    if ($status->is_final && ! filter_var($data['confirm_final'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        throw ValidationException::withMessages(['confirm_final' => __('orders.final_confirmation_required')]);
                    }
                    $currency = app(CurrencyRepository::class)->currentPrimary();
                    if ($currency->code !== $order->currency_code || $currency->decimals !== (int) $order->currency_decimals) {
                        throw ValidationException::withMessages(['items' => __('orders.currency_changed', ['currency' => $order->currency_code])]);
                    }
                    $locale = (string) DB::table('locales')->where('id', $order->locale_id)->value('code');
                    app(ProductSales::class)->lockProducts(array_values(array_filter([...$items->pluck('product_id')->map(strval(...))->all(), ...array_map(fn (array $line): string => (string) ($line['product_id'] ?? ''), $data['items'])])));
                    $kept = array_filter(array_column($data['items'], 'item_id'));
                    DB::table('product_sale_allocations')->whereIn('item_id', array_diff(array_keys($items->all()), $kept))->delete();
                    $charge = $shipping === null ? $this->storedShipping($order) : $this->shippingInput($shipping);
                    $quote = $this->pricing->quote($data['items'], $items->all(), ['billing' => $data['billing'], 'shipping' => $data['shipping']], $exempt, $locale, $currency->decimals, isset($data['customer_id']) ? (string) $data['customer_id'] : null, true, $charge, $couponCode, $data['email'], $id, $fee);
                    $this->replaceLines($id, $quote['lines'], $items->all(), $status->stock_action, (bool) $order->stock_held);
                    $this->writeTaxes($id, $quote['taxes']);
                    app(PromotionApplications::class)->save($id, $quote);
                    DB::table('orders')->where('id', $id)->update([...$this->amounts($quote), 'tax_exempt' => $exempt, ...($shipping === null ? $this->refreshedShippingTax($order, $quote) : $this->shippingColumns($shipping, $quote))]);
                }
                $this->writeAddresses($id, $data['billing'], $data['shipping'], $addresses->all());
                if ($paymentChanged) {
                    // Recorded or in-progress payments belong to the method they were made with.
                    if ($this->paymentMethods->locked($id)) {
                        throw ValidationException::withMessages(['payment_method_id' => __('orders.payment_method_locked')]);
                    }
                    $total = (string) DB::table('orders')->where('id', $id)->value('total');
                    DB::table('orders')->where('id', $id)->update($this->paymentMethod($data['payment_method_id'], $total, $order->currency_code, (int) $order->currency_decimals, $data['locale']));
                }
                $current = DB::table('orders')->where('id', $id)->first(['shipping_method_id', 'payment_method_id']);
                $this->assertShippingPayment($current->shipping_method_id === null ? null : (string) $current->shipping_method_id, $current->payment_method_id === null ? null : (string) $current->payment_method_id);
                DB::table('orders')->where('id', $id)->update([
                    'customer_id' => $data['customer_id'] ?? null, 'locale_id' => $this->localeId($data['locale']), ...$this->contact($data),
                    'customer_note' => $data['customer_note'] ?? null, 'internal_note' => $data['internal_note'] ?? null,
                    'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now(),
                ]);
                app(CouponRedemptions::class)->sync($id, $status->stock_action === 'restore');
                app(PromotionApplications::class)->release($id, $status->stock_action === 'restore');
            });
        });
    }

    /**
     * The shipping charge of a new or edited order: the method's server-calculated charge, or a staff-entered amount
     * (which also covers a carrier that cannot quote right now). Null when no method is chosen.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, object>  $existing
     * @return array{id: string, name: string, amount: string, tax_ids: list<string>, details: array<string, mixed>}|null
     */
    private function shippingCharge(array $data, array $existing, ?object $order, string $currency, int $decimals): ?array
    {
        $methodId = isset($data['shipping_method_id']) && $data['shipping_method_id'] !== '' ? (string) $data['shipping_method_id'] : null;
        $override = isset($data['shipping_amount']) && $data['shipping_amount'] !== '' ? Decimal::round((string) $data['shipping_amount'], $decimals) : null;
        if ($methodId === null) {
            if ($override !== null) {
                throw ValidationException::withMessages(['shipping_method_id' => __('orders.shipping_method_required')]);
            }

            return null;
        }
        $customer = isset($data['customer_id']) ? (string) $data['customer_id'] : null;
        // With the order's coupon, so the shipment value matches the one checkout measured (ShipmentValue).
        $coupon = array_key_exists('coupon_code', $data) ? $data['coupon_code'] : ($order->coupon_code ?? null);
        $preview = $this->pricing->quote($data['items'], $existing, ['billing' => $data['billing'], 'shipping' => $data['shipping']], $this->exempt($customer), $data['locale'], $decimals, $customer,
            couponCode: $coupon === null || $coupon === '' ? null : (string) $coupon, email: (string) ($data['email'] ?? ''), orderId: $order === null ? null : (string) $order->id);
        $lines = array_map(fn (array $line): array => [
            'product_id' => $line['item_id'] !== null ? (($existing[$line['item_id']]->product_id ?? null) === null ? null : (string) $existing[$line['item_id']]->product_id) : $line['product_id'],
            'quantity' => (int) $line['quantity'],
        ], $data['items']);
        $payment = array_key_exists('payment_method_id', $data) ? $data['payment_method_id'] : ($order->payment_method_id ?? null);
        $quote = $this->shipping->quote($methodId, [
            'lines' => $lines, 'address' => $data['shipping'] ?? $data['billing'], 'currency' => $currency, 'decimals' => $decimals, 'customer_id' => $customer,
            'payment_method_id' => $payment === null ? null : (string) $payment, 'subtotal' => $preview['subtotal'], 'shipment_value' => ShipmentValue::of($preview['lines']),
        ], $data['locale'], $order !== null && (string) $order->shipping_method_id === $methodId);
        if ($quote['name'] === '') {
            throw ValidationException::withMessages(['shipping_method_id' => __('orders.shipping_method_unavailable', ['reason' => (string) $quote['message']])]);
        }
        if (! $quote['available'] && $override === null) {
            throw ValidationException::withMessages(['shipping_method_id' => __('orders.shipping_method_unavailable', ['reason' => (string) $quote['message']])]);
        }
        $amount = $override ?? $quote['amount'];

        return ['id' => $methodId, 'name' => $quote['name'], 'amount' => $amount, 'tax_ids' => $quote['tax_ids'], 'details' => [
            ...($quote['available'] ? $quote['details'] : []), 'amount' => $amount, 'tax_ids' => $quote['tax_ids'], 'overridden' => $override !== null,
            'calculated_amount' => $quote['available'] ? $quote['amount'] : null, 'unavailable_reason' => $quote['available'] ? null : $quote['reason'],
        ]];
    }

    /**
     * Whether an edit changes the shipping charge, and the new charge. Null keeps the stored charge and snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: ?string, name: ?string, amount: string, tax_ids: list<string>, details: ?array<string, mixed>}|null
     */
    private function shippingUpdate(array $data, object $order): ?array
    {
        $details = json_decode((string) ($order->shipping_details ?? 'null'), true) ?: [];
        $storedMethod = $order->shipping_method_id === null ? null : (string) $order->shipping_method_id;
        $method = array_key_exists('shipping_method_id', $data) ? ($data['shipping_method_id'] === null ? null : (string) $data['shipping_method_id']) : $storedMethod;
        $storedOverride = ($details['overridden'] ?? false) ? (string) $details['amount'] : null;
        $override = array_key_exists('shipping_amount', $data) ? (($data['shipping_amount'] ?? '') === '' ? null : (string) $data['shipping_amount']) : $storedOverride;
        $items = DB::table('order_items')->where('order_id', $order->id)->get()->keyBy(fn (object $i): string => (string) $i->id);
        $addresses = DB::table('order_addresses')->where('order_id', $order->id)->get()->keyBy('type');
        $delivery = $data['shipping'] ?? $data['billing'];
        $storedDelivery = $addresses['shipping'] ?? $addresses['billing'] ?? null;
        $addressChanged = $storedDelivery === null || collect(['country_code', 'region_code', 'postal_code', 'city', 'line1'])
            ->contains(fn (string $f): bool => (string) ($delivery[$f] ?? '') !== (string) ($storedDelivery->{$f} ?? ''));
        $paymentChanged = array_key_exists('payment_method_id', $data) && (string) ($data['payment_method_id'] ?? '') !== (string) ($order->payment_method_id ?? '');
        $overrideChanged = ($override === null) !== ($storedOverride === null) || ($override !== null && Decimal::compare($override, (string) $storedOverride) !== 0);
        $changed = $method !== $storedMethod || $overrideChanged || filter_var($data['recalculate_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($method !== null && ($addressChanged || $paymentChanged || $this->contentsChanged($data, $items->all(), $addresses->all())));
        if (! $changed) {
            return null;
        }
        if ($method === null) {
            if ($override !== null) {
                throw ValidationException::withMessages(['shipping_method_id' => __('orders.shipping_method_required')]);
            }

            return ['id' => null, 'name' => null, 'amount' => '0', 'tax_ids' => [], 'details' => null];
        }

        return $this->shippingCharge([...$data, 'shipping_method_id' => $method, 'shipping_amount' => $override], $items->all(), $order, $order->currency_code, (int) $order->currency_decimals);
    }

    /**
     * @param  array{amount: string, tax_ids: list<string>, id: ?string, details?: ?array<string, mixed>}|null  $shipping
     * @return array{amount: string, tax_ids: list<string>, id?: ?string, available?: bool, discountable?: string}|null
     */
    private function shippingInput(?array $shipping): ?array
    {
        return $shipping === null ? null : ['amount' => $shipping['amount'], 'tax_ids' => $shipping['tax_ids'], 'id' => $shipping['id'], 'available' => ($shipping['details']['unavailable_reason'] ?? null) === null, 'discountable' => $shipping['details']['promotion_discountable'] ?? $shipping['amount']];
    }

    /** @return array{amount: string, tax_ids: list<string>, id?: ?string, available?: bool, discountable?: string}|null */
    private function storedShipping(object $order): ?array
    {
        $details = json_decode((string) ($order->shipping_details ?? 'null'), true) ?: [];

        return $order->shipping_method_id === null ? null : ['amount' => Decimal::trim((string) ($details['amount'] ?? $order->shipping_total)), 'tax_ids' => array_map(strval(...), $details['tax_ids'] ?? []), 'id' => (string) $order->shipping_method_id, 'available' => ($details['unavailable_reason'] ?? null) === null, 'discountable' => $details['promotion_discountable'] ?? Decimal::trim((string) ($details['amount'] ?? $order->shipping_total))];
    }

    /**
     * @param  array<string, mixed>|null  $shipping
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function shippingColumns(?array $shipping, array $quote): array
    {
        if ($shipping === null || $shipping['id'] === null) {
            return ['shipping_method_id' => null, 'shipping_method_name' => null, 'shipping_details' => null];
        }

        return ['shipping_method_id' => $shipping['id'], 'shipping_method_name' => $shipping['name'],
            'shipping_details' => json_encode([...($shipping['details'] ?? []), 'tax' => $quote['shipping_tax']], JSON_THROW_ON_ERROR)];
    }

    /**
     * Keeps the stored charge and snapshot, refreshing only its tax breakdown after a recalculation.
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function refreshedShippingTax(object $order, array $quote): array
    {
        $details = json_decode((string) ($order->shipping_details ?? 'null'), true);

        return is_array($details) ? ['shipping_details' => json_encode([...$details, 'tax' => $quote['shipping_tax']], JSON_THROW_ON_ERROR)] : [];
    }

    /** A shipping method may restrict which payment methods it is combined with. */
    private function assertShippingPayment(?string $shippingMethodId, ?string $paymentMethodId): void
    {
        if ($shippingMethodId !== null && ! $this->shipping->allowsPayment($shippingMethodId, $paymentMethodId)) {
            throw ValidationException::withMessages(['payment_method_id' => __('orders.payment_not_for_shipping')]);
        }
    }

    /** @param list<array{id: string, version: int}> $orders */
    public function bulkStatus(string $actor, array $orders, string $statusId): int
    {
        $this->authorize($actor, 'orders.status');
        Validator::make(['orders' => $orders, 'status_id' => $statusId], [
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*' => ['required', 'array:id,version'],
            'orders.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'orders.*.version' => ['required', 'integer', 'min:1'],
            'status_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $orders, $statusId): int {
            // Lock the entire selection in ID order before any transition takes the shared stock lock.
            $locked = DB::table('orders')->whereIn('id', array_column($orders, 'id'))->orderBy('id')->lockForUpdate()->get();
            if ($locked->count() !== count($orders)) {
                throw ValidationException::withMessages(['orders' => __('orders.stale')]);
            }
            if (! DB::table('order_statuses')->where('id', $statusId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['status_id' => __('orders.status_unavailable')]);
            }
            $changed = 0;
            foreach ($orders as $order) {
                $changed += (int) $this->changeStatus($actor, (string) $order['id'], (int) $order['version'], $statusId);
            }

            return $changed;
        });
    }

    /**
     * Moves an order to another active status: adjusts stock, appends history and emails the customer once.
     * The same status is a no-op (no history, no email). Returns whether the status changed.
     */
    public function changeStatus(?string $actor, string $id, ?int $version, string $statusId, ?string $note = null, string $source = 'admin'): bool
    {
        return $this->audited($actor, fn (): bool => DB::transaction(function () use ($actor, $id, $version, $statusId, $note, $source): bool {
            $this->authorize($actor, 'orders.status');
            $order = $this->locked($id, $version);
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $note = $note === null ? null : (trim($note) === '' ? null : mb_substr(trim($note), 0, 1000));
            if ((string) $order->status_id === $statusId) {
                return false;
            }
            $status = DB::table('order_statuses')->where('id', $statusId)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['status_id' => __('orders.status_unavailable')]);
            $held = (bool) $order->stock_held;
            $targets = DB::table('order_items')->where('order_id', $id)->pluck('quantity', 'id')->map(fn ($q): int => $this->rules->target($status->stock_action, $held, (int) $q))->all();
            $this->stock->apply(array_combine(array_map(strval(...), array_keys($targets)), $targets));
            app(CouponRedemptions::class)->sync($id, $status->stock_action === 'restore');
            app(PromotionApplications::class)->release($id, $status->stock_action === 'restore');
            app(ProductSales::class)->orderStatus($id, $status->stock_action === 'restore');
            DB::table('orders')->where('id', $id)->update([
                'status_id' => $status->id, 'stock_held' => $this->rules->holds($status->stock_action, $held),
                'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now(),
            ]);
            $this->transition($id, (string) $order->status_id, (string) $status->id, $actor, $source, $note, $order->email);

            return true;
        }));
    }

    public function comment(string $actor, string $id, string $visibility, string $body): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $id, $visibility, $body): void {
            $this->authorize($actor, 'orders.update');
            $body = trim($body);
            if (! in_array($visibility, ['internal', 'customer'], true) || $body === '' || mb_strlen($body) > 5000) {
                throw ValidationException::withMessages(['body' => __('orders.comment_invalid')]);
            }
            DB::table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('order_comments')->insert(['order_id' => $id, 'author_id' => $actor, 'visibility' => $visibility, 'body' => $body, 'created_at' => now()]);
        }));
    }

    private function transition(string $id, ?string $from, string $to, ?string $actor, string $source, ?string $note, string $recipient): void
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown order change source.');
        }
        $history = (string) DB::table('order_status_history')->insertGetId(['order_id' => $id, 'from_status_id' => $from, 'to_status_id' => $to, 'actor_id' => $actor, 'source' => $source, 'note' => $note, 'created_at' => now()]);
        $this->notifier->queue($id, $history, $to, $recipient);
        // The invoice goes out once per order: the first time it enters a status marked to send it.
        if (DB::table('order_statuses')->where('id', $to)->value('sends_invoice') && ! DB::table('order_notifications')->where('order_id', $id)->where('kind', 'invoice')->exists()) {
            $this->notifier->queue($id, $history, $to, $recipient, 'invoice');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, object>  $existing
     */
    private function replaceLines(string $id, array $lines, array $existing, string $action, bool $held): void
    {
        $kept = array_filter(array_map(fn (array $line): ?string => $line['item_id'], $lines));
        $removed = array_diff(array_keys($existing), $kept);
        // Removed lines first give back what they took, then disappear.
        $this->stock->apply(array_fill_keys(array_map(strval(...), $removed), 0));
        DB::table('order_items')->whereIn('id', $removed)->delete();
        // Lines that now hold fewer units give stock back before their quantity shrinks (deducted <= quantity).
        $before = [];
        $after = [];
        foreach ($lines as $line) {
            if ($line['item_id'] !== null && $line['quantity'] !== (int) $existing[$line['item_id']]->quantity) {
                $target = $this->rules->target($action, $held, $line['quantity']);
                if ($target < (int) $existing[$line['item_id']]->deducted_quantity) {
                    $before[$line['item_id']] = $target;
                } else {
                    $after[$line['item_id']] = $target;
                }
            }
        }
        $this->stock->apply($before);
        // Move kept lines out of the way so renumbering never collides with the unique position.
        DB::table('order_items')->where('order_id', $id)->update(['position' => DB::raw('position + 1000000')]);
        foreach ($lines as $position => $line) {
            $row = [...$this->itemRow($line), 'position' => $position + 1];
            if ($line['item_id'] === null) {
                $itemId = (string) DB::table('order_items')->insertGetId([...$row, ...$this->brandSnapshot($line['product_id']), 'order_id' => $id, 'deducted_quantity' => 0]);
                app(ProductSales::class)->reserve($itemId, $line['sale_id'] ?? null, $action === 'restore' ? 0 : $line['quantity']);
                $after[$itemId] = $this->rules->target($action, $held, $line['quantity']);

                continue;
            }
            DB::table('order_items')->where('id', $line['item_id'])->update($row);
            app(ProductSales::class)->reserve($line['item_id'], $line['sale_id'] ?? null, $action === 'restore' ? 0 : $line['quantity']);
        }
        $this->stock->apply($after);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, object>  $items
     * @param  array<string, object>  $addresses
     */
    private function contentsChanged(array $data, array $items, array $addresses): bool
    {
        if (count($data['items']) !== count($items)) {
            return true;
        }
        foreach ($data['items'] as $line) {
            $item = $line['item_id'] === null ? null : ($items[$line['item_id']] ?? null);
            if ($item === null || $line['quantity'] !== (int) $item->quantity
                || ($line['unit_price'] !== null && Decimal::compare($line['unit_price'], Decimal::trim($item->unit_price)) !== 0)) {
                return true;
            }
        }
        foreach (['billing' => $data['billing'], 'shipping' => $data['shipping']] as $type => $address) {
            $stored = $addresses[$type] ?? null;
            if (($address === null) !== ($stored === null) || ($stored !== null && ($stored->country_code !== $address['country_code'] || $stored->region_code !== ($address['region_code'] ?? null)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contact(array $data): array
    {
        return ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null];
    }

    /** The payment method's fee (e.g. cash on delivery) for a new charge, if it has one. */
    private function paymentFee(mixed $methodId): ?string
    {
        if ($methodId === null || $methodId === '') {
            return null;
        }
        $fee = DB::table('payment_methods')->where('id', (int) $methodId)->value('fee');

        return $fee === null ? null : Decimal::trim((string) $fee);
    }

    /**
     * The chosen payment method and its name at this moment; only methods currently offered for this total qualify.
     *
     * @return array{payment_method_id: ?string, payment_method_name: ?string}
     */
    private function paymentMethod(mixed $methodId, string $total, string $currency, int $decimals, string $locale): array
    {
        if ($methodId === null || $methodId === '') {
            return ['payment_method_id' => null, 'payment_method_name' => null];
        }
        $name = $this->paymentMethods->assignable((string) $methodId, $total, $currency, $decimals, $locale)
            ?? throw ValidationException::withMessages(['payment_method_id' => __('orders.payment_method_unavailable')]);

        return ['payment_method_id' => (string) $methodId, 'payment_method_name' => $name];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function amounts(array $quote): array
    {
        return ['coupon_discount' => $quote['coupon_discount'], 'coupon_shipping_discount' => $quote['coupon_shipping_discount'], 'coupon_id' => $quote['coupon_id'], 'coupon_code' => $quote['coupon_code'], 'shipping_discount' => $quote['shipping_discount'], 'subtotal' => $quote['subtotal'], 'discount_total' => $quote['discount_total'], 'tax_total' => $quote['tax_total'], 'tax_included' => $quote['tax_included'], 'shipping_total' => $quote['shipping_total'], 'payment_fee' => $quote['payment_fee'], 'total' => $quote['total'], 'tax_method' => $quote['method']];
    }

    /** The brand a new line is sold under (kept like its name: later brand changes never rewrite brand sales).
     * @return array{brand_id: int|string|null, brand_name: ?string} */
    private function brandSnapshot(int|string|null $productId): array
    {
        $brand = $productId === null ? null : DB::table('products')->where('id', $productId)->value('brand_id');
        if ($brand === null) {
            return ['brand_id' => null, 'brand_name' => null];
        }
        $name = DB::table('brand_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.brand_id', $brand)->whereRaw("btrim(t.name) <> ''")
            ->orderByDesc('l.is_default')->orderBy('l.id')->value('t.name');

        return ['brand_id' => $brand, 'brand_name' => $name === null ? null : mb_substr((string) $name, 0, 200)];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function itemRow(array $line): array
    {
        return ['product_id' => $line['product_id'], 'name' => $line['name'], 'sku' => $line['sku'], 'variation' => $line['variation'], 'quantity' => $line['quantity'],
            'catalog_price' => $line['catalog_price'], 'unit_price' => $line['unit_price'], 'price_overridden' => $line['price_overridden'],
            'pricing_basis' => json_encode($line['pricing_basis'], JSON_THROW_ON_ERROR), 'subtotal' => $line['subtotal'], 'discount_total' => $line['discount_total'], 'tax_total' => $line['tax_total'], 'tax_included' => $line['tax_included'], 'total' => $line['total']];
    }

    /**
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     * @param  array<string,object>  $previous
     */
    private function writeAddresses(string $id, array $billing, ?array $shipping, array $previous = []): void
    {
        $fields = ['first_name', 'last_name', 'company', 'line1', 'line2', 'city', 'postal_code', 'country_code', 'region_code', 'phone', 'floor', 'bell_name', 'notes'];
        foreach (['billing' => $billing, 'shipping' => $shipping] as $type => $address) {
            if ($address === null) {
                DB::table('order_addresses')->where('order_id', $id)->where('type', $type)->delete();

                continue;
            }
            $sameLocation = isset($previous[$type]) && ! collect(['line1', 'line2', 'city', 'postal_code', 'country_code', 'region_code'])->contains(fn (string $field): bool => (string) ($previous[$type]->{$field} ?? '') !== (string) ($address[$field] ?? ''));
            $row = [];
            foreach ($fields as $field) {
                if (in_array($field, ['floor', 'bell_name', 'notes'], true) && ! array_key_exists($field, $address) && $sameLocation) {
                    $row[$field] = $previous[$type]->{$field};

                    continue;
                }
                $row[$field] = $address[$field] ?? null;
            }
            DB::table('order_addresses')->updateOrInsert(['order_id' => $id, 'type' => $type], $row);
        }
    }

    /** @param list<array<string, mixed>> $taxes */
    private function writeTaxes(string $id, array $taxes): void
    {
        DB::table('order_taxes')->where('order_id', $id)->delete();
        foreach ($taxes as $tax) {
            DB::table('order_taxes')->insert([...$tax, 'order_id' => $id]);
        }
    }

    private function exempt(mixed $customerId): bool
    {
        return $customerId !== null && (bool) DB::table('customers')->where('id', $customerId)->value('tax_exempt');
    }

    private function localeId(string $code): int
    {
        return (int) app(LocaleCatalog::class)->active()->firstWhere('code', $code)->id;
    }

    private function locked(string $id, ?int $version): object
    {
        $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('orders.stale')]);
        if ($version !== null && (int) $order->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('orders.stale')]);
        }

        return $order;
    }

    private function authorize(?string $actor, string $ability): void
    {
        if ($actor === null) {
            return; // Internal callers (a future checkout, the local demo) are trusted code paths.
        }
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(?string $actor, callable $operation): mixed
    {
        return $actor === null ? $operation() : app(AuditLogger::class)->operation('order', $actor, $operation);
    }
}
