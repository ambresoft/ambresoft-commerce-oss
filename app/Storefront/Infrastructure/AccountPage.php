<?php

namespace App\Storefront\Infrastructure;

use App\Authorization\Infrastructure\AccessChecker;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\ProductReviews;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Catalog\Infrastructure\ReviewPurchases;
use App\Customer\Infrastructure\CustomerSelfService;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderCancellation;
use App\Order\Infrastructure\OrderMoney;
use App\Order\Infrastructure\OrderQueries;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Geography\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the account page's main content block shows for each section of the signed-in customer's account: details,
 * orders, addresses, reviews and password. The customer is always the profile linked to the session, never an ID from
 * the request.
 */
final class AccountPage
{
    public const SECTIONS = ['profile', 'orders', 'order', 'addresses', 'reviews', 'newsletters', 'password'];

    public const ORDERS_PER_PAGE = 10;

    /** @return array<string,mixed> */
    public function data(string $section, object $customer, UserRecord $user, int $page = 1, ?string $order = null): array
    {
        return [
            'section' => $section,
            'wishlist_enabled' => (bool) app(GeneralSettings::class)->all()['wishlist_enabled'],
            'name' => $customer->first_name,
            ...match ($section) {
                'orders' => $this->orders($customer, $page),
                'order' => $this->order($customer, (string) $order),
                'addresses' => $this->addresses($customer),
                'reviews' => $this->reviews($customer),
                'newsletters' => ['newsletters' => $this->newsletters($customer)],
                'password' => ['staff_rule' => app(AccessChecker::class)->allows($user, 'admin.access')],
                default => $this->profile($customer),
            },
        ];
    }

    /** @return array<string,mixed> */
    private function profile(object $c): array
    {
        return ['profile' => ['first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email, 'phone' => $c->phone ?? '',
            'since' => CarbonImmutable::parse($c->account_linked_at)->toIso8601String(), 'version' => (int) $c->lock_version]];
    }

    /** @return array<string,mixed> */
    private function orders(object $c, int $page): array
    {
        $statuses = collect(app(OrderQueries::class)->statuses())->keyBy('id');
        $money = app(OrderMoney::class);
        $paged = DB::table('orders as o')->where('o.customer_id', $c->id)
            ->select(['o.id', 'o.order_number', 'o.status_id', 'o.total', 'o.currency_code', 'o.currency_decimals', 'o.created_at'])
            ->selectRaw("EXISTS (SELECT 1 FROM order_notifications n WHERE n.order_id = o.id AND n.kind = 'invoice') AS invoice_available")
            ->selectRaw('(SELECT coalesce(sum(quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS units_count')
            ->orderByDesc('o.created_at')->orderByDesc('o.id')->paginate(self::ORDERS_PER_PAGE, ['*'], 'page', max(1, $page));

        return ['orders' => [
            'rows' => array_map(fn (object $o): array => ['id' => (string) $o->id, 'number' => $o->order_number, 'invoice_url' => (bool) $o->invoice_available ? '/account/orders/'.$o->id.'/invoice' : null, 'created_at' => CarbonImmutable::parse($o->created_at)->toIso8601String(),
                'status' => isset($statuses[(string) $o->status_id]) ? ['name' => $statuses[(string) $o->status_id]['name'], 'color' => $statuses[(string) $o->status_id]['color']] : null,
                'units' => (int) $o->units_count, 'total' => $money->format($o->total, $o->currency_code, (int) $o->currency_decimals)], $paged->items()),
            'page' => $paged->currentPage(), 'last_page' => $paged->lastPage(), 'total' => $paged->total(),
        ]];
    }

    /**
     * One of the customer's own orders. An order of someone else is a 404, never a different page. Staff notes, actors
     * and e-mail logs stay out; the customer sees what they bought, where it goes and how the order progressed.
     *
     * @return array<string,mixed>
     */
    private function order(object $c, string $id): array
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1, 404);
        $o = DB::table('orders')->where('id', $id)->where('customer_id', $c->id)->first() ?? abort(404);
        $money = fn (string $value): string => app(OrderMoney::class)->format($value, $o->currency_code, (int) $o->currency_decimals);
        $statuses = collect(app(OrderQueries::class)->statuses())->keyBy('id');
        $status = fn ($sid): ?array => isset($statuses[(string) $sid]) ? ['name' => $statuses[(string) $sid]['name'], 'color' => $statuses[(string) $sid]['color']] : null;
        $countries = app(Countries::class);
        $invoice = $o->invoice_requested ? DB::table('order_invoice_verifications')->where('order_id', $o->id)->first(['country_code', 'vat_number', 'business_name']) : null;
        $rows = DB::table('order_addresses')->where('order_id', $o->id)->get();
        $regions = app(ShippingGeography::class)->regionNames($rows->filter(fn ($a): bool => $a->region_code !== null)->map(fn ($a): array => [$a->country_code, $a->region_code])->values()->all());
        $address = fn (?object $a): ?array => $a === null ? null : ['lines' => array_values(array_filter([
            trim($a->first_name.' '.$a->last_name), $a->company, $a->line1, $a->line2, trim(($a->postal_code ?? '').' '.$a->city),
            $a->region_code === null ? null : ($regions[$a->country_code.':'.$a->region_code] ?? null), $countries->name($a->country_code),
            ...array_filter([
                ($a->floor ?? null) !== null && $a->floor !== '' ? __('customers.floor').': '.$a->floor : null,
                ($a->bell_name ?? null) !== null && $a->bell_name !== '' ? __('customers.bell_name').': '.$a->bell_name : null,
                ($a->notes ?? null) !== null && $a->notes !== '' ? __('customers.address_notes').': '.$a->notes : null,
            ]),
        ], fn (?string $line): bool => $line !== null && trim($line) !== '')), 'phone' => $a->phone];
        $items = DB::table('order_items')->where('order_id', $o->id)->orderBy('position')->get();
        $products = $items->pluck('product_id')->filter()->all();
        $primary = DB::table('product_images')->whereIn('product_id', $products)->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $media = app(PublicMedia::class);
        $images = $media->images($primary->values()->all());
        // Only products the customer can still open on the storefront are linked.
        $visible = $products === [] ? collect() : app(PublicCatalog::class)->products((string) $c->id)->whereIn('products.id', $products)->get(['products.id', 'products.category_id']);
        $paths = app(CategoryUrls::class)->paths($visible->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all(), app()->getLocale());
        $slugs = [];
        $localeCodes = DB::table('locales')->pluck('code', 'id');
        foreach (DB::table('product_translations')->whereIn('product_id', $visible->pluck('id'))->get(['product_id', 'locale_id', 'slug']) as $t) {
            $slugs[$t->product_id][$localeCodes[$t->locale_id]] = ['slug' => $t->slug];
        }
        $urls = [];
        foreach ($visible as $row) {
            $slug = app(BlockContent::class)->text($slugs[$row->id] ?? [], app()->getLocale(), 'slug');
            if (isset($paths[(string) $row->category_id]) && $slug !== '') {
                $urls[(int) $row->id] = $paths[(string) $row->category_id].'/'.rawurlencode($slug);
            }
        }
        $statusRows = DB::table('order_status_history')->where('order_id', $o->id)->orderBy('created_at')->orderBy('id')->get(['to_status_id', 'created_at']);

        return ['order' => [
            'id' => (string) $o->id, 'number' => $o->order_number, 'created_at' => CarbonImmutable::parse($o->created_at)->toIso8601String(), 'status' => $status($o->status_id),
            'units' => (int) $items->sum('quantity'),
            'items' => $items->map(fn (object $i): array => [
                'id' => (string) $i->id, 'name' => $i->name, 'url' => $urls[(int) $i->product_id] ?? null, 'sku' => $i->sku, 'variation' => $i->variation, 'quantity' => (int) $i->quantity,
                'unit_price' => $money($i->unit_price), 'total' => $money($i->total),
                'image' => $i->product_id !== null && isset($primary[$i->product_id]) ? $media->url($images[$primary[$i->product_id]] ?? null, 'product', (string) $i->product_id) : null,
            ])->all(),
            'totals' => ['subtotal' => $money($o->subtotal), 'discount' => $o->discount_total > 0 ? $money($o->discount_total) : null, 'shipping' => $money($o->shipping_total), 'shipping_free' => $o->shipping_total <= 0, 'payment_fee' => $o->payment_fee > 0 ? $money($o->payment_fee) : null,
                'tax_included' => $o->tax_included > 0 ? $money($o->tax_included) : null, 'total' => $money($o->total)],
            'billing' => $address($rows->firstWhere('type', 'billing')), 'shipping' => $address($rows->firstWhere('type', 'shipping')),
            'shipping_method' => $o->shipping_method_name, 'payment_method' => $o->payment_method_name, 'note' => $o->customer_note, 'invoice_requested' => (bool) $o->invoice_requested,
            'invoice_details' => $invoice === null ? null : [...(array) $invoice, 'country' => $countries->name($invoice->country_code)],
            'invoice_url' => DB::table('order_notifications')->where('order_id', $o->id)->where('kind', 'invoice')->exists() ? '/account/orders/'.$o->id.'/invoice' : null,
            'can_cancel' => app(OrderCancellation::class)->allowed((string) $o->id),
            'timeline' => $statusRows->map(fn (object $h): array => ['status' => $status($h->to_status_id), 'at' => CarbonImmutable::parse($h->created_at)->toIso8601String()])->filter(fn (array $h): bool => $h['status'] !== null)->values()->all(),
        ]];
    }

    /** @return array<string,mixed> */
    private function addresses(object $c): array
    {
        $countries = app(Countries::class);
        $geography = app(ShippingGeography::class);
        $rows = DB::table('customer_addresses')->where('customer_id', $c->id)->orderBy('id')->get();
        $names = $geography->regionNames($rows->filter(fn ($a): bool => $a->region_code !== null)->map(fn ($a): array => [$a->country_code, $a->region_code])->values()->all());
        $allowed = CustomerSelfService::countryCodes();

        return [
            'version' => (int) $c->lock_version,
            'profile' => ['first_name' => $c->first_name, 'last_name' => $c->last_name],
            'defaults' => ['billing' => $c->default_billing_address_id === null ? null : (string) $c->default_billing_address_id, 'shipping' => $c->default_shipping_address_id === null ? null : (string) $c->default_shipping_address_id],
            'addresses' => $rows->map(fn ($a): array => ['id' => (string) $a->id, 'label' => $a->label, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'company' => $a->company,
                'line1' => $a->line1, 'line2' => $a->line2, 'city' => $a->city, 'postal_code' => $a->postal_code, 'country_code' => $a->country_code, 'country' => $countries->name($a->country_code),
                'region_code' => $a->region_code, 'region' => $a->region_code === null ? null : ($names[$a->country_code.':'.$a->region_code] ?? null), 'phone' => $a->phone,
                'street_number' => $a->street_number, 'floor' => $a->floor, 'bell_name' => $a->bell_name, 'notes' => $a->notes])->all(),
            'countries' => array_values(array_filter($countries->options(), fn (array $o): bool => $allowed === null || in_array($o['code'], $allowed, true))),
            'regions' => $geography->regionOptions(),
        ];
    }

    /** @return array<string,mixed> */
    private function reviews(object $c): array
    {
        $purchases = app(ReviewPurchases::class);
        $mine = DB::table('product_reviews')->where('customer_id', $c->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get(['id', 'product_id', 'rating', 'title', 'status', 'verified_purchase', 'created_at']);
        $reviewed = $mine->pluck('product_id')->map(strval(...))->all();
        $candidates = array_values(array_diff($purchases->purchasedProducts((string) $c->id), $reviewed));
        $reviewable = DB::table('products')->whereIn('id', $candidates)->whereNull('deleted_at')->whereIn('status', ProductReviews::REVIEWABLE_PRODUCT_STATUSES)->pluck('id')->map(strval(...))->all();
        $names = $this->productNames([...$reviewed, ...$reviewable]);

        return [
            'reviews' => $mine->map(fn (object $r): array => ['id' => (string) $r->id, 'product' => $names[(string) $r->product_id] ?? '#'.$r->product_id, 'rating' => (int) $r->rating, 'title' => $r->title,
                // Customers see whether a review is published, not the moderation reason.
                'state' => match ($r->status) {
                    'approved' => 'published', 'pending' => 'pending', default => 'not_published'
                },
                'verified_purchase' => (bool) $r->verified_purchase, 'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String()])->all(),
            'reviewable' => array_map(fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? '#'.$id], array_values(array_filter($candidates, fn (string $id): bool => in_array($id, $reviewable, true)))),
        ];
    }

    /**
     * The active newsletter lists the customer is on, by their account or their account's e-mail (a sign-up as a guest
     * counts too); `confirmed` is false while the list's confirmation e-mail is still unanswered.
     *
     * @return list<array{id:string,name:string,since:string,confirmed:bool}>
     */
    public function newsletters(object $c): array
    {
        $rows = DB::table('newsletter_list_subscribers as m')->join('newsletter_subscribers as s', 's.id', '=', 'm.subscriber_id')->join('newsletter_lists as l', 'l.id', '=', 'm.list_id')
            ->where('s.status', 'active')->where('l.status', 'active')->where(fn ($q) => $q->where('s.customer_id', $c->id)->orWhereRaw('lower(s.email) = ?', [mb_strtolower((string) $c->email)]))
            ->orderBy('m.created_at')->orderBy('l.id')->get(['l.id', 'm.created_at', 'm.confirmed_at'])->unique('id');
        $content = app(BlockContent::class);
        $names = $content->translations('newsletter_list_translations', 'list_id', $rows->pluck('id')->all());

        return $rows->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => $content->text($names[$r->id] ?? [], app()->getLocale(), 'name') ?: '#'.$r->id,
            'since' => CarbonImmutable::parse($r->created_at)->toIso8601String(), 'confirmed' => $r->confirmed_at !== null])->values()->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function productNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $catalog = app(LocaleCatalog::class);
        $locales = DB::table('locales')->whereIn('code', array_unique([app()->getLocale(), $catalog->defaultCode()]))->pluck('id', 'code');
        $current = $locales[app()->getLocale()] ?? null;
        $default = $locales[$catalog->defaultCode()] ?? null;

        return DB::table('products as p')->whereIn('p.id', $ids)
            ->leftJoin('product_translations as c', fn ($q) => $q->on('c.product_id', '=', 'p.id')->where('c.locale_id', $current))
            ->leftJoin('product_translations as d', fn ($q) => $q->on('d.product_id', '=', 'p.id')->where('d.locale_id', $default))
            ->selectRaw("p.id, coalesce(nullif(c.name, ''), nullif(d.name, ''), p.sku, '#' || p.id) as name")->get()
            ->mapWithKeys(fn (object $p): array => [(string) $p->id => $p->name])->all();
    }
}
