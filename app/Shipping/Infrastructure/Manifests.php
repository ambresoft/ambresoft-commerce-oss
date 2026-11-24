<?php

namespace App\Shipping\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\ShippingDocument;
use App\Shipping\Application\Contract\ManifestsShipments;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Contract\ShippingProviderRejected;
use App\Shipping\Application\Contract\ShippingProviderUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carrier manifests / pickup lists (`orders.shipments`). Issuing is serialized per provider, account and date, and a
 * repeated request key returns the earlier result instead of issuing a second list. Shipments found on an issued list
 * are marked with its number (they are final at the carrier and can no longer be cancelled from here).
 */
final class Manifests
{
    public function __construct(private CarrierSettings $carriers) {}

    /**
     * Lists of a day at the carrier (merged with local records) and the account's shipments of that day.
     *
     * @return array<string, mixed>
     */
    public function day(string $actor, string $providerId, string $accountId, string $date): array
    {
        $this->authorize($actor);
        [$provider, $account] = $this->resolve($providerId, $accountId);
        $date = $this->date($date);
        $remote = $this->call(fn () => $provider->manifests($date, $account, $this->carriers->settings($provider)));
        $local = DB::table('shipping_manifests')->where('provider', $providerId)->where('account_id', $accountId)->where('manifest_date', $date)->get()->keyBy('number');
        $lists = [];
        foreach ($remote as $manifest) {
            $lists[$manifest->number] = ['number' => $manifest->number, 'issued_at' => $manifest->issuedAt, 'count' => $manifest->count, 'local' => isset($local[$manifest->number])];
        }
        foreach ($local as $number => $row) {
            $lists[$number] ??= ['number' => (string) $number, 'issued_at' => $row->created_at, 'count' => $row->voucher_count, 'local' => true];
        }

        return [
            'lists' => array_values($lists),
            'shipments' => DB::table('order_shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('s.provider', $providerId)->where('s.account_id', $accountId)
                ->where('s.pickup_date', $date)->where('s.state', 'created')->orderBy('s.id')
                ->get(['s.id', 's.order_id', 'o.order_number', 's.tracking_number', 's.label_printed_at', 's.manifest_number'])
                ->map(fn (object $s): array => ['id' => (string) $s->id, 'order_id' => (string) $s->order_id, 'order_number' => $s->order_number, 'tracking_number' => $s->tracking_number,
                    'printed' => $s->label_printed_at !== null, 'manifest_number' => $s->manifest_number])->all(),
            'request_key' => (string) Str::uuid(),
        ];
    }

    /** @return array{number: string, count: ?int} */
    public function issue(string $actor, string $providerId, string $accountId, string $date, string $requestKey): array
    {
        $this->authorize($actor);
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => __('shipping.stale')]);
        }
        $done = DB::table('shipping_manifests')->where('request_key', $requestKey)->first();
        if ($done !== null) {
            return ['number' => $done->number, 'count' => $done->voucher_count === null ? null : (int) $done->voucher_count];
        }
        [$provider, $account] = $this->resolve($providerId, $accountId);
        $date = $this->date($date);
        $lock = Cache::lock('cms-manifest:'.$providerId.':'.$accountId.':'.$date, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['manifest' => __('shipping.manifest_in_progress')]);
        }
        try {
            $result = $this->call(fn () => $provider->issueManifest($date, $account, $this->carriers->settings($provider)));
            if ($result->number === null) {
                $orders = DB::table('order_shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('s.provider', $providerId)
                    ->whereIn('s.tracking_number', $result->blocking)->pluck('o.order_number', 's.tracking_number');
                $blocking = array_map(fn (string $n): string => isset($orders[$n]) ? $n.' ('.__('shipping.order_ref', ['number' => $orders[$n]]).')' : $n, $result->blocking);
                throw ValidationException::withMessages(['manifest' => trim(__('shipping.manifest_refused', ['message' => (string) $result->message]).' '.implode(', ', $blocking))]);
            }
            $this->audited($actor, fn () => DB::table('shipping_manifests')->insertOrIgnore([
                'provider' => $providerId, 'account_id' => $accountId, 'number' => $result->number, 'manifest_date' => $date, 'voucher_count' => $result->count,
                'request_key' => $requestKey, 'issued_by' => $actor, 'created_at' => now(),
            ]));
        } finally {
            $lock->release();
        }
        $this->markContents($provider, $account, $result->number, $date);

        return ['number' => $result->number, 'count' => $result->count];
    }

    /**
     * Tracking numbers on a list, with the local orders they belong to.
     *
     * @return list<array{tracking_number: string, order_id: ?string, order_number: ?string}>
     */
    public function contents(string $actor, string $providerId, string $accountId, string $number, string $date): array
    {
        $this->authorize($actor);
        [$provider, $account] = $this->resolve($providerId, $accountId);
        $numbers = $this->markContents($provider, $account, $this->number($number), $this->date($date));
        $orders = DB::table('order_shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('s.provider', $providerId)->whereIn('s.tracking_number', $numbers)
            ->get(['s.tracking_number', 's.order_id', 'o.order_number'])->keyBy('tracking_number');

        return array_map(fn (string $n): array => ['tracking_number' => $n, 'order_id' => isset($orders[$n]) ? (string) $orders[$n]->order_id : null, 'order_number' => $orders[$n]->order_number ?? null], $numbers);
    }

    public function print(string $actor, string $providerId, string $accountId, string $number, string $date): ShippingDocument
    {
        $this->authorize($actor);
        [$provider, $account] = $this->resolve($providerId, $accountId);

        return $this->call(fn () => $provider->printManifest($this->number($number), $this->date($date), $account, $this->carriers->settings($provider)));
    }

    /** @return list<string> */
    private function markContents(ShippingProvider&ManifestsShipments $provider, CarrierAccount $account, string $number, string $date): array
    {
        try {
            $numbers = $provider->manifestContents($number, $date, $account, $this->carriers->settings($provider));
        } catch (ShippingProviderRejected|ShippingProviderUnavailable) {
            return [];
        }
        DB::table('order_shipments')->where('provider', $provider->id())->where('account_id', $account->id)->whereIn('tracking_number', $numbers)
            ->whereNull('manifest_number')->update(['manifest_number' => $number, 'manifest_date' => $date, 'updated_at' => now()]);

        return $numbers;
    }

    /** @return array{0: ShippingProvider&ManifestsShipments, 1: CarrierAccount} */
    private function resolve(string $providerId, string $accountId): array
    {
        $provider = $this->carriers->provider($providerId);
        $account = $provider === null ? null : $this->carriers->usableAccount($provider, $accountId);
        if (! $provider instanceof ManifestsShipments || $account === null) {
            throw ValidationException::withMessages(['account' => __('shipping.account_unusable')]);
        }

        return [$provider, $account];
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $parsed < new \DateTimeImmutable('-1 year') || $parsed > new \DateTimeImmutable('+1 month')) {
            throw ValidationException::withMessages(['date' => __('shipping.date_invalid')]);
        }

        return $date;
    }

    private function number(string $number): string
    {
        if (preg_match('/^[A-Za-z0-9-]{1,60}$/D', $number) !== 1) {
            throw ValidationException::withMessages(['number' => __('shipping.choice_invalid')]);
        }

        return $number;
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
            throw ValidationException::withMessages(['manifest' => __('shipping.carrier_refused', ['message' => $e->getMessage()])]);
        } catch (ShippingProviderUnavailable) {
            throw ValidationException::withMessages(['manifest' => __('shipping.carrier_unavailable')]);
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
        return app(AuditLogger::class)->operation('shipping_manifest', $actor, $operation);
    }
}
