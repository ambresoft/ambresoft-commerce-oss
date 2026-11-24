<?php

namespace App\Shipping\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\ImageAttachment;
use App\Order\Infrastructure\OrderMoney;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Modules\AddonCatalog;
use App\Shipping\Application\Contract\CancelsShipments;
use App\Shipping\Application\Contract\CreatesShipments;
use App\Shipping\Application\Contract\ManifestsShipments;
use App\Shipping\Application\Contract\PrintsLabels;
use App\Shipping\Application\Contract\QuotesRates;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Contract\TracksShipments;
use App\Shipping\Application\Contract\VerifiesAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read models for shipping administration and the shipping part of the order page. */
final class ShippingQueries
{
    public function __construct(private CarrierSettings $carriers) {}

    /** The method's name in $locale, then the default language, then "#id". */
    public function name(string $methodId, string $locale): string
    {
        $names = DB::table('shipping_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $methodId)->pluck('t.name', 'l.code');
        $default = app(LocaleCatalog::class)->defaultCode();

        return (string) ($names[$locale] ?? $names[$default] ?? '#'.$methodId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $locale = app()->getLocale();
        $localeIds = DB::table('locales')->whereIn('code', [$locale, app(LocaleCatalog::class)->defaultCode()])->pluck('id', 'code');
        $query = DB::table('shipping_methods as m')
            ->leftJoin('shipping_method_translations as c', fn ($j) => $j->on('c.method_id', '=', 'm.id')->where('c.locale_id', $localeIds[$locale] ?? 0))
            ->leftJoin('shipping_method_translations as d', fn ($j) => $j->on('d.method_id', '=', 'm.id')->where('d.locale_id', $localeIds[app(LocaleCatalog::class)->defaultCode()] ?? 0))
            ->leftJoin('shipping_carrier_accounts as a', 'a.id', '=', 'm.account_id');
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('c.name', 'ilike', $term)->orWhere('d.name', 'ilike', $term)->orWhere('a.name', 'ilike', $term)->orWhere('m.provider', 'ilike', $term));
        }
        if (in_array($filters['calculation'] ?? null, ['manual', 'carrier'], true)) {
            $query->where('m.calculation', $filters['calculation']);
        }
        if (in_array($filters['status'] ?? null, ['active', 'disabled'], true)) {
            $query->where('m.is_active', $filters['status'] === 'active');
        }
        $page = $query->orderBy('m.position')->orderBy('m.id')
            ->select(['m.*', 'a.name as account_name', DB::raw("coalesce(nullif(c.name, ''), d.name, '#' || m.id) as display_name")])
            ->paginate(25)->withQueryString();
        $usage = DB::table('orders')->whereIn('shipping_method_id', $page->getCollection()->pluck('id'))->selectRaw('shipping_method_id, count(*) AS n')->groupBy('shipping_method_id')->pluck('n', 'shipping_method_id');
        $page->setCollection($page->getCollection()->map(fn ($m) => $this->row($m, $usage->all())));

        return $page->toArray();
    }

    /**
     * @param  array<int|string, mixed>  $usage  orders per method
     * @return array<string, mixed>
     */
    private function row(object $m, array $usage): array
    {
        $provider = $m->provider === null ? null : $this->carriers->provider($m->provider);

        return [
            'id' => (string) $m->id, 'version' => (int) $m->lock_version, 'name' => $m->display_name, 'calculation' => $m->calculation,
            'provider' => $m->provider, 'provider_name' => $provider?->name() ?? $m->provider, 'provider_available' => $m->provider === null || $provider !== null,
            'account_name' => $m->account_name, 'active' => (bool) $m->is_active, 'orders' => (int) ($usage[$m->id] ?? 0),
            'updated_at' => $m->updated_at === null ? null : CarbonImmutable::parse($m->updated_at)->toIso8601String(),
            'image_url' => $m->image_media_id === null ? null : '/admin/shipping-methods/'.$m->id.'/image?v='.$m->image_media_id,
        ];
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $m = DB::table('shipping_methods')->where('id', $id)->first();
        if ($m === null) {
            return null;
        }
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $amount = fn (string $v): string => Decimal::atLeastScale($v, $scale);
        $translations = DB::table('shipping_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $id)->get(['l.code', 't.name', 't.description', 't.delivery_time'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['name' => $t->name, 'description' => $t->description, 'delivery_time' => $t->delivery_time]]);
        $pluck = fn (string $table, string $column): array => DB::table($table)->where('method_id', $id)->orderBy($column)->pluck($column)->map(strval(...))->all();

        return [
            'id' => (string) $m->id, 'version' => (int) $m->lock_version, 'name' => $this->name($id, app()->getLocale()), 'calculation' => $m->calculation,
            'provider' => $m->provider ?? '', 'account_id' => $m->account_id === null ? '' : (string) $m->account_id, 'active' => (bool) $m->is_active,
            'min_weight' => $m->min_weight === null ? '' : Decimal::trim($m->min_weight), 'max_weight' => $m->max_weight === null ? '' : Decimal::trim($m->max_weight),
            'free_shipping_applies' => (bool) $m->free_shipping_applies, 'value_limit_enabled' => (bool) $m->value_limit_enabled, 'max_shipment_value' => $m->max_shipment_value === null ? '' : $amount(Decimal::trim($m->max_shipment_value)), 'carrier_amount' => $m->carrier_amount, 'carrier_surcharge' => $amount(Decimal::trim($m->carrier_surcharge)),
            'translations' => (object) $translations->all(),
            'rates' => DB::table('shipping_method_rates')->where('method_id', $id)->orderBy('rate_area_id')->orderBy('weight_from')->get()->map(fn (object $r): array => [
                'rate_area_id' => (string) $r->rate_area_id, 'weight_from' => Decimal::trim($r->weight_from), 'weight_to' => $r->weight_to === null ? '' : Decimal::trim($r->weight_to),
                'base' => $amount(Decimal::trim($r->base)), 'per_unit' => $amount(Decimal::trim($r->per_unit)),
            ])->all(),
            'area_ids' => $pluck('shipping_method_areas', 'rate_area_id'), 'tax_ids' => $pluck('shipping_method_taxes', 'tax_id'),
            'payment_method_ids' => $pluck('shipping_method_payment_methods', 'payment_method_id'), 'customer_group_ids' => $pluck('shipping_method_customer_groups', 'group_id'),
            'orders' => DB::table('orders')->where('shipping_method_id', $id)->count(),
            'image' => ImageAttachment::view($m->image_media_id, '/admin/shipping-methods/'.$m->id.'/image'),
        ];
    }

    /**
     * Choices for the method editor: providers (with their accounts), rate areas, taxes, payment methods, groups.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $locale = app()->getLocale();
        $default = app(LocaleCatalog::class)->defaultCode();
        $named = function (string $table, string $owner, string $base) use ($locale, $default): array {
            $rows = DB::table($base)->orderBy('id')->get(['id']);
            $names = DB::table($table.' as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('l.code', [$locale, $default])->get(["t.$owner as owner", 'l.code', 't.name'])->groupBy('owner');

            return $rows->map(function (object $r) use ($names, $locale, $default): array {
                $byLocale = collect($names[$r->id] ?? [])->pluck('name', 'code');

                return ['id' => (string) $r->id, 'name' => (string) ($byLocale[$locale] ?? $byLocale[$default] ?? '#'.$r->id)];
            })->all();
        };
        $settings = app(GeneralSettings::class)->all();

        return [
            'providers' => array_map(fn (ShippingProvider $p): array => [
                'id' => $p->id(), 'name' => $p->name(), 'quotes' => $p instanceof QuotesRates, 'shipments' => $p instanceof CreatesShipments,
                'accounts' => DB::table('shipping_carrier_accounts')->where('provider', $p->id())->orderBy('position')->orderBy('id')->get()
                    ->map(fn (object $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'usable' => $this->carriers->accountIssues($p, $a) === []])->all(),
            ], $this->carriers->providers()),
            'areas' => $named('rate_area_translations', 'rate_area_id', 'rate_areas'),
            'taxes' => $named('tax_translations', 'tax_id', 'taxes'),
            'paymentMethods' => $named('payment_method_translations', 'method_id', 'payment_methods'),
            'customerGroups' => $named('customer_group_translations', 'group_id', 'customer_groups'),
            'weightUnit' => (string) $settings['weight_unit'],
            'currency' => ['code' => app(CurrencyRepository::class)->currentPrimary()->code, 'decimals' => app(CurrencyRepository::class)->currentPrimary()->decimals],
        ];
    }

    /**
     * Carrier page: provider settings, accounts and capabilities.
     *
     * @return array<string, mixed>|null
     */
    public function carrier(string $providerId): ?array
    {
        $provider = $this->carriers->provider($providerId);
        if ($provider === null) {
            return null;
        }
        $row = $this->carriers->settingsRow($providerId);
        $fields = $this->carriers->fieldValues();
        $owner = (string) $this->carriers->owner($providerId);

        return [
            'id' => $provider->id(), 'name' => $provider->name(), 'owner' => $owner, 'owner_title' => $owner === 'core' ? null : (app(AddonCatalog::class)->manifest($owner)->title ?? $owner),
            'version' => (int) $row->lock_version, 'settings' => $fields->editor($provider->settings(), $this->carriers->settings($provider)),
            'settings_issues' => $this->carriers->settingsIssues($provider),
            'capabilities' => ['quotes' => $provider instanceof QuotesRates, 'shipments' => $provider instanceof CreatesShipments, 'labels' => $provider instanceof PrintsLabels,
                'tracking' => $provider instanceof TracksShipments, 'cancel' => $provider instanceof CancelsShipments, 'verify' => $provider instanceof VerifiesAccounts,
                'manifests' => $provider instanceof ManifestsShipments],
            'manifest_label' => $provider instanceof ManifestsShipments ? __($provider->manifestLabel()) : null,
            'account_fields' => $fields->editor($provider->accountFields(), []),
            'accounts' => DB::table('shipping_carrier_accounts')->where('provider', $providerId)->orderBy('position')->orderBy('id')->get()->map(fn (object $a): array => [
                'id' => (string) $a->id, 'version' => (int) $a->lock_version, 'name' => $a->name, 'issues' => $this->carriers->accountIssues($provider, $a),
                'verified' => $a->verified_fingerprint !== null && $a->verified_fingerprint === $fields->fingerprint('carrier:'.$providerId, $provider->accountFields(), $this->carriers->accountValues($provider, $a)),
                'verified_at' => $a->verified_at === null ? null : CarbonImmutable::parse($a->verified_at)->toIso8601String(),
                'fields' => $fields->editor($provider->accountFields(), $this->carriers->accountValues($provider, $a)),
                'methods' => DB::table('shipping_methods')->where('account_id', $a->id)->count(),
            ])->all(),
        ];
    }

    /**
     * Shipping and shipments section of the order page.
     *
     * @return array<string, mixed>
     */
    public function forOrder(string $orderId, bool $canManage): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'shipping_method_id', 'shipping_method_name', 'shipping_details', 'shipping_total', 'currency_code', 'currency_decimals']);
        $money = app(OrderMoney::class);
        $format = fn (?string $v): ?string => $v === null ? null : $money->format($v, $order->currency_code, (int) $order->currency_decimals);
        $details = json_decode((string) ($order->shipping_details ?? 'null'), true) ?: null;
        $method = $order->shipping_method_id === null ? null : DB::table('shipping_methods')->where('id', $order->shipping_method_id)->first();
        $provider = $method?->provider === null ? null : $this->carriers->provider($method->provider);
        $account = $provider === null ? null : $this->carriers->usableAccount($provider, $method->account_id === null ? null : (string) $method->account_id);
        $users = DB::table('users')->pluck('name', 'id');
        $shipments = DB::table('order_shipments')->where('order_id', $orderId)->orderBy('id')->get()->map(function (object $s) use ($users, $format, $canManage): array {
            $provider = $this->carriers->provider($s->provider);
            $active = $s->state === 'created';

            return [
                'id' => (string) $s->id, 'provider' => $s->provider, 'provider_name' => $provider?->name() ?? $s->provider, 'account_name' => $s->account_name, 'state' => $s->state,
                'tracking_number' => $s->tracking_number, 'extra_numbers' => json_decode((string) $s->extra_numbers, true) ?: [],
                'pickup_date' => $s->pickup_date, 'weight_kg' => $s->weight_kg === null ? null : Decimal::trim($s->weight_kg), 'packages' => (int) $s->packages,
                'cod_amount' => $format($s->cod_amount === null ? null : Decimal::trim($s->cod_amount)), 'manifest_number' => $s->manifest_number,
                'label_printed_at' => $this->iso($s->label_printed_at), 'tracking_state' => $s->tracking_state, 'tracking_code' => $s->tracking_code,
                'tracking_summary' => $s->tracking_summary, 'tracking_events' => json_decode((string) ($s->tracking_events ?? '[]'), true) ?: [],
                'tracking_checked_at' => $this->iso($s->tracking_checked_at), 'delivered_at' => $this->iso($s->delivered_at), 'returned_at' => $this->iso($s->returned_at),
                'error' => $s->error, 'created_by' => $s->created_by === null ? null : ($users[$s->created_by] ?? null), 'created_at' => $this->iso($s->created_at),
                'stale' => $s->state === 'creating' && CarbonImmutable::parse($s->updated_at)->lessThan(now()->subMinutes(5)),
                'actions' => [
                    'label' => $canManage && $active && $provider instanceof PrintsLabels, 'track' => $canManage && $active && $provider instanceof TracksShipments,
                    'cancel' => $canManage && $active && $s->manifest_number === null && $provider instanceof CancelsShipments,
                    'resolve' => $canManage && $s->state === 'creating',
                ],
                'label_formats' => $provider instanceof PrintsLabels ? array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => __($l)], array_keys($provider->labelFormats()), $provider->labelFormats()) : [],
            ];
        })->all();

        return [
            'method' => $order->shipping_method_id === null ? null : ['id' => (string) $order->shipping_method_id, 'name' => $order->shipping_method_name],
            'charge' => $format(Decimal::trim($order->shipping_total)),
            'details' => $details === null ? null : [...$details, 'calculated_amount' => $format(isset($details['calculated_amount']) ? Decimal::trim((string) $details['calculated_amount']) : null)],
            'provider' => $provider === null ? null : ['id' => $provider->id(), 'name' => $provider->name()],
            'can_create' => $canManage && $provider instanceof CreatesShipments && $account !== null,
            'create_blocker' => $method === null || $method->provider === null ? null : ($provider === null ? __('shipping.provider_unavailable') : ($account === null ? __('shipping.account_unusable') : null)),
            'shipments' => $shipments,
        ];
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
