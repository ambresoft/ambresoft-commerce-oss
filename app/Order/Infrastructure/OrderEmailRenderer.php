<?php

namespace App\Order\Infrastructure;

use App\Order\Domain\Service\Code128;
use App\Order\Domain\Service\TemplateTokens;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\CompanySettings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns templates and a saved order (or representative sample data) into a customer email: the language's editable
 * header, the template body and the footer, inside one responsive document. Values come only from the allow-listed
 * variables built from the order's snapshots; text values are HTML-escaped. The shop logo travels as an inline image
 * (cid:store-logo), so the private logo file never needs a public URL.
 */
final class OrderEmailRenderer
{
    public const LOGO_CID = 'store-logo';

    public function __construct(private TemplateTokens $tokens, private OrderMoney $money, private Countries $countries) {}

    /**
     * @param  array{text: array<string, string>, html: array<string, string>}  $variables
     * @param  array{header_html: string, footer_html: string}|null  $layout  Unsaved layout for previews; null uses the saved one.
     * @return array{subject: string, html: string, text: string, inline: list<array{cid: string, mime: string, content: string}>}
     */
    public function render(string $subject, string $body, array $variables, string $locale, ?array $layout = null): array
    {
        $layout ??= $this->layout($locale);
        $subject = mb_substr(trim(preg_replace('/\s+/', ' ', $this->tokens->render($subject, $variables['text'])) ?? ''), 0, 300);
        $header = $this->tokens->render($layout['header_html'], $variables['html']);
        $content = $this->tokens->render($body, $variables['html']);
        $footer = $this->tokens->render($layout['footer_html'], $variables['html']);
        $html = $this->document($header, $content, $footer, $subject, $locale);
        $inline = str_contains($html, 'cid:'.self::LOGO_CID) ? $this->inlineImages() : [];

        return ['subject' => $subject, 'html' => $html, 'text' => $this->text($header."\n".$content."\n".$footer), 'inline' => $inline];
    }

    /** @return list<array{cid: string, mime: string, content: string}> */
    public function inlineImages(): array
    {
        $logo = $this->logo();

        return $logo === null ? [] : [['cid' => self::LOGO_CID, ...$logo]];
    }

    /** Admin preview: the inline logo becomes a data URI, so the browser shows exactly what will be sent. */
    public function preview(string $html): string
    {
        $logo = $this->logo();

        return $logo === null ? $html : str_replace('cid:'.self::LOGO_CID, 'data:'.$logo['mime'].';base64,'.base64_encode($logo['content']), $html);
    }

    /** @return array{text: array<string, string>, html: array<string, string>} */
    public function forOrder(string $orderId, ?string $previousStatusId, string $statusId, string $changedAt): array
    {
        $model = $this->model($orderId);
        $model['status'] = $this->statusName($statusId, $model['locale']);
        $model['previous'] = $previousStatusId === null ? '' : $this->statusName($previousStatusId, $model['locale']);
        $model['changed_at'] = $this->date($changedAt);
        // Only e-mails about a status that lets the customer cancel carry the link, and only while it still works.
        $cancellation = app(OrderCancellation::class);
        $model['cancel_url'] = DB::table('order_statuses')->where('id', $statusId)->value('customer_can_cancel') && $cancellation->allowed($orderId) ? $cancellation->url($orderId) : null;

        return $this->variables($model);
    }

    /**
     * Representative, clearly fictitious values for previews and test emails. Nothing is stored.
     *
     * @param  array<string,string>  $invoiceOverrides
     * @return array{text: array<string, string>, html: array<string, string>}
     */
    public function sample(string $locale, ?string $statusId, array $invoiceOverrides = []): array
    {
        return $this->variables($this->sampleModel($locale, $statusId, $invoiceOverrides));
    }

    /**
     * @param  array<string,string>  $invoiceOverrides
     * @return array<string, mixed>
     */
    private function sampleModel(string $locale, ?string $statusId, array $invoiceOverrides = []): array
    {
        $currency = app(CurrencyRepository::class)->currentPrimary();
        $amount = fn (string $value): string => $this->money->format($value, $currency->code, $currency->decimals);
        $address = (object) ['first_name' => 'Maria', 'last_name' => 'Example', 'company' => null, 'line1' => 'Ermou 1', 'line2' => null, 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR', 'region_code' => null, 'phone' => '+30 210 000 0000'];
        $initial = DB::table('order_statuses')->where('is_initial', true)->value('id');
        $statusId ??= $initial === null ? null : (string) $initial;
        $previous = $initial !== null && (string) $initial !== $statusId ? $this->statusName((string) $initial, $locale) : '';

        return [
            'locale' => $locale, 'number' => '100123', 'date' => $this->date(now()->subDay()->toIso8601String()), 'changed_at' => $this->date(now()->toIso8601String()),
            'status' => $statusId === null ? '' : $this->statusName($statusId, $locale), 'previous' => $previous,
            'first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test', 'phone' => '+30 210 000 0000',
            'note' => __('orders.preview_note', [], $locale), 'currency' => $currency->code,
            'payment_method' => __('orders.preview_payment_method', [], $locale), 'shipping_method' => __('orders.preview_shipping_method', [], $locale),
            'amounts' => ['subtotal' => $amount('50'), 'discount' => $amount('0'), 'tax' => $amount('9.68'), 'shipping' => $amount('0'), 'payment_fee' => $amount('0'), 'has_payment_fee' => false, 'total' => $amount('50'), 'has_discount' => false],
            'items' => [['name' => __('orders.preview_product', [], $locale), 'sku' => 'SAMPLE-1', 'variation' => '', 'quantity' => 2, 'unit' => $amount('25'), 'total' => $amount('50'), 'unit_net' => $amount('20.16'), 'vat_rate' => '24%', 'unit_gross' => $amount('25'), 'line_total' => $amount('50'), 'line_net' => $amount('40.32'), 'line_vat' => $amount('9.68'), 'original_unit_net' => $amount('20.16'), 'sale_discount' => '', 'discount' => '']],
            'taxes' => [['name' => 'VAT', 'rate' => '24%', 'included' => true, 'registration' => '', 'amount' => $amount('9.68')]],
            'invoice_overrides' => $invoiceOverrides, 'adjustments' => [], 'payments' => [],
            'invoice_details' => (object) ['country_code' => 'GR', 'vat_number' => '000000012', 'business_name' => 'Example Company (demo)'],
            'billing' => $address, 'shipping' => $address,
            'cancel_url' => $statusId !== null && DB::table('order_statuses')->where('id', $statusId)->value('customer_can_cancel') ? url('/orders/100123/cancel') : null,
        ];
    }

    /** A complete printable invoice document (logo embedded) for the admin "Print invoice" page. */
    public function invoicePage(string $orderId): string
    {
        $model = $this->model($orderId);
        $model['status'] = $this->statusName((string) DB::table('orders')->where('id', $orderId)->value('status_id'), $model['locale']);

        return $this->page($model['locale'], __('orders.invoice_order', ['number' => $model['number']], $model['locale']), $this->variables($model)['html']['invoice']);
    }

    /** The printable packing slip (admin "Print packing slip"), in the order's language. */
    public function packingSlipPage(string $orderId): string
    {
        $model = $this->model($orderId);
        $model['status'] = $this->statusName((string) DB::table('orders')->where('id', $orderId)->value('status_id'), $model['locale']);

        return $this->page($model['locale'], __('orders.packing_slip_for', ['number' => $model['number']], $model['locale']), $this->packingSlip($model));
    }

    /**
     * The packing slip editor's preview: unsaved content with sample data.
     *
     * @param  array{body?: string, item?: string}  $overrides
     */
    public function packingSlipPreview(string $locale, array $overrides): string
    {
        return $this->page($locale, __('orders.packing_slip_title', [], $locale), $this->packingSlip($this->sampleModel($locale, null), $overrides));
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array{body?: string, item?: string}  $overrides
     */
    private function packingSlip(array $m, array $overrides = []): string
    {
        $locale = $m['locale'];
        $values = $this->variables($m)['html'];
        // A parcel always has a destination: without a separate shipping address it goes to the billing address.
        $values['shipping_address'] = $values['shipping_address'] !== '' ? $values['shipping_address'] : $values['billing_address'];
        $layout = $this->layout($locale);
        $row = $overrides['item'] ?? $layout['packing_slip_item_html'];
        $values['packing_items'] = implode('', array_map(fn (array $item): string => $this->tokens->render($row, [...$values,
            'item_name' => e((string) $item['name']), 'item_sku' => e((string) $item['sku']), 'item_variation' => e((string) $item['variation']), 'item_quantity' => e((string) $item['quantity'])]), $m['items']));
        $values['order_barcode'] = Code128::svg((string) $m['number']);
        // Status, payment and shipping method: only the ones the order has.
        $details = array_filter(['invoice_status' => (string) $m['status'], 'packing_payment_method' => (string) ($m['payment_method'] ?? ''), 'packing_shipping_method' => (string) ($m['shipping_method'] ?? '')], fn (string $value): bool => trim($value) !== '');
        $values['packing_details'] = $details === [] ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px"><tbody>'
            .implode('', array_map(fn (string $key, string $value): string => '<tr><td style="padding:2px 16px 2px 0; white-space:nowrap">'.e(__('orders.'.$key, [], $locale)).':</td><td style="padding:2px 0; color:#4b5563">'.e($value).'</td></tr>', array_keys($details), $details))
            .'</tbody></table>';
        $note = trim((string) ($m['note'] ?? ''));
        $values['packing_note'] = $note === '' ? '' : '<div style="font-weight:bold">'.e(__('orders.packing_note', [], $locale)).':</div><div style="white-space:pre-line">'.e($note).'</div>';

        return $this->tokens->render($overrides['body'] ?? $layout['packing_slip_body_html'], $values);
    }

    /** A standalone printable document (logo embedded). */
    private function page(string $locale, string $title, string $content): string
    {
        return $this->preview('<!DOCTYPE html><html lang="'.e($locale).'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
            .e($title).'</title><style>@media print{body{margin:0}}</style></head>'
            .'<body style="margin:0; padding:24px; background:#ffffff; font-family:Arial,Helvetica,sans-serif; color:#1f2937"><div style="max-width:760px; margin:0 auto">'.$content.'</div></body></html>');
    }

    /** @return array{address: string, name: string, replyTo: ?string}|null Sender from Settings → General → Emails; null when missing. */
    public function sender(): ?array
    {
        $settings = app(GeneralSettings::class)->all();
        $address = $settings['order_email_from'];
        if (! is_string($address) || $address === '') {
            return null;
        }
        $name = $settings['order_email_from_name'] ?: app(CompanySettings::class)->all()['name'] ?: config('app.name');
        $reply = $settings['order_email_reply_to'];

        return ['address' => $address, 'name' => (string) $name, 'replyTo' => is_string($reply) && $reply !== '' && $reply !== $address ? $reply : null];
    }

    /** @return array{header_html: string, footer_html: string, invoice_subject: string, invoice_body_html: string, invoice_item_html: string, invoice_total_html: string, packing_slip_body_html: string, packing_slip_item_html: string} */
    public function layout(string $locale): array
    {
        $row = DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', $locale)->first(['t.*'])
            ?? DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.is_default', true)->first(['t.*']);

        $packing = ['packing_slip_body_html' => $row->packing_slip_body_html ?? PackingSlipLayout::document($locale), 'packing_slip_item_html' => $row->packing_slip_item_html ?? PackingSlipLayout::item()];

        return $row === null ? [...OrderEmailDefaults::layout($locale), 'invoice_item_html' => InvoiceLayout::item($locale), 'invoice_total_html' => InvoiceLayout::total(), ...$packing] : ['header_html' => $row->header_html, 'footer_html' => $row->footer_html, 'invoice_subject' => $row->invoice_subject, 'invoice_body_html' => InvoiceLayout::expand($row->invoice_body_html, $locale), 'invoice_item_html' => $row->invoice_item_html ?? InvoiceLayout::item($locale), 'invoice_total_html' => $row->invoice_total_html ?? InvoiceLayout::total(), ...$packing];
    }

    /** @return array<string, mixed> The order's own snapshots, formatted in its language and currency. */
    private function model(string $orderId): array
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('o.id', $orderId)->first(['o.*', 'l.code as locale_code']);
        $amount = fn (string $value): string => $this->money->format($value, $order->currency_code, (int) $order->currency_decimals);
        $addresses = DB::table('order_addresses')->where('order_id', $orderId)->get()->keyBy('type');
        $orderTaxes = DB::table('order_taxes')->where('order_id', $orderId)->orderBy('priority')->orderBy('tax_id')->get();

        return [
            'locale' => $order->locale_code, 'number' => $order->order_number, 'date' => $this->date($order->created_at), 'changed_at' => '', 'status' => '', 'previous' => '',
            'first_name' => $order->first_name, 'last_name' => $order->last_name, 'email' => $order->email, 'phone' => (string) $order->phone,
            'note' => (string) $order->customer_note, 'currency' => $order->currency_code,
            'invoice_details' => $order->invoice_requested ? DB::table('order_invoice_verifications')->where('order_id', $orderId)->first(['country_code', 'vat_number', 'business_name']) : null,
            'payment_method' => (string) $order->payment_method_name, 'shipping_method' => (string) $order->shipping_method_name,
            'amounts' => ['subtotal' => $amount($order->subtotal), 'discount' => $amount($order->discount_total), 'tax' => $amount($order->tax_total), 'shipping' => $amount($order->shipping_total), 'payment_fee' => $amount($order->payment_fee), 'has_payment_fee' => Decimal::compare(Decimal::trim($order->payment_fee), '0') > 0, 'total' => $amount($order->total), 'has_discount' => Decimal::compare(Decimal::trim($order->discount_total), '0') > 0],
            'items' => DB::table('order_items')->where('order_id', $orderId)->orderBy('position')->get()->map(function (object $i) use ($amount, $orderTaxes, $order): array {
                $vat = app(OrderLineVat::class)->line($i, $orderTaxes);

                return ['name' => $i->name, 'sku' => (string) $i->sku, 'variation' => (string) $i->variation, 'quantity' => (int) $i->quantity, 'unit' => $amount($i->unit_price), 'total' => $amount($i->subtotal),
                    'unit_net' => $amount($vat['unit_net']), 'vat_rate' => $vat['vat_rate'].'%', 'unit_gross' => $amount($vat['unit_gross']), 'line_total' => $amount($vat['gross']), 'line_net' => $amount($vat['net']), 'line_vat' => $amount($vat['vat']), ...$this->discountDetails($i, $vat, $amount, $order->locale_code)];
            })->all(),
            'taxes' => $orderTaxes->map(fn (object $t): array => [
                'name' => $t->name, 'rate' => $t->rate_type === 'percent' && $t->rate_value !== null ? Decimal::trim(Decimal::multiply(Decimal::trim($t->rate_value), '100')).'%' : '',
                'included' => (bool) $t->included, 'registration' => (string) $t->registration_number, 'amount' => $amount($t->amount),
            ])->all(),
            'adjustments' => $this->adjustments($order, $amount),
            'payments' => $this->paymentSummary($order, $amount),
            // Without a separate shipping address the order ships to the billing address.
            'billing' => $addresses->get('billing'), 'shipping' => $addresses->get('shipping') ?? $addresses->get('billing'),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array{text: array<string, string>, html: array<string, string>}
     */
    private function variables(array $m): array
    {
        $locale = $m['locale'];
        $shop = $this->storeVariables($locale);
        $text = [
            'order_number' => $m['number'], 'order_date' => $m['date'], 'previous_status' => $m['previous'], 'current_status' => $m['status'], 'status_changed_at' => $m['changed_at'],
            'customer_name' => trim($m['first_name'].' '.$m['last_name']), 'customer_first_name' => $m['first_name'], 'customer_last_name' => $m['last_name'],
            'customer_email' => $m['email'], 'customer_phone' => $m['phone'], 'order_currency' => $m['currency'],
            'subtotal' => $m['amounts']['subtotal'], 'discount_total' => $m['amounts']['discount'], 'tax_total' => $m['amounts']['tax'], 'shipping_total' => $m['amounts']['shipping'], 'payment_fee' => $m['amounts']['payment_fee'], 'grand_total' => $m['amounts']['total'],
            'customer_note' => $m['note'], 'payment_method' => (string) ($m['payment_method'] ?? ''), 'shipping_method' => (string) ($m['shipping_method'] ?? ''), ...$shop['text'],
        ];
        $html = array_map(fn (string $value): string => nl2br(e($value), false), $text);
        $html = [...$html, ...$shop['html']];
        $text['order_items'] = implode("\n", array_map(fn (array $i): string => $i['quantity'].' × '.$i['name'].($i['sku'] !== '' ? ' ('.$i['sku'].')' : '').' — '.$i['total'], $m['items']));
        $html['order_items'] = $this->itemsTable($m['items'], $locale);
        foreach (['billing', 'shipping'] as $type) {
            $lines = $m[$type] === null ? [] : $this->addressLines($m[$type], $locale);
            $text[$type.'_address'] = implode(', ', $lines);
            $html[$type.'_address'] = implode('<br>', array_map(fn (string $line): string => e($line), $lines));
        }
        $details = $this->invoiceDetails($m['invoice_details'] ?? null, $locale);
        $text['invoice_customer_details'] = $details['text'];
        $html['invoice_customer_details'] = $details['html'];
        $cancel = $m['cancel_url'] ?? null;
        $text['cancel_link'] = $cancel === null ? '' : __('orders.cancel_email_text', [], $locale).' '.$cancel;
        $html['cancel_link'] = $cancel === null ? '' : '<p style="margin:24px 0 0;font-size:14px;color:#475569">'.e(__('orders.cancel_email_text', [], $locale)).' <a href="'.e($cancel).'" style="color:#b91c1c;font-weight:600">'.e(__('orders.cancel_email_link', [], $locale)).'</a></p>';
        $text['invoice'] = __('orders.invoice_order', ['number' => $m['number']], $locale);
        $invoice = $this->invoice($m, $html);
        $html = [...$html, ...$invoice];

        return ['text' => $text, 'html' => $html];
    }

    /**
     * The shop's own placeholders (name, contact details, address, logo) for the shared header and footer. Any customer
     * email, whether about an order or not, uses these so it looks the same.
     *
     * @return array{text: array<string, string>, html: array<string, string>}
     */
    public function storeVariables(string $locale): array
    {
        $company = app(CompanySettings::class)->all();
        $store = (string) ($company['name'] ?: config('app.name'));
        $text = [
            'store_name' => $store, 'store_email' => (string) ($company['email_customers'] ?: $company['email_orders'] ?: ''),
            'store_phone' => (string) ($company['phone'] ?? ''), 'store_website' => (string) ($company['website'] ?? ''), 'store_address' => $this->storeAddress($company, $locale),
        ];
        $text['store_contact'] = implode(' · ', array_filter([$text['store_email'], $text['store_phone'], $text['store_website']]));
        $greek = ($company['country_code'] ?? null) === 'GR';
        foreach (['legal_name', 'business_activity', 'tax_id', 'tax_office', 'gemi_number'] as $field) {
            $text['store_'.$field] = $field === 'gemi_number' && ! $greek ? '' : (string) ($company[$field] ?? '');
        }
        // The order summary's store details: only the filled-in ones, labelled in the e-mail's language.
        $details = array_filter(['invoice_phone' => $text['store_phone'], 'invoice_website' => $text['store_website'], 'invoice_email' => $text['store_email'],
            'invoice_legal_name' => $text['store_legal_name'], 'invoice_business_activity' => $text['store_business_activity'], 'invoice_tax_id' => $text['store_tax_id'],
            'invoice_tax_office' => $text['store_tax_office'], 'invoice_gemi_number' => $text['store_gemi_number']], fn (string $value): bool => trim($value) !== '');
        $text['store_details'] = implode("\n", array_map(fn (string $key, string $value): string => __('orders.'.$key, [], $locale).': '.$value, array_keys($details), $details));
        $html = array_map(fn (string $value): string => nl2br(e($value), false), $text);
        $html['store_details'] = $details === [] ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:4px; font-size:14px"><tbody>'
            .implode('', array_map(fn (string $key, string $value): string => '<tr><td style="padding:1px 12px 1px 0; color:#4b5563; white-space:nowrap; vertical-align:top">'.e(__('orders.'.$key, [], $locale)).':</td><td style="padding:1px 0">'.e($value).'</td></tr>', array_keys($details), $details))
            .'</tbody></table>';
        $text['store_logo'] = $store;
        $html['store_logo'] = $this->logoTag($store, 'max-width:220px; max-height:80px', 'font-size:22px');

        return ['text' => $text, 'html' => $html];
    }

    private function logoTag(string $store, string $imageSize, string $textSize): string
    {
        return $this->logo() === null
            ? '<span style="'.$textSize.'; font-weight:bold; color:inherit">'.e($store).'</span>'
            : '<img src="cid:'.self::LOGO_CID.'" alt="'.e($store).'" style="display:block; '.$imageSize.'; height:auto; border:0">';
    }

    /** @return array{text: string, html: string} */
    private function invoiceDetails(?object $details, string $locale): array
    {
        if ($details === null) {
            return ['text' => '', 'html' => ''];
        }
        $rows = array_filter([
            'business_name' => trim((string) $details->business_name),
            'vat_number' => $details->vat_number,
            'country' => $this->countries->name($details->country_code, $locale).' ('.$details->country_code.')',
        ], fn (string $value): bool => $value !== '');
        $label = fn (string $key): string => __('checkout.'.$key, [], $locale);
        $text = implode('; ', array_map(fn (string $key, string $value): string => $label($key).': '.$value, array_keys($rows), $rows));
        $html = '<div style="margin:0 0 20px; page-break-inside:avoid; break-inside:avoid">'
            .'<h2 style="margin:0 0 6px; font-size:20px; font-weight:bold; line-height:1.5">'.e($label('modal_title')).'</h2>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; line-height:1.5"><tbody>'
            .implode('', array_map(fn (string $key, string $value): string => '<tr><td valign="top" style="width:180px; padding:1px 12px 1px 0; color:#4b5563">'.e($label($key)).':</td><td valign="top" style="padding:1px 0; overflow-wrap:anywhere">'.e($value).'</td></tr>', array_keys($rows), $rows))
            .'</tbody></table></div>';

        return ['text' => $text, 'html' => $html];
    }

    /**
     * The invoice document: shop and order details, addresses, lines, taxes and totals.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string,string>  $values
     * @return array<string,string>
     */
    private function invoice(array $m, array $values): array
    {
        $locale = $m['locale'];
        $t = fn (string $key): string => e(__('orders.'.$key, [], $locale));
        $company = app(CompanySettings::class)->all();
        $values['invoice_logo'] = $this->logoTag((string) ($company['name'] ?: config('app.name')), 'max-width:240px; max-height:80px', 'font-size:26px');
        $values['invoice_store_address'] = implode('<br>', array_map(e(...), array_filter([$company['address'] ?? null, trim(($company['city'] ?? '').' '.($company['postal_code'] ?? '')), $company['country_code'] ? $this->countries->name($company['country_code'], $locale) : null])));
        $layout = $this->layout($locale);
        $overrides = $m['invoice_overrides'] ?? [];
        $itemTemplate = $overrides['item'] ?? $layout['invoice_item_html'];
        $totalTemplate = $overrides['total'] ?? $layout['invoice_total_html'];
        $items = '';
        foreach ($m['items'] as $item) {
            $row = [];
            foreach (['name', 'sku', 'variation', 'quantity', 'unit_net', 'vat_rate', 'unit_gross', 'line_net', 'line_vat', 'line_total', 'original_unit_net'] as $key) {
                $row['item_'.$key] = e((string) ($item[$key] ?? ''));
            }
            $row['item_sale_discount'] = e($item['sale_discount'] ?? '');
            $row['item_discount'] = e($item['discount'] ?? '');
            $items .= $this->tokens->render($itemTemplate, [...$values, ...$row]);
        }
        $total = fn (string $label, string $value): string => $this->tokens->render($totalTemplate, [...$values, 'invoice_total_label' => $label, 'invoice_total_value' => $value]);
        $totals = $total('<strong>'.$t('invoice_subtotal').':</strong>', e($m['amounts']['subtotal']));
        if ($m['amounts']['has_discount']) {
            $totals .= $total('<strong>'.$t('invoice_discount').':</strong>', '−'.e($m['amounts']['discount']));
        }
        foreach ($m['adjustments'] ?? [] as $row) {
            $totals .= $total(e($row['label']), e($row['amount']));
        }
        if ($m['taxes'] !== []) {
            $totals .= $total('<strong>'.$t('invoice_taxes').':</strong>', '');
        }
        foreach ($m['taxes'] as $tax) {
            $label = e(trim($tax['name'].' '.$tax['rate'])).($tax['included'] ? ' '.$t('invoice_included') : '').($tax['registration'] !== '' ? ' ('.e($tax['registration']).')' : '');
            $totals .= $total($label.':', e($tax['amount']));
        }
        $totals .= $total('<strong>'.$t('invoice_shipping_cost').':</strong>', e($m['amounts']['shipping']));
        if ($m['amounts']['has_payment_fee']) {
            $totals .= $total('<strong>'.$t('invoice_payment_fee').':</strong>', e($m['amounts']['payment_fee']));
        }
        $totals .= '<tr><td colspan="2" style="padding:8px 0 0"><div style="border-top:1px solid #d1d5db"></div></td></tr>';
        $totals .= $total('<strong style="font-size:18px">'.$t('invoice_total_cost').':</strong>', '<strong style="font-size:22px">'.e($m['amounts']['total']).'</strong>');
        foreach ($m['payments'] ?? [] as $row) {
            $totals .= $total(e($row['label']), e($row['amount']));
        }
        $values = [...$values, 'invoice_items' => $items, 'invoice_totals' => $totals];
        $body = InvoiceLayout::expand($overrides['body'] ?? $layout['invoice_body_html'], $locale);

        return [...$values, 'invoice' => $this->tokens->render($body, $values)];
    }

    /** Discount values are informational: already-priced sales are never deducted a second time.
     * @param array<string,string> $vat
     * @return array<string,string> */
    private function discountDetails(object $item, array $vat, \Closure $money, string $locale): array
    {
        $basis = json_decode((string) ($item->pricing_basis ?? 'null'), true);
        $original = Decimal::multiply((string) ($basis['base_unit_price'] ?? $item->unit_price), (string) $item->quantity);
        $sale = Decimal::subtract($original, Decimal::trim($item->subtotal));
        $sale = Decimal::compare($sale, '0') > 0 ? $sale : '0';
        $discount = Decimal::trim((string) ($item->discount_total ?? '0'));
        $charged = Decimal::subtract(Decimal::trim($item->subtotal), $discount);
        $groups = [];
        foreach ($basis['tax_rates'] ?? [] as $tax) {
            if ($tax['type'] === 'percent' && $tax['included']) {
                $priority = $tax['priority'] ?? 0;
                $groups[$priority] = Decimal::add($groups[$priority] ?? '0', $tax['value']);
            }
        }
        $divisor = array_reduce($groups, fn (string $factor, string $rate): string => Decimal::multiply($factor, Decimal::add('1', $rate)), '1');
        $factor = Decimal::compare($charged, '0') > 0 ? Decimal::divide($vat['net'], $charged, 12) : Decimal::divide('1', $divisor, 12);
        $label = function (string $key, string $amount, string $base) use ($factor, $money, $locale): string {
            if (Decimal::compare($amount, '0') <= 0 || Decimal::compare($base, '0') <= 0) {
                return '';
            }
            $percent = Decimal::trim(Decimal::round(Decimal::multiply(Decimal::divide($amount, $base, 12), '100'), 2));

            return __('orders.'.$key, ['percent' => $percent, 'amount' => $money(Decimal::multiply($amount, $factor))], $locale);
        };

        return ['original_unit_net' => $money(Decimal::divide(Decimal::add($vat['net'], Decimal::multiply(Decimal::add($sale, $discount), $factor)), (string) $item->quantity, 6)),
            'sale_discount' => $label('invoice_sale_reduction', $sale, $original), 'discount' => $label('invoice_line_reduction', $discount, Decimal::trim($item->subtotal))];
    }

    /** @return list<array{label:string,amount:string}> */
    private function adjustments(object $order, \Closure $money): array
    {
        $rows = [];
        if ($order->coupon_code !== null && Decimal::compare(Decimal::add($order->coupon_discount, $order->coupon_shipping_discount), '0') > 0) {
            $rows[] = ['label' => __('orders.invoice_coupon_included', ['code' => $order->coupon_code], $order->locale_code), 'amount' => $money(Decimal::add($order->coupon_discount, $order->coupon_shipping_discount))];
        }
        foreach (DB::table('order_promotions')->where('order_id', $order->id)->where('is_current', true)->get() as $p) {
            $rows[] = ['label' => __('orders.invoice_promotion_included', ['name' => $p->name], $order->locale_code), 'amount' => $money(Decimal::add($p->discount, $p->shipping_discount))];
        }

        return $rows;
    }

    /** Gift cards settle the gross debt; they never reduce the taxable value.
     * @return list<array{label:string,amount:string}> */
    private function paymentSummary(object $order, \Closure $money): array
    {
        $payments = DB::table('order_payments')->where('order_id', $order->id)->where('state', 'captured')->get();
        if ($payments->isEmpty()) {
            return [];
        }
        $gift = $other = '0';
        foreach ($payments as $p) {
            if ($p->kind === 'gift_card') {
                $gift = Decimal::add($gift, $p->amount);
            } else {
                $other = Decimal::add($other, $p->amount);
            }
        }
        $rows = [];
        foreach (['invoice_gift_card_payment' => $gift, 'invoice_other_payments' => $other] as $label => $amount) {
            if (Decimal::compare($amount, '0') > 0) {
                $rows[] = ['label' => __('orders.'.$label, [], $order->locale_code), 'amount' => $money($amount)];
            }
        }
        $due = Decimal::subtract($order->total, Decimal::add($gift, $other));
        $rows[] = ['label' => __('orders.invoice_amount_due', [], $order->locale_code), 'amount' => $money(Decimal::compare($due, '0') > 0 ? $due : '0')];

        return $rows;
    }

    /** @param list<array{name: string, sku: string, variation: string, quantity: int, unit: string, total: string}> $items */
    private function itemsTable(array $items, string $locale): string
    {
        $cell = 'padding:10px 6px; border-bottom:1px solid #e5e7eb; vertical-align:top;';
        $rows = '';
        foreach ($items as $item) {
            $details = array_filter([$item['sku'] !== '' ? __('orders.sku', [], $locale).': '.$item['sku'] : '', $item['variation']]);
            $rows .= '<tr><td style="'.$cell.'">'.e($item['name']).($details === [] ? '' : '<br><span style="color:#6b7280; font-size:12px">'.e(implode(' · ', $details)).'</span>').'</td>'
                .'<td style="'.$cell.'text-align:right">'.$item['quantity'].'</td><td style="'.$cell.'text-align:right; white-space:nowrap">'.e($item['unit']).'</td><td style="'.$cell.'text-align:right; white-space:nowrap">'.e($item['total']).'</td></tr>';
        }
        $head = 'padding:8px 6px; border-bottom:2px solid #d1d5db; text-align:left; font-size:12px; color:#6b7280;';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px; margin:20px 0 12px"><thead><tr>'
            .'<th style="'.$head.'">'.e(__('orders.product', [], $locale)).'</th><th style="'.$head.'text-align:right">'.e(__('orders.quantity', [], $locale)).'</th>'
            .'<th style="'.$head.'text-align:right">'.e(__('orders.unit_price', [], $locale)).'</th><th style="'.$head.'text-align:right">'.e(__('orders.line_total', [], $locale)).'</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /** @return list<string> */
    private function addressLines(object $a, string $locale): array
    {
        $lines = [];
        foreach ([trim($a->first_name.' '.$a->last_name), $a->company, $a->line1, $a->line2, trim(($a->postal_code ?? '').' '.$a->city),
            trim($this->countries->name($a->country_code, $locale).($a->region_code ? ' ('.$a->region_code.')' : '')), $a->phone] as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $line;
            }
        }

        foreach (['floor' => 'floor', 'bell_name' => 'bell_name', 'notes' => 'address_notes'] as $field => $key) {
            if (($a->{$field} ?? null) !== null && $a->{$field} !== '') {
                $lines[] = __('customers.'.$key, [], $locale).': '.$a->{$field};
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $company */
    private function storeAddress(array $company, string $locale): string
    {
        return implode(', ', array_filter([$company['address'] ?? null, trim(($company['postal_code'] ?? '').' '.($company['city'] ?? '')), $company['country_code'] ? $this->countries->name($company['country_code'], $locale) : null]));
    }

    /** @return array{mime: string, content: string}|null The selected email logo (or store fallback), read from private storage. */
    private function logo(): ?array
    {
        $media = app(CompanySettings::class)->emailLogo();
        if ($media === null || $media->disk !== 'local' || ! Storage::disk('local')->exists($media->storage_key)) {
            return null;
        }

        return ['mime' => (string) $media->mime_type, 'content' => (string) Storage::disk('local')->get($media->storage_key)];
    }

    private function statusName(string $statusId, string $locale): string
    {
        $default = DB::table('locales')->where('is_default', true)->value('code');
        $names = DB::table('order_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $statusId)->pluck('t.name', 'l.code');

        return $names[$locale] ?? $names[$default] ?? (string) DB::table('order_statuses')->where('id', $statusId)->value('code');
    }

    private function date(string $value): string
    {
        $settings = app(GeneralSettings::class)->all();

        return CarbonImmutable::parse($value)->setTimezone($settings['timezone'])->format($settings['date_format'].' '.$settings['time_format']);
    }

    /** One centered, responsive column; a header and footer, when present, span its full width. */
    private function document(string $header, string $content, string $footer, string $title, string $locale): string
    {
        return '<!DOCTYPE html><html lang="'.e($locale).'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title></head>'
            .'<body style="margin:0; padding:0; background-color:#f3f4f6">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6"><tbody><tr><td align="center" style="padding:24px 12px">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; background-color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.55; color:#1f2937"><tbody>'
            .(trim($header) === '' ? '' : '<tr><td style="padding:0">'.$header.'</td></tr>')
            .'<tr><td style="padding:28px 32px">'.$content.'</td></tr>'
            .(trim($footer) === '' ? '' : '<tr><td style="padding:0; border-top:1px solid #e5e7eb">'.$footer.'</td></tr>')
            .'</tbody></table></td></tr></tbody></table></body></html>';
    }

    private function text(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h[1-6]|li|tr|blockquote|table)>/i', '/<\/t[dh]>/i', '/<img[^>]*>/i'], ["\n", "\n\n", '  ', ''], $html) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $text) ?? '') ?? '');
    }
}
