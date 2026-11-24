<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Geography\Application\Contract\ShippingGeography;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Application\Contract\OrderPaymentMethods;
use App\Order\Application\Contract\OrderShipping;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Order\Infrastructure\OrderInput;
use App\Order\Infrastructure\OrderMoney;
use App\Order\Infrastructure\OrderNotifier;
use App\Order\Infrastructure\OrderPricing;
use App\Order\Infrastructure\OrderQueries;
use App\Payment\Infrastructure\PaymentQueries;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use App\Shipping\Domain\Service\ShipmentValue;
use App\Shipping\Infrastructure\Shipments;
use App\Shipping\Infrastructure\ShippingQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController
{
    public function index(Request $request, OrderQueries $queries): Response
    {
        Gate::authorize('orders.view');
        $withIp = Gate::allows('orders.customer-ip');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'string', 'max:254'], 'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'integer', 'min:1'], 'invoice' => ['nullable', Rule::in(['0', '1'])],
            'created_from' => ['nullable', 'date_format:Y-m-d'], 'created_to' => ['nullable', 'date_format:Y-m-d'],
            'updated_from' => ['nullable', 'date_format:Y-m-d'], 'updated_to' => ['nullable', 'date_format:Y-m-d'],
            'total_min' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'], 'total_max' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'],
            'ip' => $withIp ? ['nullable', 'ip'] : ['prohibited'],
            'sort' => ['nullable', Rule::in(OrderQueries::SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        return Inertia::render('Admin/Orders/Index', [
            ...$this->shell($request), 'orders' => $queries->listing($filters, $withIp), 'filters' => (object) $filters,
            'statuses' => $queries->statuses(), 'canSeeIp' => $withIp,
        ]);
    }

    public function create(Request $request, OrderQueries $queries, Countries $countries): Response
    {
        Gate::authorize('orders.create');
        $currency = app(CurrencyRepository::class)->currentPrimary();

        return Inertia::render('Admin/Orders/Form', [
            ...$this->shell($request, __('orders.create')), 'order' => null, ...$this->formOptions($request, $queries, $countries, null),
            'currency' => ['code' => $currency->code, 'decimals' => $currency->decimals],
            'paymentMethods' => app(OrderPaymentMethods::class)->choices($currency->code, $currency->decimals, app()->getLocale()),
            'shippingMethods' => app(OrderShipping::class)->choices(app()->getLocale()),
        ]);
    }

    public function store(Request $request, OrderAdministration $orders): RedirectResponse
    {
        Gate::authorize('orders.create');
        // Staff-created orders have no customer request IP: the administrator's own IP is never recorded as the customer's.
        $id = $orders->create((string) $request->user()->id, $request->except(['ip_address']), 'admin', null);

        return redirect('/admin/orders/'.$id)->with('status', __('orders.created_message'));
    }

    public function show(Request $request, OrderQueries $queries, PaymentQueries $payments, string $order): Response
    {
        Gate::authorize('orders.view');
        $detail = $queries->detail($order, Gate::allows('orders.customer-ip')) ?? abort(404);

        return Inertia::render('Admin/Orders/Show', [
            ...$this->shell($request, __('orders.number', ['number' => $detail['number']])), 'order' => $detail, 'statuses' => $queries->statuses(),
            'payment' => $payments->forOrder($order, Gate::allows('orders.payments')),
            'shipping' => $shipping = app(ShippingQueries::class)->forOrder($order, Gate::allows('orders.shipments')),
            'shipmentForm' => $shipping['can_create'] ? app(Shipments::class)->form($order) : null,
        ]);
    }

    /** Printable invoice of the order, as the customer receives it. */
    public function invoice(OrderEmailRenderer $renderer, string $order): HttpResponse
    {
        Gate::authorize('orders.view');
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);

        return response($renderer->invoicePage($order))->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'")->header('Cache-Control', 'private, no-store');
    }

    /** The packing slip to print and put in the parcel. */
    public function packingSlip(OrderEmailRenderer $renderer, string $order): HttpResponse
    {
        Gate::authorize('orders.view');
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);

        return response($renderer->packingSlipPage($order))->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'")->header('Cache-Control', 'private, no-store');
    }

    public function edit(Request $request, OrderQueries $queries, Countries $countries, string $order): Response
    {
        Gate::authorize('orders.update');
        $editor = $queries->editor($order) ?? abort(404);
        $decimals = (int) DB::table('orders')->where('id', $order)->value('currency_decimals');
        $methods = app(OrderPaymentMethods::class);
        $choices = $methods->choices($editor['currency'], $decimals, app()->getLocale());
        // The order's current method stays visible (and selected) even when it is no longer offered.
        if ($editor['payment_method_id'] !== '' && ! in_array($editor['payment_method_id'], array_column($choices, 'id'), true)) {
            $choices[] = ['id' => $editor['payment_method_id'], 'name' => (string) $editor['payment_method_name']];
        }

        return Inertia::render('Admin/Orders/Form', [
            ...$this->shell($request, __('orders.number', ['number' => $editor['number']])), 'order' => $editor, ...$this->formOptions($request, $queries, $countries, $editor['customer_id'] ?: null),
            'currency' => ['code' => $editor['currency'], 'decimals' => $decimals],
            'paymentMethods' => $choices, 'paymentMethodLocked' => $methods->locked($order),
            'shippingMethods' => $this->shippingChoices($editor),
        ]);
    }

    public function update(Request $request, OrderAdministration $orders, string $order): RedirectResponse
    {
        $orders->update((string) $request->user()->id, $order, $request->except(['ip_address']));

        return redirect('/admin/orders/'.$order)->with('status', __('orders.saved'));
    }

    /** Server-side totals for the editor's current lines, without saving anything. */
    public function quote(Request $request, OrderInput $input, OrderPricing $pricing, OrderMoney $money, ?string $order = null): RedirectResponse
    {
        Gate::authorize($order === null ? 'orders.create' : 'orders.update');
        $data = $input->validate([...$request->except(['ip_address']), 'status_id' => 1, 'version' => 1], $order === null);
        $existing = $order === null ? [] : DB::table('order_items')->where('order_id', $order)->get()->keyBy(fn (object $i): string => (string) $i->id)->all();
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $exempt = ($data['customer_id'] ?? null) !== null && (bool) DB::table('customers')->where('id', $data['customer_id'])->value('tax_exempt');
        $customer = isset($data['customer_id']) ? (string) $data['customer_id'] : null;
        $quote = $pricing->quote($data['items'], $existing, ['billing' => $data['billing'], 'shipping' => $data['shipping']], $exempt, $data['locale'], $currency->decimals, $customer);
        $format = fn (string $value): string => $money->format($value, $currency->code, $currency->decimals);
        // Shipping is calculated on the server like at save time; a carrier may be asked (its quotes are cached).
        $shipping = null;
        $shippingCharge = null;
        if (isset($data['shipping_method_id'])) {
            $method = (string) $data['shipping_method_id'];
            $result = app(OrderShipping::class)->quote($method, [
                'lines' => array_map(fn (array $line): array => ['product_id' => $line['item_id'] !== null ? (($existing[$line['item_id']]->product_id ?? null) === null ? null : (string) $existing[$line['item_id']]->product_id) : $line['product_id'], 'quantity' => (int) $line['quantity']], $data['items']),
                'address' => $data['shipping'] ?? $data['billing'], 'currency' => $currency->code, 'decimals' => $currency->decimals, 'customer_id' => $customer,
                'payment_method_id' => isset($data['payment_method_id']) ? (string) $data['payment_method_id'] : null, 'subtotal' => $quote['subtotal'], 'shipment_value' => ShipmentValue::of($quote['lines']),
            ], $data['locale'], $order !== null && (string) DB::table('orders')->where('id', $order)->value('shipping_method_id') === $method);
            $override = isset($data['shipping_amount']) && $data['shipping_amount'] !== '' ? (string) $data['shipping_amount'] : null;
            $shipping = ['available' => $result['available'], 'message' => $result['message'], 'calculated' => $result['available'] ? $format($result['amount']) : null, 'overridden' => $override !== null];
            if ($result['available'] || $override !== null) {
                $shippingCharge = ['amount' => $override ?? $result['amount'], 'tax_ids' => $result['tax_ids'], 'id' => $method, 'available' => $result['available'], 'discountable' => $result['details']['promotion_discountable'] ?? ($override ?? $result['amount'])];
            }
        }

        // The fee the order would be charged: the chosen method's, or the one an existing order keeps with its method.
        $stored = $order === null ? null : DB::table('orders')->where('id', $order)->first(['payment_method_id', 'payment_fee']);
        $method = isset($data['payment_method_id']) ? (string) $data['payment_method_id'] : null;
        $fee = $stored !== null && $method === ($stored->payment_method_id === null ? null : (string) $stored->payment_method_id) ? (string) $stored->payment_fee
            : ($method === null ? null : DB::table('payment_methods')->where('id', (int) $method)->value('fee'));
        $quote = $pricing->quote($data['items'], $existing, ['billing' => $data['billing'], 'shipping' => $data['shipping']], $exempt, $data['locale'], $currency->decimals, $customer, false, $shippingCharge, $data['coupon_code'] ?? null, $data['email'], $order, $fee === null ? null : (string) $fee);

        return back()->with('order_quote', [
            'promotions' => array_map(fn (array $p): array => ['id' => $p['promotion_id'], 'name' => $p['name'], 'discount' => $format($p['discount']), 'shipping_discount' => $format($p['shipping_discount'])], $quote['promotions']),
            'discount' => $format($quote['discount_total']), 'coupon_code' => $quote['coupon_code'], 'shipping_discount' => $format($quote['shipping_discount']),
            'lines' => array_map(fn (array $l): array => ['key' => $l['item_id'] ?? 'p'.$l['product_id'], 'name' => $l['name'], 'sku' => $l['sku'], 'variation' => $l['variation'], 'unit_price' => $format($l['unit_price']), 'subtotal' => $format($l['subtotal']), 'tax' => $format($l['tax_total']), 'total' => $format($l['total'])], $quote['lines']),
            'taxes' => array_map(fn (array $t): array => ['name' => $t['name'], 'included' => $t['included'], 'amount' => $format($t['amount'])], $quote['taxes']),
            'subtotal' => $format($quote['subtotal']), 'tax' => $format($quote['tax_total']), 'tax_included' => $format($quote['tax_included']), 'shipping' => $format($quote['shipping_total']), 'total' => $format($quote['total']),
            'payment_fee' => Decimal::compare($quote['payment_fee'], '0') > 0 ? $format($quote['payment_fee']) : null,
            'unresolved' => $quote['unresolved'], 'exempt' => $exempt, 'shipping_status' => $shipping,
        ]);
    }

    public function bulkStatus(Request $request, OrderAdministration $orders): RedirectResponse
    {
        Gate::authorize('orders.status');
        $data = $request->validate(['orders' => ['required', 'array'], 'status_id' => ['required', 'integer', 'min:1'], 'return_to' => ['nullable', 'string']]);
        $changed = $orders->bulkStatus((string) $request->user()->id, $data['orders'], (string) $data['status_id']);
        $return = is_string($data['return_to'] ?? null) && preg_match('~^/admin/orders(?:\?[^\r\n#]*)?$~D', $data['return_to']) === 1 ? $data['return_to'] : '/admin/orders';

        return redirect($return)->with('status', __('orders.bulk_status_changed', ['count' => $changed]));
    }

    public function status(Request $request, OrderAdministration $orders, string $order): RedirectResponse
    {
        $data = $request->validate(['status_id' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:1000'], 'return_to' => ['nullable', 'string']]);
        $changed = $orders->changeStatus((string) $request->user()->id, $order, (int) $data['version'], (string) $data['status_id'], $data['note'] ?? null);
        $notification = $changed ? DB::table('order_notifications')->where('order_id', $order)->orderByDesc('id')->value('state') : null;
        $message = ! $changed ? __('orders.status_unchanged') : ($notification === 'sent' ? __('orders.status_changed') : __('orders.status_changed_email_failed'));
        $return = is_string($data['return_to'] ?? null) && preg_match('~^/admin/orders(?:/\d+)?(?:\?[^\r\n#]*)?$~D', $data['return_to']) === 1 ? $data['return_to'] : '/admin/orders/'.$order;

        return redirect($return)->with('status', $message);
    }

    public function comment(Request $request, OrderAdministration $orders, string $order): RedirectResponse
    {
        $data = $request->validate(['visibility' => ['required', Rule::in(['internal', 'customer'])], 'body' => ['required', 'string', 'max:5000']]);
        $orders->comment((string) $request->user()->id, $order, $data['visibility'], $data['body']);

        return redirect('/admin/orders/'.$order)->with('status', __('orders.comment_added'));
    }

    public function retry(Request $request, OrderNotifier $notifier, string $order, string $notification): RedirectResponse
    {
        $sent = $notifier->retry((string) $request->user()->id, $order, $notification);

        return redirect('/admin/orders/'.$order)->with('status', __($sent ? 'orders.retry_sent' : 'orders.retry_failed'));
    }

    /**
     * Active shipping methods, plus the order's current method even when it is no longer offered.
     *
     * @param  array<string, mixed>  $editor
     * @return list<array<string, mixed>>
     */
    private function shippingChoices(array $editor): array
    {
        $choices = app(OrderShipping::class)->choices(app()->getLocale());
        if ($editor['shipping_method_id'] !== '' && ! in_array($editor['shipping_method_id'], array_column($choices, 'id'), true)) {
            $choices[] = ['id' => $editor['shipping_method_id'], 'name' => (string) $editor['shipping_method_name'], 'calculation' => 'manual', 'payment_method_ids' => []];
        }

        return $choices;
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request, OrderQueries $queries, Countries $countries, ?string $customer): array
    {
        $search = $request->validate(['product_search' => ['nullable', 'string', 'max:100'], 'customer_search' => ['nullable', 'string', 'max:100']]);

        return [
            'statuses' => array_values(array_filter($queries->statuses(), fn (array $s): bool => $s['active'])),
            'products' => fn () => $queries->products($search['product_search'] ?? ''),
            'customers' => fn () => $queries->customers($search['customer_search'] ?? '', $customer),
            'countries' => $countries->options(), 'regions' => app(ShippingGeography::class)->regionOptions(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'quote' => $request->session()->get('order_quote'),
        ];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('orders.title'), 'href' => $current === null ? null : '/admin/orders']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
