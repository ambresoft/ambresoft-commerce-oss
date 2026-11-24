<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Customer\Application\Contract\CustomerBlacklist;
use App\Customer\Infrastructure\CustomerInput;
use App\Customer\Infrastructure\CustomerSelfService;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Marketing\Infrastructure\GiftCardRedemptions;
use App\Marketing\Infrastructure\NewsletterSubscribers;
use App\Order\Application\Contract\OrderShipping;
use App\Order\Application\Event\OrderPlaced;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderOrigin;
use App\Order\Infrastructure\OrderPricing;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Payment\Domain\Service\PaymentRules;
use App\Payment\Infrastructure\PaymentLinks;
use App\Payment\Infrastructure\PaymentMethodChoices;
use App\Payment\Infrastructure\PaymentMethodRestrictions;
use App\Payment\Infrastructure\PaymentSettings;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\CheckoutSettings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use App\Shipping\Domain\Service\ShipmentValue;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single-page checkout (/checkout): what the page shows, the live quote while the shopper fills it in, and placing
 * the order. Nothing that costs money comes from the browser: lines and quantities come from the cart, prices,
 * discounts, taxes and totals from OrderPricing, shipping charges from the Shipping context and the payment fee from the
 * chosen method. The order is created by OrderAdministration (source "checkout"), so its rules, address snapshots and
 * idempotent submission key apply unchanged.
 */
final class Checkout
{
    /** The order address fields; saved addresses keep the house number in the street line, as staff orders do. */
    private const FIELDS = ['first_name', 'last_name', 'company', 'line1', 'line2', 'city', 'postal_code', 'country_code', 'region_code', 'phone', 'floor', 'bell_name', 'notes'];

    private const TEXT = 'not_regex:/[\x00-\x1f\x7f]/';

    public function __construct(private CartPage $pages, private OrderShipping $shipping, private PaymentMethodChoices $choices, private PaymentSettings $payments) {}

    /**
     * @return array<string,mixed>
     */
    public function page(?object $cart, ?string $customer, string $locale): array
    {
        $cartData = $this->pages->data($cart, ['locale' => $locale, 'customer' => $customer]);
        $profile = $customer === null ? null : DB::table('customers')->where('id', $customer)->first();
        $id = fn ($value): ?string => $value === null ? null : (string) $value;
        $defaults = ['shipping' => $id($profile?->default_shipping_address_id), 'billing' => $id($profile?->default_billing_address_id)];
        $allowed = CustomerSelfService::countryCodes();
        $countries = app(Countries::class);
        $invoiceSettings = app(CheckoutSettings::class)->all();
        $invoiceCountries = InvoiceVerification::countryCodes($invoiceSettings);

        return [
            'cart' => $cartData,
            'account_required' => $customer === null && (bool) app(GeneralSettings::class)->all()['checkout_requires_account'],
            'contact' => $profile === null ? null : ['first_name' => $profile->first_name, 'last_name' => $profile->last_name, 'email' => $profile->email, 'phone' => $profile->phone ?? ''],
            'addresses' => $customer === null ? [] : DB::table('customer_addresses')->where('customer_id', $customer)->orderBy('id')->get()
                ->map(fn (object $a): array => ['id' => (string) $a->id, 'label' => $a->label, 'lines' => $this->lines($this->orderAddress((array) $a))])->all(),
            'defaults' => $defaults,
            'invoice_countries' => array_values(array_filter($countries->options(), fn (array $o): bool => in_array($o['code'], $invoiceCountries, true))),
            'invoice_greek_sign_in' => $invoiceSettings['greek_provider'] === 'aade',
            'countries' => array_values(array_filter($countries->options(), fn (array $o): bool => $allowed === null || in_array($o['code'], $allowed, true))),
            'regions' => app(ShippingGeography::class)->regionOptions(),
            'quote' => $this->quote($customer, $locale, ['shipping_address_id' => $defaults['shipping']], $cartData),
        ];
    }

    /**
     * The order as it would be placed with the shopper's current choices: the shipping methods that can deliver to the
     * address, the payment methods the products and the shipping method allow, and the totals.
     *
     * @param  array<string,mixed>  $input  addresses (saved IDs or typed) and the chosen methods
     * @param  array<string,mixed>  $cartData  CartPage::data() of the visitor's cart
     * @return array<string,mixed>
     */
    public function quote(?string $customer, string $locale, array $input, array $cartData): array
    {
        $items = [];
        foreach ($cartData['lines'] as $line) {
            if ($line['total'] !== null) {
                $items[] = ['product_id' => (string) $line['id'], 'quantity' => (int) $line['quantity']];
            }
        }
        $needsShipping = $items !== [] && DB::table('products')->whereIn('id', array_column($items, 'product_id'))->where('type', '<>', 'digital')->exists();
        // A store without shipping methods takes orders without one; otherwise a method that delivers here is required.
        $offered = $needsShipping ? $this->shipping->choices($locale) : [];
        $base = ['needs_shipping' => $needsShipping, 'shipping_required' => $offered !== [], 'blocked' => array_filter(array_column($cartData['lines'], 'problem')) !== [], 'items' => $items];
        if ($items === []) {
            return [...$base, 'shipping_methods' => [], 'shipping_method_id' => null, 'payment_methods' => [], 'payment_method_id' => null, 'totals' => null, 'discounts' => [], 'coupon' => null, 'coupon_code' => null, 'gift_card' => null];
        }
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $scale = $currency->decimals;
        $address = $this->address($input, 'shipping', $customer);
        $billing = ! $needsShipping || filter_var($input['billing_same'] ?? true, FILTER_VALIDATE_BOOLEAN) ? $address : $this->address($input, 'billing', $customer);
        $exempt = $customer !== null && (app(CustomerTaxProfiles::class)->context($customer)->exempt ?? false);
        $addresses = ['billing' => $billing, 'shipping' => $needsShipping ? $address : null];
        $coupon = $cartData['coupon'];
        $price = fn (?array $charge, ?string $fee, ?string $code): array => app(OrderPricing::class)->quote($items, [], $addresses, $exempt, $locale, $scale, $customer, false, $charge, $code, paymentFee: $fee);
        // The coupon is checked again against the real addresses; one that does not apply says why and is left out.
        try {
            $preview = $price(null, null, $coupon['code'] ?? null);
            if ($coupon !== null) {
                $coupon['problem'] = $preview['coupon_code'] === null ? __('coupons.no_products') : null;
            }
        } catch (ValidationException $e) {
            if ($coupon === null) {
                throw $e;
            }
            $coupon['problem'] = (string) collect($e->errors())->flatten()->first();
            $preview = $price(null, null, null);
        }
        $code = $preview['coupon_code'];

        // Payment methods the blacklist takes away from this buyer (e.g. cash on delivery), by their e-mail or any phone.
        $profile = $customer === null ? null : DB::table('customers')->where('id', $customer)->first(['email', 'phone']);
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? $value : null;
        $restricted = app(CustomerBlacklist::class)->restrictedPaymentMethods($profile->email ?? $text($input['email'] ?? null),
            [$text($input['phone'] ?? null), $profile->phone ?? null, $address['phone'] ?? null, $billing['phone'] ?? null]);
        $payments = array_diff_key($this->paymentMethods($items, $currency->code, $scale, $locale, (string) $preview['subtotal']), array_flip($restricted));
        $paymentId = $this->chosen($input['payment_method_id'] ?? null, $payments);
        $methods = [];
        if ($needsShipping && ($address['country_code'] ?? null) !== null) {
            foreach ($offered as $method) {
                $quote = $this->shipping->quote($method['id'], ['lines' => $items, 'address' => $address, 'currency' => $currency->code, 'decimals' => $scale, 'customer_id' => $customer, 'payment_method_id' => $paymentId, 'subtotal' => $preview['subtotal'], 'shipment_value' => ShipmentValue::of($preview['lines'])], $locale);
                if ($quote['available']) {
                    $methods[$method['id']] = ['id' => $method['id'], 'name' => $quote['name'], 'amount' => Decimal::round($quote['amount'], $scale), 'payment_method_ids' => $method['payment_method_ids'],
                        'charge' => ['amount' => $quote['amount'], 'tax_ids' => $quote['tax_ids'], 'id' => $method['id'], 'available' => true, 'discountable' => $quote['details']['promotion_discountable'] ?? $quote['amount']]];
                }
            }
        }
        // The first method that delivers is preselected; a payment method is always the shopper's own choice.
        $shippingId = $this->chosen($input['shipping_method_id'] ?? null, $methods) ?? array_key_first($methods);
        $shippingId = $shippingId === null ? null : (string) $shippingId;
        if ($shippingId !== null && $methods[$shippingId]['payment_method_ids'] !== []) {
            $payments = array_intersect_key($payments, array_flip($methods[$shippingId]['payment_method_ids']));
            $paymentId = isset($payments[$paymentId ?? '']) ? $paymentId : null;
        }
        $final = $price($shippingId === null ? null : $methods[$shippingId]['charge'], $paymentId === null ? null : $payments[$paymentId]['fee'], $code);
        $total = Decimal::round($final['total'], $scale);
        $giftCard = $this->giftCard($cartData, $total, $scale);
        $round = fn (string $amount): string => Decimal::round($amount, $scale);
        $shippingImages = DB::table('shipping_methods')->whereIn('id', array_keys($methods))->pluck('image_media_id', 'id');
        $images = app(PublicMedia::class)->images($shippingImages->values()->all());

        return [...$base,
            'shipping_methods' => array_values(array_map(fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'amount' => $m['amount'],
                'image' => app(PublicMedia::class)->url($images[$shippingImages[$m['id']] ?? ''] ?? null, 'shipping-method', (string) $m['id'])], $methods)),
            'shipping_method_id' => $shippingId,
            'payment_methods' => array_values($payments),
            'payment_method_id' => $paymentId,
            'totals' => [
                'subtotal' => $round($final['subtotal']), 'discount' => $round($final['discount_total']), 'shipping' => $shippingId === null ? null : $round($final['shipping_total']),
                'payment_fee' => $round($final['payment_fee']), 'tax_added' => $round(Decimal::subtract($final['tax_total'], $final['tax_included'])), 'tax_included' => $round($final['tax_included']),
                'total' => $total, 'gift_card' => $giftCard['amount'] ?? $round('0'), 'due' => $round(Decimal::subtract($total, $giftCard['amount'] ?? '0')),
            ],
            'discounts' => array_values(array_unique(array_column($final['promotions'], 'name'))),
            'coupon' => $coupon === null ? null : ['code' => $coupon['code'], 'problem' => $coupon['problem']],
            'coupon_code' => $code,
            'gift_card' => $giftCard,
        ];
    }

    /**
     * Places the order for the visitor's cart. A repeated submission (a double click, a retried request) answers with
     * the order it already placed. The order, the gift card payment and emptying the cart commit together.
     *
     * @param  array<string,mixed>  $input
     * @return string The order ID
     */
    public function place(Request $request, object $cart, ?string $customer, string $locale, array $input): string
    {
        if ($customer === null && app(GeneralSettings::class)->all()['checkout_requires_account']) {
            throw ValidationException::withMessages(['account' => __('storefront.checkout_account_required')]);
        }
        $profile = $customer === null ? null : DB::table('customers')->where('id', $customer)->first();
        $fields = app(CustomerInput::class);
        foreach (['first_name', 'last_name', 'email', 'phone', 'customer_note'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]) === '' ? null : trim($input[$field]);
            }
        }
        // A customer orders under their account's e-mail; only guests type one.
        $email = $profile !== null ? (string) $profile->email : (is_string($input['email'] ?? null) ? strtolower($input['email']) : '');
        $key = is_string($input['submission_key'] ?? null) ? $input['submission_key'] : '';
        $orders = app(OrderAdministration::class);
        if (Str::isUuid($key) && $email !== '' && ($previous = $orders->placed($customer, $email, $key)) !== null) {
            return $previous;
        }

        $cartData = $this->pages->data($cart, ['locale' => $locale, 'customer' => $customer]);
        $quote = $this->quote($customer, $locale, $input, $cartData);
        $savedShipping = $customer !== null && filled($input['shipping_address_id'] ?? null);
        $billingSame = ! $quote['needs_shipping'] || filter_var($input['billing_same'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $savedBilling = ! $billingSame && $customer !== null && filled($input['billing_address_id'] ?? null);
        foreach (['shipping', 'billing'] as $type) {
            if (is_array($input[$type] ?? null)) {
                $input[$type] = $fields->normalizedAddress($input[$type]);
            }
        }
        [$messages, $attributes] = $this->messages();
        Validator::make($input, [
            'submission_key' => ['required', 'uuid'],
            'email' => $profile === null ? ['required', 'string', 'max:254', 'email:rfc'] : ['nullable'],
            'first_name' => ['required', 'string', 'max:100', self::TEXT],
            'last_name' => ['required', 'string', 'max:100', self::TEXT],
            'phone' => ['required', 'string', 'max:40', $fields->phoneRule()],
            'billing_same' => ['sometimes', 'boolean'],
            'save_address' => ['sometimes', 'boolean'],
            'shipping_address_id' => ['nullable', 'integer', 'min:1'],
            'billing_address_id' => ['nullable', 'integer', 'min:1'],
            'shipping_method_id' => ['nullable', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'min:1'],
            'customer_note' => ['nullable', 'string', 'max:5000'],
            'accepted_pages' => ['sometimes', 'array', 'max:50'],
            'accepted_pages.*' => ['integer', 'min:1'],
            'newsletter' => ['sometimes', 'boolean'],
            'invoice_requested' => ['sometimes', 'boolean'],
            ...($savedShipping ? [] : $fields->addressRules('shipping.', [], true)),
            ...($billingSame || $savedBilling ? [] : $fields->addressRules('billing.', [], true)),
        ], $messages, $attributes)->validate();

        if ($quote['blocked']) {
            throw ValidationException::withMessages(['cart' => __('storefront.checkout_cart_changed')]);
        }
        if ($quote['items'] === []) {
            throw ValidationException::withMessages(['cart' => __('storefront.checkout_empty')]);
        }
        $address = $this->address($input, 'shipping', $customer) ?? throw ValidationException::withMessages(['shipping_address_id' => __('storefront.checkout_address_invalid')]);
        $shipsTo = app(ShippingGeography::class)->countries();
        if ($quote['needs_shipping'] && $shipsTo !== [] && ! in_array($address['country_code'], $shipsTo, true)) {
            throw ValidationException::withMessages([($savedShipping ? 'shipping_address_id' : 'shipping.country_code') => __('account.country_not_shipped')]);
        }
        $billing = $billingSame ? $address : ($this->address($input, 'billing', $customer) ?? throw ValidationException::withMessages(['billing_address_id' => __('storefront.checkout_address_invalid')]));
        if ((string) $input['payment_method_id'] !== $quote['payment_method_id']) {
            throw ValidationException::withMessages(['payment_method_id' => __('storefront.checkout_payment_unavailable')]);
        }
        $shippingId = null;
        if ($quote['shipping_required']) {
            if ($quote['shipping_methods'] === []) {
                throw ValidationException::withMessages(['shipping_method_id' => __('storefront.checkout_no_shipping')]);
            }
            if ((string) ($input['shipping_method_id'] ?? '') !== $quote['shipping_method_id']) {
                throw ValidationException::withMessages(['shipping_method_id' => __('storefront.checkout_shipping_unavailable')]);
            }
            $shippingId = $quote['shipping_method_id'];
        }
        // Pages the checkout block makes required (terms of use…) must be accepted.
        $options = app(CheckoutOptions::class)->current($locale, $customer);
        $accepted = array_map(strval(...), $input['accepted_pages'] ?? []);
        $unaccepted = array_filter($options['pages'], fn (array $page): bool => $page['required'] && ! in_array($page['id'], $accepted, true));
        if ($unaccepted !== []) {
            throw ValidationException::withMessages(['accepted_pages' => __('storefront.checkout_accept_required', ['pages' => implode(', ', array_column($unaccepted, 'name'))])]);
        }
        $invoice = $options['invoice'] && filter_var($input['invoice_requested'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $verification = $invoice ? app(InvoiceVerification::class)->forOrder($request, $cart, $customer, $input) : null;
        $status = DB::table('order_statuses')->where('is_initial', true)->where('is_active', true)->value('id')
            ?? throw ValidationException::withMessages(['cart' => __('storefront.checkout_unavailable')]);
        // Only server-decided values: never a price, a shipping charge, a status or an internal note from the browser.
        $order = [
            'submission_key' => $key, 'locale' => $locale, 'customer_id' => $customer, 'email' => $email,
            'first_name' => $input['first_name'], 'last_name' => $input['last_name'], 'phone' => $input['phone'],
            'billing' => $billing, 'shipping_same' => $billingSame, 'shipping' => $billingSame ? null : $address,
            'items' => $quote['items'], 'coupon_code' => $quote['coupon_code'], 'payment_method_id' => $quote['payment_method_id'], 'shipping_method_id' => $shippingId,
            'customer_note' => $input['customer_note'] ?? null, 'status_id' => (string) $status,
            'invoice_requested' => $invoice,
        ];
        $cardId = Decimal::compare($quote['gift_card']['amount'] ?? '0', '0') > 0 ? DB::table('carts')->where('id', $cart->id)->value('gift_card_id') : null;

        [$id, $placed] = DB::transaction(function () use ($request, $cart, $customer, $email, $key, $orders, $order, $cardId, $verification): array {
            // One checkout per cart at a time: a concurrent submission waits here, then finds the order already placed.
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            if (($previous = $orders->placed($customer, $email, $key)) !== null) {
                return [$previous, false];
            }
            $id = $orders->create(null, $order, 'checkout', OrderOrigin::fromRequest($request));
            if ($verification !== null) {
                DB::table('order_invoice_verifications')->insert(['order_id' => $id, ...$verification]);
            }
            if ($cardId !== null) {
                app(GiftCardRedemptions::class)->redeemAtCheckout($id, (string) $cardId);
            }
            // Only what was ordered leaves the cart; the codes are used up with the order.
            DB::table('cart_items')->where('cart_id', $cart->id)->whereIn('product_id', array_column($order['items'], 'product_id'))->delete();
            DB::table('carts')->where('id', $cart->id)->update(['coupon_id' => null, 'gift_card_id' => null, 'updated_at' => now()]);

            return [$id, true];
        });
        if ($placed) {
            // A listener can never undo or fail the order it is told about.
            rescue(fn () => event($this->placedEvent($id)));
        }
        if ($placed && $profile !== null && ! $savedShipping && filter_var($input['save_address'] ?? false, FILTER_VALIDATE_BOOLEAN) && $request->user() instanceof UserRecord) {
            // The order stands even if the address book refuses the copy (e.g. it changed in another tab meanwhile).
            rescue(fn () => app(CustomerSelfService::class)->addAddress($request->user(), (int) $profile->lock_version, $input['shipping']));
        }
        if ($placed && $options['newsletter'] !== null && filter_var($input['newsletter'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // Like the newsletter block: the list's confirmation e-mail is sent; a refused sign-up never undoes the order.
            rescue(fn () => app(NewsletterSubscribers::class)->subscribe($email, [$options['newsletter']['list']], 'storefront', false, $locale, $customer));
        }

        return $id;
    }

    private function placedEvent(string $id): OrderPlaced
    {
        $order = DB::table('orders')->where('id', $id)->first(['order_number', 'currency_code', 'currency_decimals', 'total', 'tax_total', 'shipping_total', 'coupon_code']);
        $items = DB::table('order_items')->where('order_id', $id)->orderBy('position')->get(['product_id', 'sku', 'name', 'variation', 'quantity', 'total'])
            ->map(fn (object $i): array => ['product_id' => $i->product_id === null ? null : (string) $i->product_id, 'sku' => $i->sku, 'name' => (string) $i->name,
                'variation' => $i->variation, 'quantity' => (int) $i->quantity, 'total' => Decimal::trim((string) $i->total)])->all();

        return new OrderPlaced($id, (string) $order->order_number, (string) $order->currency_code, (int) $order->currency_decimals, Decimal::trim((string) $order->total),
            Decimal::trim((string) $order->tax_total), Decimal::trim((string) $order->shipping_total), $order->coupon_code, $items);
    }

    /**
     * What the confirmation page shows: the order, what is still due and how to pay it.
     *
     * @return array<string,mixed>
     */
    public function confirmation(string $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first() ?? abort(404);
        $payments = DB::table('order_payments')->where('order_id', $orderId)->get(['state', 'amount'])->map(fn (object $p): array => ['state' => $p->state, 'amount' => Decimal::trim($p->amount)])->all();
        $due = PaymentRules::outstanding($payments, $order->total);
        $scale = (int) $order->currency_decimals;
        $row = $order->payment_method_id === null ? null : DB::table('payment_methods')->where('id', $order->payment_method_id)->first();
        $online = $row !== null && $this->payments->method($row) instanceof RedirectPaymentMethod;
        $payable = $online && Decimal::compare($due, '0') > 0 && $this->payments->ownerEnabled($row) && $this->payments->available($row, new PaymentAmount($due, $order->currency_code, $scale));
        $addresses = DB::table('order_addresses')->where('order_id', $orderId)->get()->keyBy('type');
        $delivery = $addresses['shipping'] ?? $addresses['billing'] ?? null;
        $round = fn ($amount): string => Decimal::round((string) $amount, $scale);
        $locale = (string) DB::table('locales')->where('id', $order->locale_id)->value('code');
        $items = DB::table('order_items')->where('order_id', $orderId)->orderBy('position')->get(['product_id', 'name', 'variation', 'quantity', 'total']);
        $customer = $order->customer_id === null ? null : (string) $order->customer_id;
        $cards = app(BlockContent::class)->productCards($items->pluck('product_id')->filter()->map(strval(...))->unique()->values()->all(), ['locale' => $locale, 'customer' => $customer], app(PublicCatalog::class)->products($customer))['cards'];

        return [
            'number' => $order->order_number, 'email' => $order->email,
            'lines' => $items->map(fn (object $i): array => ['name' => $i->name, 'variation' => $i->variation, 'quantity' => (int) $i->quantity, 'total' => $round($i->total),
                // The picture and link of a product the buyer can still see; none for one no longer sold.
                'image' => $cards[(string) $i->product_id]['image'] ?? null, 'url' => $cards[(string) $i->product_id]['url'] ?? null])->all(),
            'totals' => ['subtotal' => $round($order->subtotal), 'discount' => $round($order->discount_total), 'shipping' => $order->shipping_method_id === null ? null : $round($order->shipping_total),
                'payment_fee' => $round($order->payment_fee), 'tax_added' => $round(Decimal::subtract((string) $order->tax_total, (string) $order->tax_included)), 'total' => $round($order->total), 'due' => $round($due)],
            'shipping_method' => $order->shipping_method_name, 'invoice_requested' => (bool) $order->invoice_requested, 'note' => (string) $order->customer_note,
            'address' => $delivery === null ? null : $this->parts((array) $delivery),
            'payment' => ['name' => $order->payment_method_name, 'state' => PaymentRules::orderStatus($payments, $order->total),
                // Offline methods (bank deposit, cash on delivery) tell the customer what to do; online ones offer to pay.
                'instructions' => $row === null || $online || Decimal::compare($due, '0') <= 0 ? '' : $this->payments->description((string) $row->id, $locale),
                // A payment already being confirmed must not be offered again.
                'pay_url' => $payable && PaymentRules::orderStatus($payments, $order->total) !== 'pending' ? app(PaymentLinks::class)->payUrl($orderId) : null],
            'account_url' => $order->customer_id === null ? null : '/account/orders/'.$order->id,
            'currency_format' => app(BlockContent::class)->currencyFormat(),
        ];
    }

    /**
     * Payment methods offered for these products: active, configured, not restricted by the products or by their
     * maximum order subtotal, with their fee.
     *
     * @param  list<array{product_id:string,quantity:int}>  $items
     * @return array<string, array{id:string,name:string,description:string,fee:?string,image:?array<string,mixed>}>
     */
    private function paymentMethods(array $items, string $currency, int $scale, string $locale, string $subtotal): array
    {
        $blocked = app(PaymentMethodRestrictions::class)->forProducts(array_column($items, 'product_id'));
        $rows = DB::table('payment_methods')->get(['id', 'code', 'fee', 'max_subtotal', 'image_media_id'])->keyBy(fn (object $r): string => (string) $r->id);
        $images = app(PublicMedia::class)->images($rows->pluck('image_media_id')->all());
        $methods = [];
        foreach ($this->choices->choices($currency, $scale, $locale) as $choice) {
            $row = $rows[$choice['id']] ?? null;
            if ($row === null || ($blocked[$row->code] ?? null) !== null || ($row->max_subtotal !== null && Decimal::compare($subtotal, (string) $row->max_subtotal) > 0)) {
                continue;
            }
            $fee = $row->fee === null || Decimal::compare((string) $row->fee, '0') <= 0 ? null : Decimal::round((string) $row->fee, $scale);
            $methods[$choice['id']] = ['id' => $choice['id'], 'name' => $choice['name'], 'description' => $this->payments->description($choice['id'], $locale), 'fee' => $fee,
                'image' => app(PublicMedia::class)->url($images[$row->image_media_id] ?? null, 'payment-method', $choice['id'])];
        }

        return $methods;
    }

    /** @param array<string, mixed> $options */
    private function chosen(mixed $value, array $options): ?string
    {
        return is_scalar($value) && isset($options[(string) $value]) ? (string) $value : null;
    }

    /**
     * The shopper's saved address (only ever one of their own) or the one typed in, in the order address shape.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,?string>|null
     */
    private function address(array $input, string $type, ?string $customer): ?array
    {
        $id = $input[$type.'_address_id'] ?? null;
        if ($customer !== null && is_scalar($id) && preg_match('/^[1-9]\d{0,18}$/D', (string) $id) === 1) {
            $row = DB::table('customer_addresses')->where('id', $id)->where('customer_id', $customer)->first();

            return $row === null ? null : $this->orderAddress((array) $row);
        }

        return is_array($input[$type] ?? null) ? $this->orderAddress(app(CustomerInput::class)->normalizedAddress($input[$type])) : null;
    }

    /**
     * @param  array<string,mixed>  $a
     * @return array<string,?string>
     */
    private function orderAddress(array $a): array
    {
        $text = fn ($v, int $limit = 200): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $limit) : null;
        $address = [];
        foreach (self::FIELDS as $field) {
            $address[$field] = $text($a[$field] ?? null, $field === 'notes' ? 500 : 200);
        }
        $address['line1'] = $text(trim(($address['line1'] ?? '').' '.($text($a['street_number'] ?? null) ?? '')));

        return $address;
    }

    /**
     * An address for the confirmation page: who, where, how to reach them, and the courier's details apart.
     *
     * @param  array<string,mixed>  $a
     * @return array{name:string,lines:list<string>,phone:?string,extras:list<array{label:string,value:string}>}
     */
    private function parts(array $a): array
    {
        $country = $a['country_code'] === null ? '' : app(Countries::class)->name((string) $a['country_code']);
        $extras = [];
        foreach (['floor' => 'floor', 'bell_name' => 'bell_name', 'notes' => 'address_notes'] as $field => $key) {
            if (($a[$field] ?? '') !== '' && $a[$field] !== null) {
                $extras[] = ['label' => __('customers.'.$key), 'value' => (string) $a[$field]];
            }
        }

        return ['name' => trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')), 'phone' => ($a['phone'] ?? '') === '' ? null : $a['phone'], 'extras' => $extras,
            'lines' => array_values(array_filter([$a['company'] ?? null, $a['line1'] ?? null, $a['line2'] ?? null, trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')), $country], fn ($line): bool => is_string($line) && $line !== ''))];
    }

    /**
     * An address as the lines printed on a label.
     *
     * @param  array<string,mixed>  $a
     * @return list<string>
     */
    private function lines(array $a): array
    {
        $country = $a['country_code'] === null ? '' : app(Countries::class)->name((string) $a['country_code']);

        return array_values(array_filter([trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')), $a['company'] ?? null, $a['line1'] ?? null, $a['line2'] ?? null,
            trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')), $country, $a['phone'] ?? null, ...array_filter([
                ($a['floor'] ?? null) !== null && $a['floor'] !== '' ? __('customers.floor').': '.$a['floor'] : null,
                ($a['bell_name'] ?? null) !== null && $a['bell_name'] !== '' ? __('customers.bell_name').': '.$a['bell_name'] : null,
                ($a['notes'] ?? null) !== null && $a['notes'] !== '' ? __('customers.address_notes').': '.$a['notes'] : null,
            ])], fn ($line): bool => is_string($line) && $line !== ''));
    }

    /**
     * The cart's gift card against this total: what it pays, or why it cannot.
     *
     * @param  array<string,mixed>  $cartData
     * @return array{code:string,amount:?string,problem:?string}|null
     */
    private function giftCard(array $cartData, string $total, int $scale): ?array
    {
        $card = $cartData['gift_card'];
        if ($card === null || $card['problem'] !== null) {
            return $card;
        }
        $balance = (string) DB::table('carts as c')->join('gift_cards as g', 'g.id', '=', 'c.gift_card_id')->where('c.uuid', $cartData['id'])->value('g.balance');

        return [...$card, 'amount' => Decimal::round(GiftCardRules::applicable($balance, $total), $scale)];
    }

    /**
     * Address messages and field names for both checkout addresses.
     *
     * @return array{array<string,string>, array<string,string>}
     */
    private function messages(): array
    {
        $messages = app(CustomerInput::class)->messages();
        $attributes = ['first_name' => __('customers.first_name'), 'last_name' => __('customers.last_name'), 'email' => __('customers.email'), 'phone' => __('customers.phone'), 'customer_note' => __('storefront.checkout_note')];
        foreach (['shipping.', 'billing.'] as $prefix) {
            $messages += [$prefix.'country_code.in' => __('customers.country_invalid'), $prefix.'postal_code.regex' => __('customers.postal_code_invalid'), $prefix.'phone.regex' => __('customers.phone_invalid')];
            foreach (['first_name', 'last_name', 'company', 'line1', 'street_number', 'line2', 'city', 'postal_code', 'phone', 'floor', 'bell_name'] as $field) {
                $attributes[$prefix.$field] = __('customers.'.$field);
            }
            $attributes[$prefix.'notes'] = __('customers.address_notes');
            $attributes[$prefix.'country_code'] = __('customers.country');
            $attributes[$prefix.'region_code'] = __('customers.region');
        }

        return [$messages, $attributes];
    }
}
