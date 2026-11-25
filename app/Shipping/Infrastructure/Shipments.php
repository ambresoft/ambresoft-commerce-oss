<?php

namespace App\Shipping\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Domain\Service\PaymentRules;
use App\Settings\Infrastructure\CompanySettings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\Destination;
use App\Shipping\Application\Command\ShipmentDraft;
use App\Shipping\Application\Command\ShipmentReference;
use App\Shipping\Application\Command\ShippingDocument;
use App\Shipping\Application\Command\TrackingResult;
use App\Shipping\Application\Contract\CancelsShipments;
use App\Shipping\Application\Contract\CreatesShipments;
use App\Shipping\Application\Contract\MapsOrderStatuses;
use App\Shipping\Application\Contract\PrintsLabels;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Contract\ShippingProviderRejected;
use App\Shipping\Application\Contract\ShippingProviderUnavailable;
use App\Shipping\Application\Contract\TracksShipments;
use App\Shipping\Domain\Service\ShipmentTransitions;
use App\Shipping\Domain\Service\ShippingWeights;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Carrier shipments of orders (`orders.shipments`). Creating a shipment is not idempotent at carriers, so a request
 * first claims a local row (unique request key; one creation in progress per order) and the carrier is called outside
 * any transaction. A refused request is recorded as failed; an unknown outcome (timeout) stays "creating" until staff
 * check the carrier and mark it failed — it is never retried automatically. Follow-up operations use the stored
 * account. Delivered/returned states change the order status once, through OrderAdministration, and only while the
 * order is still in the status the shipment expects.
 */
final class Shipments
{
    public function __construct(private CarrierSettings $carriers) {}

    /**
     * The shipment form of an order's carrier: provider fields prefilled from the order snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function form(string $orderId): ?array
    {
        [$provider] = $this->carrierOf($orderId);
        if (! $provider instanceof CreatesShipments) {
            return null;
        }
        $defaults = $provider->shipmentDefaults($this->draft($orderId), $this->carriers->settings($provider));

        return ['fields' => $this->carriers->fieldValues()->editor($provider->shipmentFields(), $defaults), 'request_key' => (string) Str::uuid()];
    }

    /** @param array<string, mixed> $input */
    public function create(string $actor, string $orderId, string $requestKey, array $input, bool $additional): string
    {
        $this->authorize($actor);
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => __('shipping.stale')]);
        }
        $existing = DB::table('order_shipments')->where('request_key', $requestKey)->first();
        if ($existing !== null) {
            // The same request again (double click, refresh): never a second carrier call.
            return $this->replayed($existing);
        }
        [$provider, $account, $method] = $this->carrierOf($orderId);
        if (! $provider instanceof CreatesShipments || $account === null) {
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_unavailable')]);
        }
        if (! $additional && DB::table('order_shipments')->where('order_id', $orderId)->where('state', 'created')->exists()) {
            throw ValidationException::withMessages(['additional' => __('shipping.shipment_exists')]);
        }
        $fields = $provider->shipmentFields();
        $submitted = $this->carriers->fieldValues()->submit($fields, $provider->shipmentDefaults($this->draft($orderId), $this->carriers->settings($provider)), $input, [], '');
        $errors = $submitted['errors'];
        foreach ($fields as $field) {
            if ($field->required && trim($submitted['values'][$field->key] ?? '') === '') {
                $errors[$field->key] ??= __('shipping.field_required', ['field' => __($field->label)]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $order = DB::table('orders')->where('id', $orderId)->first();
        try {
            // In its own (nested) transaction so a refused claim never aborts a caller's transaction.
            $id = (string) DB::transaction(fn () => DB::table('order_shipments')->insertGetId([
                'order_id' => $orderId, 'shipping_method_id' => $method->id, 'provider' => $provider->id(), 'account_id' => $account->id, 'account_name' => $account->name,
                'state' => 'creating', 'request_key' => $requestKey, 'currency_code' => $order->currency_code, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            $again = DB::table('order_shipments')->where('request_key', $requestKey)->first();
            if ($again !== null) {
                return $this->replayed($again);
            }
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_in_progress')]);
        }
        try {
            $created = $provider->createShipment($this->draft($orderId), $submitted['values'], $account, $this->carriers->settings($provider));
        } catch (ShippingProviderRejected $e) {
            DB::table('order_shipments')->where('id', $id)->update(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_rejected', ['message' => $e->getMessage()])]);
        } catch (Throwable $e) {
            // Unavailable carrier, timeout or anything unexpected: the voucher may exist at the carrier.
            Log::warning('shipment_outcome_unknown', ['shipment_id' => $id, 'order_id' => $orderId, 'type' => $e::class]);
            DB::table('order_shipments')->where('id', $id)->update(['error' => __('shipping.shipment_outcome_unknown'), 'updated_at' => now()]);
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_outcome_unknown')]);
        }
        $this->audited($actor, fn () => DB::transaction(function () use ($id, $orderId, $created, $provider): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            DB::table('order_shipments')->where('id', $id)->update([
                'state' => 'created', 'tracking_number' => mb_substr($created->trackingNumber, 0, 60), 'extra_numbers' => json_encode($created->extraNumbers, JSON_THROW_ON_ERROR),
                'pickup_date' => $created->pickupDate, 'weight_kg' => $created->weightKg, 'packages' => $created->packages, 'cod_amount' => $created->codAmount,
                'details' => json_encode((object) $created->details, JSON_THROW_ON_ERROR), 'error' => null, 'expected_status_id' => $order->status_id, 'updated_at' => now(),
            ]);
            $this->transition($id, $provider, MapsOrderStatuses::SHIPMENT_CREATED, __('shipping.note_shipment_created', ['number' => $created->trackingNumber]));
        }));

        return $id;
    }

    /** The carrier's label of a shipment, from the account that created it. */
    public function label(string $actor, string $shipmentId, string $format): ShippingDocument
    {
        $this->authorize($actor);
        [$row, $provider, $account] = $this->stored($shipmentId);
        if (! $provider instanceof PrintsLabels || $row->state !== 'created') {
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_unavailable')]);
        }
        if (! array_key_exists($format, $provider->labelFormats())) {
            throw ValidationException::withMessages(['format' => __('shipping.choice_invalid')]);
        }
        $document = $this->call(fn () => $provider->label($this->reference($row), $account, $this->carriers->settings($provider), $format));
        DB::table('order_shipments')->where('id', $row->id)->whereNull('label_printed_at')->update(['label_printed_at' => now(), 'updated_at' => now()]);

        return $document;
    }

    public function cancel(string $actor, string $shipmentId): void
    {
        $this->authorize($actor);
        [$row, $provider, $account] = $this->stored($shipmentId);
        if (! $provider instanceof CancelsShipments || $row->state !== 'created') {
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_unavailable')]);
        }
        $this->call(fn () => $provider->cancelShipment($this->reference($row), $account, $this->carriers->settings($provider)));
        $this->audited($actor, fn () => DB::table('order_shipments')->where('id', $row->id)->where('state', 'created')
            ->update(['state' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor, 'updated_at' => now()]));
    }

    /** Reads the carrier's tracking and applies the configured status of a newly reported delivery or return once. */
    public function track(?string $actor, string $shipmentId): TrackingResult
    {
        if ($actor !== null) {
            $this->authorize($actor);
        }
        [$row, $provider, $account] = $this->stored($shipmentId);
        if (! $provider instanceof TracksShipments || $row->state !== 'created') {
            throw ValidationException::withMessages(['shipment' => __('shipping.shipment_unavailable')]);
        }
        $result = $this->call(fn () => $provider->track($this->reference($row), $account, $this->carriers->settings($provider)));
        $work = function () use ($row, $result, $provider): void {
            DB::table('orders')->where('id', $row->order_id)->lockForUpdate()->first();
            DB::table('order_shipments')->where('id', $row->id)->update([
                'tracking_state' => $result->state, 'tracking_code' => $result->code === null ? null : mb_substr($result->code, 0, 30), 'tracking_summary' => mb_substr($result->summary, 0, 500),
                'tracking_events' => json_encode(array_slice($result->events, 0, 100), JSON_THROW_ON_ERROR), 'tracking_checked_at' => now(),
                'delivered_at' => $result->deliveredAt ?? ($result->state === 'delivered' ? ($row->delivered_at ?? now()) : $row->delivered_at),
                'returned_at' => $result->returnedAt ?? ($result->state === 'returned' ? ($row->returned_at ?? now()) : $row->returned_at), 'updated_at' => now(),
            ]);
            if (in_array($result->state, [MapsOrderStatuses::DELIVERED, MapsOrderStatuses::RETURNED], true)) {
                $this->transition((string) $row->id, $provider, $result->state, __('shipping.note_tracking_'.$result->state, ['number' => (string) $row->tracking_number]));
            }
        };
        $actor === null ? DB::transaction($work) : $this->audited($actor, fn () => DB::transaction($work));

        return $result;
    }

    /** Staff confirmed (at the carrier) that an interrupted creation produced nothing: the claim is released. */
    public function markFailed(string $actor, string $shipmentId): void
    {
        $this->authorize($actor);
        $this->audited($actor, fn () => DB::table('order_shipments')->where('id', $shipmentId)->where('state', 'creating')
            ->update(['state' => 'failed', 'error' => __('shipping.shipment_marked_failed'), 'updated_at' => now()]));
    }

    /**
     * Applies the provider's status for an event once, through the order workflow (stock and emails as usual).
     */
    private function transition(string $shipmentId, ShippingProvider $provider, string $event, string $note): void
    {
        if (! $provider instanceof MapsOrderStatuses) {
            return;
        }
        $shipment = DB::table('order_shipments')->where('id', $shipmentId)->lockForUpdate()->first();
        $order = DB::table('orders')->where('id', $shipment->order_id)->first();
        $applied = json_decode((string) $shipment->applied_events, true) ?: [];
        $expected = $event === MapsOrderStatuses::SHIPMENT_CREATED ? null : ($shipment->expected_status_id === null ? null : (string) $shipment->expected_status_id);
        $target = ShipmentTransitions::target($event, $provider->orderStatusFor($event, $this->carriers->settings($provider)), (string) $order->status_id, $expected, $applied);
        if ($target === null) {
            return;
        }
        try {
            app(OrderAdministration::class)->changeStatus(null, (string) $order->id, null, $target, $note, 'system');
        } catch (Throwable $e) {
            // The shipment stays recorded; staff move the order manually (e.g. not enough stock for the target status).
            Log::warning('shipment_status_change_failed', ['shipment_id' => $shipmentId, 'event' => $event, 'type' => $e::class]);

            return;
        }
        DB::table('order_shipments')->where('id', $shipmentId)->update(['applied_events' => json_encode([...$applied, $event], JSON_THROW_ON_ERROR), 'expected_status_id' => $target, 'updated_at' => now()]);
    }

    /** The order snapshot a shipment is created from. */
    public function draft(string $orderId): ShipmentDraft
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('o.id', $orderId)->first(['o.*', 'l.code as locale_code']);
        $addresses = DB::table('order_addresses')->where('order_id', $orderId)->get()->keyBy('type');
        $address = $addresses['shipping'] ?? $addresses['billing'] ?? null;
        $items = DB::table('order_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')->where('i.order_id', $orderId)->get(['i.quantity', 'p.weight']);
        $weights = ShippingWeights::measure($items->map(fn (object $i): array => ['weight' => $i->weight === null ? null : Decimal::trim((string) $i->weight), 'quantity' => (int) $i->quantity, 'free_shipping' => false, 'freight' => null])->all(), false);
        $grams = (string) app(GeneralSettings::class)->all()['grams_per_weight_unit'];
        $payments = DB::table('order_payments')->where('order_id', $orderId)->get(['state', 'amount'])->map(fn (object $p): array => ['state' => $p->state, 'amount' => Decimal::trim($p->amount)])->all();
        $company = app(CompanySettings::class)->all();

        return new ShipmentDraft(
            (string) $order->id, $order->order_number,
            new Destination((string) ($address->country_code ?? ''), $address->region_code ?? null, $address->postal_code ?? null, $address->city ?? null, $address->line1 ?? null, $address->line2 ?? null,
                $address === null ? trim($order->first_name.' '.$order->last_name) : trim($address->first_name.' '.$address->last_name), $address->company ?? null, $address->phone ?? $order->phone, $order->email),
            $weights['chargeable_known'] ? ShippingWeights::toKilograms($weights['physical'], $grams) : null, $weights['chargeable_items'],
            Decimal::trim($order->total), PaymentRules::outstanding($payments, Decimal::trim($order->total)), $order->currency_code,
            $order->payment_method_id === null ? null : (string) DB::table('payment_methods')->where('id', $order->payment_method_id)->value('code'),
            $order->customer_note, (string) ($company['name'] ?: config('app.name')), $order->locale_code,
        );
    }

    /** @return array{0: ?ShippingProvider, 1: ?CarrierAccount, 2: ?object} */
    private function carrierOf(string $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first() ?? throw ValidationException::withMessages(['order' => __('shipping.order_missing')]);
        $method = $order->shipping_method_id === null ? null : DB::table('shipping_methods')->where('id', $order->shipping_method_id)->first();
        $provider = $method?->provider === null ? null : $this->carriers->provider($method->provider);
        $account = $provider === null ? null : $this->carriers->usableAccount($provider, $method->account_id === null ? null : (string) $method->account_id);

        return [$provider, $account, $method];
    }

    /** @return array{0: object, 1: ?ShippingProvider, 2: CarrierAccount} */
    private function stored(string $shipmentId): array
    {
        $row = DB::table('order_shipments')->where('id', $shipmentId)->first() ?? throw ValidationException::withMessages(['shipment' => __('shipping.shipment_unavailable')]);
        $provider = $this->carriers->provider($row->provider);
        // Follow-up operations always use the account that created the shipment, never another one.
        $account = $provider === null ? null : $this->carriers->usableAccount($provider, $row->account_id === null ? null : (string) $row->account_id);
        if ($provider === null || $account === null) {
            throw ValidationException::withMessages(['shipment' => __('shipping.provider_unavailable')]);
        }

        return [$row, $provider, $account];
    }

    private function reference(object $row): ShipmentReference
    {
        return new ShipmentReference((string) $row->tracking_number, json_decode((string) $row->extra_numbers, true) ?: [], $row->pickup_date, json_decode((string) $row->details, true) ?: []);
    }

    private function replayed(object $row): string
    {
        return match ($row->state) {
            'created', 'cancelled' => (string) $row->id,
            'creating' => throw ValidationException::withMessages(['shipment' => __('shipping.shipment_in_progress')]),
            default => throw ValidationException::withMessages(['shipment' => __('shipping.shipment_rejected', ['message' => (string) $row->error])]),
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ShippingProviderRejected $e) {
            throw ValidationException::withMessages(['shipment' => __('shipping.carrier_refused', ['message' => $e->getMessage()])]);
        } catch (ShippingProviderUnavailable) {
            throw ValidationException::withMessages(['shipment' => __('shipping.carrier_unavailable')]);
        }
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('orders.shipments');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('order_shipment', $actor, $operation);
    }
}
