<?php

namespace App\Order\Infrastructure;

use App\Geography\Application\Contract\ShippingGeography;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Application\Service\PriceDisplay;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read models for the order screens. Filtering, sorting and pagination happen in PostgreSQL. */
final class OrderQueries
{
    public const SORTS = ['number', 'created_at', 'customer', 'status', 'total', 'updated_at'];

    public function __construct(private OrderMoney $money) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters, bool $withIp): array
    {
        $query = DB::table('orders as o')->join('order_statuses as s', 's.id', '=', 'o.status_id');
        $this->filter($query, $filters, $withIp);
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        match ($filters['sort'] ?? 'created_at') {
            'number' => $query->orderByRaw('length(o.order_number) '.$direction)->orderBy('o.order_number', $direction),
            'customer' => $query->orderByRaw('lower(o.last_name) '.$direction)->orderByRaw('lower(o.first_name) '.$direction),
            'status' => $query->orderBy('s.position', $direction)->orderBy('s.id', $direction),
            'total' => $query->orderBy('o.total', $direction),
            'updated_at' => $query->orderBy('o.updated_at', $direction),
            default => $query->orderBy('o.created_at', $direction),
        };
        // Deterministic tie-break.
        $query->orderBy('o.id', $direction);
        $page = $query->select(['o.id', 'o.order_number', 'o.first_name', 'o.last_name', 'o.email', 'o.phone', 'o.status_id', 'o.total', 'o.currency_code', 'o.currency_decimals', 'o.created_at', 'o.updated_at', 'o.lock_version', 'o.ip_address'])
            ->selectRaw('(SELECT count(*) FROM order_items i WHERE i.order_id = o.id) AS items_count')
            ->selectRaw('(SELECT coalesce(sum(quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS units_count')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $statuses = collect($this->statuses())->keyBy('id');
        $rows = [];
        foreach ($page->items() as $o) {
            $rows[] = [
                'id' => (string) $o->id, 'number' => $o->order_number, 'version' => (int) $o->lock_version,
                'customer' => trim($o->first_name.' '.$o->last_name), 'email' => $o->email, 'phone' => $o->phone,
                'status' => $statuses[(string) $o->status_id] ?? null, 'items' => (int) $o->items_count, 'units' => (int) $o->units_count,
                'total' => $this->money->format($o->total, $o->currency_code, (int) $o->currency_decimals), 'currency' => $o->currency_code,
                'created_at' => CarbonImmutable::parse($o->created_at)->toIso8601String(), 'updated_at' => CarbonImmutable::parse($o->updated_at)->toIso8601String(),
                'ip_address' => $withIp ? $o->ip_address : null,
            ];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return list<array{id: string, code: string, name: string, color: string, active: bool, initial: bool, final: bool, stock_action: string, position: int, system: bool, invoice: bool}> */
    public function statuses(): array
    {
        [$current, $default] = $this->localeIds();

        return DB::table('order_statuses as s')
            ->leftJoin('order_status_translations as c', fn ($j) => $j->on('c.status_id', '=', 's.id')->where('c.locale_id', $current))
            ->leftJoin('order_status_translations as d', fn ($j) => $j->on('d.status_id', '=', 's.id')->where('d.locale_id', $default))
            ->orderBy('s.position')->orderBy('s.id')
            ->get(['s.*', DB::raw('coalesce(c.name, d.name, s.code) as name')])
            ->map(fn (object $s): array => ['id' => (string) $s->id, 'code' => $s->code, 'name' => $s->name, 'color' => $s->color, 'active' => (bool) $s->is_active, 'initial' => (bool) $s->is_initial, 'final' => (bool) $s->is_final, 'stock_action' => $s->stock_action, 'position' => (int) $s->position, 'system' => (bool) $s->is_system, 'invoice' => (bool) $s->sends_invoice, 'customer_can_cancel' => (bool) $s->customer_can_cancel])->all();
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id, bool $withIp): ?array
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('o.id', $id)->first(['o.*', 'l.code as locale_code']);
        if ($order === null) {
            return null;
        }
        $money = fn (string $value): string => $this->money->format($value, $order->currency_code, (int) $order->currency_decimals);
        $statuses = collect($this->statuses())->keyBy('id');
        $countries = app(Countries::class);
        $invoice = DB::table('order_invoice_verifications')->where('order_id', $id)->first(['country_code', 'vat_number', 'business_name', 'provider', 'verified_at']);
        $rows = DB::table('order_addresses')->where('order_id', $id)->get();
        $regions = app(ShippingGeography::class)->regionNames($rows->filter(fn (object $a): bool => $a->region_code !== null)->map(fn (object $a): array => [$a->country_code, $a->region_code])->values()->all());
        $addresses = $rows->mapWithKeys(fn (object $a): array => [$a->type => [...(array) $a, 'country' => $countries->name($a->country_code), 'region' => $a->region_code === null ? null : ($regions[$a->country_code.':'.$a->region_code] ?? null)]]);
        $emails = [];
        foreach (DB::table('order_notifications')->where('order_id', $id)->orderByRaw("kind = 'invoice'")->orderBy('id')->get() as $n) {
            $emails[(string) $n->history_id][] = ['id' => (string) $n->id, 'kind' => $n->kind, 'state' => $n->state, 'attempts' => (int) $n->attempts, 'recipient' => $n->recipient, 'subject' => $n->subject, 'error' => $n->last_error,
                'sent_at' => $n->sent_at === null ? null : CarbonImmutable::parse($n->sent_at)->toIso8601String(), 'last_attempt_at' => $n->last_attempt_at === null ? null : CarbonImmutable::parse($n->last_attempt_at)->toIso8601String()];
        }
        $actors = DB::table('order_status_history')->where('order_id', $id)->select('actor_id')
            ->union(DB::table('order_comments')->where('order_id', $id)->select('author_id'));
        $users = DB::table('users')->whereIn('id', $actors)->pluck('name', 'id');
        $orderTaxes = DB::table('order_taxes')->where('order_id', $id)->orderBy('priority')->orderBy('tax_id')->get();
        // Thumbnails reuse the product media route, which needs product access; without it the page shows a placeholder.
        $images = Gate::allows('products.view')
            ? DB::table('product_images')->whereIn('product_id', DB::table('order_items')->where('order_id', $id)->whereNotNull('product_id')->pluck('product_id'))->where('is_primary', true)
                ->pluck('media_file_id', 'product_id')->map(fn ($media): string => route('admin.products.image', [$media], false))->all()
            : [];

        return [
            'id' => (string) $order->id, 'number' => $order->order_number, 'version' => (int) $order->lock_version,
            'status' => $statuses[(string) $order->status_id] ?? null, 'locale' => $order->locale_code,
            'customer_id' => $order->customer_id === null ? null : (string) $order->customer_id,
            'customer_exists' => $order->customer_id !== null,
            'first_name' => $order->first_name, 'last_name' => $order->last_name, 'email' => $order->email, 'phone' => $order->phone,
            'ip_address' => $withIp ? $order->ip_address : null,
            'currency' => $order->currency_code, 'tax_method' => $order->tax_method, 'tax_exempt' => (bool) $order->tax_exempt, 'stock_held' => (bool) $order->stock_held,
            'totals' => ['subtotal' => $money($order->subtotal), 'discount' => $money($order->discount_total), 'has_discount' => Decimal::compare($order->discount_total, '0') > 0, 'tax_added' => $money(Decimal::subtract($order->tax_total, $order->tax_included)), 'has_added_tax' => Decimal::compare($order->tax_total, $order->tax_included) > 0, 'tax' => $money($order->tax_total), 'tax_included' => $money($order->tax_included), 'tax_included_raw' => Decimal::trim($order->tax_included), 'shipping' => $money($order->shipping_total), 'payment_fee' => Decimal::compare(Decimal::trim($order->payment_fee), '0') > 0 ? $money($order->payment_fee) : null, 'total' => $money($order->total)],
            'billing' => $addresses['billing'] ?? null, 'shipping' => $addresses['shipping'] ?? null,
            'promotions' => DB::table('order_promotions')->where('order_id', $id)->where('is_current', true)->orderBy('id')->get()->map(fn (object $p): array => ['id' => (string) $p->promotion_id, 'name' => $p->name, 'discount' => $money($p->discount), 'shipping_discount' => $money($p->shipping_discount)])->all(),
            'coupon_code' => $order->coupon_code ?? '', 'coupon_id' => $order->coupon_id === null ? null : (string) $order->coupon_id,
            'invoice_details' => $invoice === null ? null : [...(array) $invoice, 'country' => $countries->name($invoice->country_code), 'verified_at' => CarbonImmutable::parse($invoice->verified_at)->toIso8601String()],
            'customer_note' => $order->customer_note, 'internal_note' => $order->internal_note, 'invoice_requested' => (bool) $order->invoice_requested,
            'created_at' => CarbonImmutable::parse($order->created_at)->toIso8601String(), 'updated_at' => CarbonImmutable::parse($order->updated_at)->toIso8601String(),
            'items' => DB::table('order_items')->where('order_id', $id)->orderBy('position')->get()->map(fn (object $i): array => [
                ...$this->vatColumns($i, $orderTaxes, $money),
                'id' => (string) $i->id, 'product_id' => $i->product_id === null ? null : (string) $i->product_id, 'image_url' => $images[$i->product_id] ?? null, 'name' => $i->name, 'sku' => $i->sku, 'variation' => $i->variation,
                'quantity' => (int) $i->quantity, 'deducted' => (int) $i->deducted_quantity, 'unit_price' => $money($i->unit_price), 'catalog_price' => $money($i->catalog_price),
                'price_overridden' => (bool) $i->price_overridden, 'discount' => Decimal::compare($i->discount_total, '0') > 0 ? $money($i->discount_total) : null, 'subtotal' => $money($i->subtotal), 'tax' => $money($i->tax_total), 'tax_included' => Decimal::compare(Decimal::trim($i->tax_included), '0') > 0, 'total' => $money($i->total),
            ])->all(),
            'taxes' => DB::table('order_taxes')->where('order_id', $id)->orderBy('priority')->orderBy('tax_id')->get()->map(fn (object $t): array => [
                'name' => $t->name, 'included' => (bool) $t->included, 'rate' => $t->rate_type === 'percent' && $t->rate_value !== null ? Decimal::trim(Decimal::multiply(Decimal::trim($t->rate_value), '100')).'%' : null, 'amount' => $money($t->amount),
            ])->all(),
            'history' => DB::table('order_status_history')->where('order_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(function (object $h) use ($statuses, $emails, $users): array {
                return [
                    'id' => (string) $h->id, 'from' => $h->from_status_id === null ? null : ($statuses[(string) $h->from_status_id] ?? null), 'to' => $statuses[(string) $h->to_status_id] ?? null,
                    'at' => CarbonImmutable::parse($h->created_at)->toIso8601String(), 'actor' => $h->actor_id === null ? null : ($users[$h->actor_id] ?? null), 'source' => $h->source, 'note' => $h->note,
                    'emails' => $emails[(string) $h->id] ?? [],
                ];
            })->all(),
            'comments' => DB::table('order_comments')->where('order_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (object $c): array => [
                'id' => (string) $c->id, 'visibility' => $c->visibility, 'body' => $c->body, 'author' => $c->author_id === null ? null : ($users[$c->author_id] ?? null), 'at' => CarbonImmutable::parse($c->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Editable values for the order form.
     *
     * @return array<string, mixed>|null
     */
    public function editor(string $id): ?array
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('o.id', $id)->first(['o.*', 'l.code as locale_code']);
        if ($order === null) {
            return null;
        }
        $address = fn (?object $a): ?array => $a === null ? null : array_map(fn ($v) => $v ?? '', array_intersect_key((array) $a, array_flip(['first_name', 'last_name', 'company', 'line1', 'line2', 'city', 'postal_code', 'country_code', 'region_code', 'phone', 'floor', 'bell_name', 'notes'])));
        $addresses = DB::table('order_addresses')->where('order_id', $id)->get()->keyBy('type');
        $status = collect($this->statuses())->firstWhere('id', (string) $order->status_id);

        return [
            'id' => (string) $order->id, 'number' => $order->order_number, 'version' => (int) $order->lock_version, 'status' => $status,
            'customer_id' => $order->customer_id === null ? '' : (string) $order->customer_id, 'locale' => $order->locale_code,
            'first_name' => $order->first_name, 'last_name' => $order->last_name, 'email' => $order->email, 'phone' => $order->phone ?? '',
            'billing' => $address($addresses->get('billing')), 'shipping' => $address($addresses->get('shipping')),
            'coupon_code' => $order->coupon_code ?? '', 'coupon_id' => $order->coupon_id === null ? null : (string) $order->coupon_id,
            'customer_note' => $order->customer_note ?? '', 'internal_note' => $order->internal_note ?? '', 'currency' => $order->currency_code,
            'editable_currency' => $order->currency_code === app(PriceDisplay::class)->primary()->code,
            'payment_method_id' => $order->payment_method_id === null ? '' : (string) $order->payment_method_id, 'payment_method_name' => $order->payment_method_name,
            'shipping_method_id' => $order->shipping_method_id === null ? '' : (string) $order->shipping_method_id, 'shipping_method_name' => $order->shipping_method_name,
            'shipping_amount' => (($shipping = json_decode((string) ($order->shipping_details ?? 'null'), true)) && ($shipping['overridden'] ?? false)) ? Decimal::atLeastScale((string) $shipping['amount'], (int) $order->currency_decimals) : '',
            'items' => DB::table('order_items')->where('order_id', $id)->orderBy('position')->get()->map(fn (object $i): array => [
                'item_id' => (string) $i->id, 'product_id' => $i->product_id === null ? null : (string) $i->product_id, 'name' => $i->name, 'sku' => $i->sku, 'variation' => $i->variation,
                'quantity' => (int) $i->quantity, 'unit_price' => Decimal::atLeastScale($i->unit_price, (int) $order->currency_decimals), 'catalog_price' => Decimal::atLeastScale($i->catalog_price, (int) $order->currency_decimals), 'price_overridden' => (bool) $i->price_overridden,
            ])->all(),
        ];
    }

    /**
     * Products that can be added: not in Trash and priced.
     *
     * @return list<array<string, mixed>>
     */
    public function products(string $search): array
    {
        [$current, $default] = $this->localeIds();
        $primary = app(PriceDisplay::class)->primary();
        $query = DB::table('products as p')->whereNull('p.deleted_at')->whereNotNull('p.price')
            ->leftJoin('product_translations as c', fn ($j) => $j->on('c.product_id', '=', 'p.id')->where('c.locale_id', $current))
            ->leftJoin('product_translations as d', fn ($j) => $j->on('d.product_id', '=', 'p.id')->where('d.locale_id', $default));
        if ($search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('p.sku', 'ilike', $term)->orWhere('c.name', 'ilike', $term)->orWhere('d.name', 'ilike', $term)->orWhere('p.ean', 'ilike', $term));
        }

        return $query->orderByRaw("coalesce(nullif(c.name, ''), d.name, '')")->orderBy('p.id')->limit(20)
            ->get(['p.id', 'p.sku', 'p.price', 'p.quantity', 'p.status', DB::raw("coalesce(nullif(c.name, ''), nullif(d.name, ''), '#' || p.id) as name")])
            ->map(fn (object $p): array => ['id' => (string) $p->id, 'name' => $p->name, 'sku' => $p->sku, 'status' => $p->status, 'quantity' => (int) $p->quantity, 'price' => Decimal::atLeastScale($p->price, $primary->decimals), 'price_formatted' => $this->money->format($p->price, $primary->code, $primary->decimals)])->all();
    }

    /**
     * Customers to pick from, with their default addresses for prefilling.
     *
     * @return list<array<string, mixed>>
     */
    public function customers(string $search, ?string $selected = null): array
    {
        $query = DB::table('customers');
        if ($search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('email', 'ilike', $term)->orWhereRaw("(first_name || ' ' || last_name) ILIKE ?", [$term])->orWhere('phone', 'ilike', $term));
        }
        $rows = $query->orderByRaw('lower(last_name), lower(first_name), id')->limit(20)->get();
        if ($selected !== null && ! $rows->contains('id', (int) $selected)) {
            $rows = $rows->prepend(DB::table('customers')->where('id', $selected)->first())->filter();
        }
        $addresses = DB::table('customer_addresses')->whereIn('customer_id', $rows->pluck('id'))->get()->keyBy('id');
        $pick = fn ($id): ?array => $id !== null && isset($addresses[$id]) ? array_map(fn ($v) => $v ?? '', ['line1' => trim($addresses[$id]->line1.' '.($addresses[$id]->street_number ?? ''))] + array_intersect_key((array) $addresses[$id], array_flip(['first_name', 'last_name', 'company', 'line1', 'line2', 'city', 'postal_code', 'country_code', 'region_code', 'phone', 'floor', 'bell_name', 'notes']))) : null;

        return $rows->values()->map(fn (object $c): array => [
            'id' => (string) $c->id, 'name' => trim($c->first_name.' '.$c->last_name), 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email, 'phone' => $c->phone ?? '',
            'status' => $c->status, 'billing' => $pick($c->default_billing_address_id), 'shipping' => $pick($c->default_shipping_address_id),
        ])->all();
    }

    /**
     * Statuses using each status and its templates, for the status screens.
     *
     * @return array<string, array{orders: int, templates: list<string>}>
     */
    public function usage(): array
    {
        $orders = DB::table('orders')->selectRaw('status_id, count(*) AS total')->groupBy('status_id')->pluck('total', 'status_id');
        $templates = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->get(['t.status_id', 'l.code'])->groupBy('status_id');
        $usage = [];
        foreach (DB::table('order_statuses')->pluck('id') as $id) {
            $usage[(string) $id] = ['orders' => (int) ($orders[$id] ?? 0), 'templates' => ($templates[$id] ?? collect())->pluck('code')->all()];
        }

        return $usage;
    }

    /** @param array<string, mixed> $filters */
    private function filter(Builder $query, array $filters, bool $withIp): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($term, $search): void {
                $q->where('o.order_number', 'ilike', $term)->orWhere('o.email', 'ilike', $term)->orWhere('o.phone', 'ilike', $term)
                    ->orWhereRaw("(o.first_name || ' ' || o.last_name) ILIKE ?", [$term]);
                if (preg_match('/^[1-9][0-9]{0,18}$/D', $search) === 1) {
                    $q->orWhere('o.id', $search);
                }
            });
        }
        foreach (['name' => "(o.first_name || ' ' || o.last_name)", 'email' => 'o.email', 'phone' => 'o.phone'] as $field => $column) {
            if (($filters[$field] ?? '') !== '') {
                $query->whereRaw($column.' ILIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters[$field]).'%']);
            }
        }
        if (! empty($filters['status'])) {
            $query->where('o.status_id', $filters['status']);
        }
        if (($filters['invoice'] ?? '') !== '') {
            $query->where('o.invoice_requested', $filters['invoice'] === '1');
        }
        $timezone = app(GeneralSettings::class)->all()['timezone'];
        foreach (['created' => 'o.created_at', 'updated' => 'o.updated_at'] as $prefix => $column) {
            if (! empty($filters[$prefix.'_from'])) {
                $query->where($column, '>=', CarbonImmutable::parse($filters[$prefix.'_from'], $timezone)->startOfDay()->utc());
            }
            if (! empty($filters[$prefix.'_to'])) {
                $query->where($column, '<', CarbonImmutable::parse($filters[$prefix.'_to'], $timezone)->addDay()->startOfDay()->utc());
            }
        }
        if (($filters['total_min'] ?? '') !== '') {
            $query->where('o.total', '>=', $filters['total_min']);
        }
        if (($filters['total_max'] ?? '') !== '') {
            $query->where('o.total', '<=', $filters['total_max']);
        }
        if ($withIp && ($filters['ip'] ?? '') !== '' && ($ip = OrderOrigin::valid((string) $filters['ip'])) !== null) {
            $query->whereRaw('o.ip_address = ?::inet', [$ip]);
        }
    }

    /** The classic invoice columns of a line: unit price without VAT, VAT percentage and unit price with VAT.
     * @param  iterable<object>  $taxes
     * @return array<string, string>
     */
    private function vatColumns(object $item, iterable $taxes, \Closure $money): array
    {
        $vat = app(OrderLineVat::class)->line($item, $taxes);

        return ['unit_net' => $money($vat['unit_net']), 'vat_rate' => $vat['vat_rate'].'%', 'unit_gross' => $money($vat['unit_gross'])];
    }

    /** @return array{int, int} */
    private function localeIds(): array
    {
        $locales = app(LocaleCatalog::class);
        $default = (int) $locales->active()->firstWhere('code', $locales->defaultCode())->id;

        return [(int) ($locales->active()->firstWhere('code', app()->getLocale())->id ?? $default), $default];
    }
}
