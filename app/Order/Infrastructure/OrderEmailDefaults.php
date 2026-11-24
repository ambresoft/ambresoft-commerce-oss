<?php

namespace App\Order\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * Reference statuses, the template-variable allow-list and generated default email content (status bodies, the shared
 * header and footer, the invoice email). Wording comes from the keyed UI catalogs of each language; after generation it
 * is ordinary merchant content.
 */
final class OrderEmailDefaults
{
    /** code => [color, initial, final, stock action] in display order. CS-Cart-inspired; none of them means "paid". */
    public const STATUSES = [
        'open' => ['#2563eb', true, false, 'none'],
        'processing' => ['#d97706', false, false, 'decrease'],
        'ready_for_shipment' => ['#0891b2', false, false, 'decrease'],
        // Handed to the carrier. Takes stock if the order has not already (no shipping integration yet).
        'shipped' => ['#7c3aed', false, false, 'decrease'],
        'completed' => ['#16a34a', false, true, 'decrease'],
        'cancelled' => ['#6b7280', false, true, 'restore'],
        'failed' => ['#dc2626', false, true, 'restore'],
        'incomplete' => ['#94a3b8', false, false, 'none'],
    ];

    /** Every placeholder a template may use. Private data (IP, internal notes, comments) is deliberately absent. */
    public const VARIABLES = [
        'order_number', 'order_date', 'previous_status', 'current_status', 'status_changed_at',
        'customer_name', 'customer_first_name', 'customer_last_name', 'customer_email', 'customer_phone',
        'order_currency', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'payment_fee', 'grand_total',
        'order_items', 'billing_address', 'shipping_address', 'customer_note', 'payment_method', 'shipping_method', 'invoice', 'cancel_link',
        ...InvoiceLayout::VARIABLES, ...PackingSlipLayout::VARIABLES,
        'store_logo', 'store_name', 'store_email', 'store_phone', 'store_website', 'store_address', 'store_contact',
        'store_legal_name', 'store_business_activity', 'store_tax_id', 'store_tax_office', 'store_gemi_number', 'store_details',
    ];

    /** Placeholders that expand to generated (escaped) HTML blocks; they are plain text in a subject. */
    public const BLOCK_VARIABLES = ['order_items', 'billing_address', 'shipping_address', 'invoice', 'invoice_logo', 'invoice_customer_details', 'invoice_items', 'invoice_totals', 'store_logo', 'cancel_link', 'store_details', 'order_barcode', 'packing_details', 'packing_items', 'packing_note'];

    /** Earlier generated bodies [initial, update] per language; templates still holding them are upgraded. */
    private const LEGACY_BODIES = [
        'en' => ['<p>Hello {{customer_first_name}},</p><p>Thank you for your order <strong>{{order_number}}</strong> placed on {{order_date}}.</p><p>Current status: <strong>{{current_status}}</strong></p>{{order_items}}<p>Subtotal: {{subtotal}}<br>Taxes: {{tax_total}}<br><strong>Total: {{grand_total}}</strong></p><p><strong>Billing address</strong><br>{{billing_address}}</p><p><strong>Shipping address</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Hello {{customer_first_name}},</p><p>The status of your order <strong>{{order_number}}</strong> changed from {{previous_status}} to <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Total: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
        'el' => ['<p>Γεια σας {{customer_first_name}},</p><p>Ευχαριστούμε για την παραγγελία <strong>{{order_number}}</strong> της {{order_date}}.</p><p>Τρέχουσα κατάσταση: <strong>{{current_status}}</strong></p>{{order_items}}<p>Υποσύνολο: {{subtotal}}<br>Φόροι: {{tax_total}}<br><strong>Σύνολο: {{grand_total}}</strong></p><p><strong>Διεύθυνση χρέωσης</strong><br>{{billing_address}}</p><p><strong>Διεύθυνση αποστολής</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Γεια σας {{customer_first_name}},</p><p>Η κατάσταση της παραγγελίας <strong>{{order_number}}</strong> άλλαξε από {{previous_status}} σε <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Σύνολο: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
        'es' => ['<p>Hola {{customer_first_name}},</p><p>Gracias por su pedido <strong>{{order_number}}</strong> del {{order_date}}.</p><p>Estado actual: <strong>{{current_status}}</strong></p>{{order_items}}<p>Subtotal: {{subtotal}}<br>Impuestos: {{tax_total}}<br><strong>Total: {{grand_total}}</strong></p><p><strong>Dirección de facturación</strong><br>{{billing_address}}</p><p><strong>Dirección de envío</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Hola {{customer_first_name}},</p><p>El estado de su pedido <strong>{{order_number}}</strong> cambió de {{previous_status}} a <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Total: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
        'fr' => ['<p>Bonjour {{customer_first_name}},</p><p>Merci pour votre commande <strong>{{order_number}}</strong> du {{order_date}}.</p><p>Statut actuel : <strong>{{current_status}}</strong></p>{{order_items}}<p>Sous-total: {{subtotal}}<br>Taxes: {{tax_total}}<br><strong>Total: {{grand_total}}</strong></p><p><strong>Adresse de facturation</strong><br>{{billing_address}}</p><p><strong>Adresse de livraison</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Bonjour {{customer_first_name}},</p><p>Le statut de votre commande <strong>{{order_number}}</strong> est passé de {{previous_status}} à <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Total: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
        'it' => ['<p>Ciao {{customer_first_name}},</p><p>Grazie per il tuo ordine <strong>{{order_number}}</strong> del {{order_date}}.</p><p>Stato attuale: <strong>{{current_status}}</strong></p>{{order_items}}<p>Subtotale: {{subtotal}}<br>Imposte: {{tax_total}}<br><strong>Totale: {{grand_total}}</strong></p><p><strong>Indirizzo di fatturazione</strong><br>{{billing_address}}</p><p><strong>Indirizzo di spedizione</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Ciao {{customer_first_name}},</p><p>Lo stato del tuo ordine <strong>{{order_number}}</strong> è passato da {{previous_status}} a <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Totale: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
        'nl' => ['<p>Hallo {{customer_first_name}},</p><p>Bedankt voor uw bestelling <strong>{{order_number}}</strong> van {{order_date}}.</p><p>Huidige status: <strong>{{current_status}}</strong></p>{{order_items}}<p>Subtotaal: {{subtotal}}<br>Belastingen: {{tax_total}}<br><strong>Totaal: {{grand_total}}</strong></p><p><strong>Factuuradres</strong><br>{{billing_address}}</p><p><strong>Verzendadres</strong><br>{{shipping_address}}</p><p>{{store_name}}</p>', '<p>Hallo {{customer_first_name}},</p><p>De status van uw bestelling <strong>{{order_number}}</strong> is gewijzigd van {{previous_status}} naar <strong>{{current_status}}</strong>.</p>{{order_items}}<p><strong>Totaal: {{grand_total}}</strong></p><p>{{store_name}}</p>'],
    ];

    private const ACCENT = '#1d3557';

    /** Seeds the reference statuses that do not exist yet, with names and templates in every supported language. */
    public static function seedStatuses(): void
    {
        $position = 10;
        foreach (self::STATUSES as $code => [$color, $initial, $final, $stock]) {
            if (! DB::table('order_statuses')->where('code', $code)->exists()) {
                $id = DB::table('order_statuses')->insertGetId([
                    'code' => $code, 'color' => $color, 'position' => $position, 'is_active' => true,
                    // Only one initial status may exist: a merchant's own choice is kept.
                    'is_initial' => $initial && ! DB::table('order_statuses')->where('is_initial', true)->exists(),
                    'is_final' => $final, 'is_system' => true, 'stock_action' => $stock, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (DB::table('locales')->get(['id', 'code']) as $locale) {
                    DB::table('order_status_translations')->insert(['status_id' => $id, 'locale_id' => $locale->id, 'name' => __('orders.status_'.$code, [], $locale->code), 'description' => __('orders.status_'.$code.'_description', [], $locale->code)]);
                }
                self::templates($id, $initial, $code);
            }
            $position += 10;
        }
    }

    /** Creates the missing templates of a status in every supported language. */
    public static function templates(int $statusId, bool $initial, ?string $code = null): void
    {
        $code ??= DB::table('order_statuses')->where('id', $statusId)->value('code');
        foreach (DB::table('locales')->get(['id', 'code']) as $locale) {
            if (DB::table('order_email_templates')->where('status_id', $statusId)->where('locale_id', $locale->id)->exists()) {
                continue;
            }
            [$subject, $body] = self::text($locale->code, $initial, $code);
            DB::table('order_email_templates')->insert(['status_id' => $statusId, 'locale_id' => $locale->id, 'subject' => $subject, 'body_html' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Creates the missing header/footer/invoice layout of every supported language. */
    public static function seedLayouts(): void
    {
        foreach (DB::table('locales')->get(['id', 'code']) as $locale) {
            if (! DB::table('order_email_layouts')->where('locale_id', $locale->id)->exists()) {
                DB::table('order_email_layouts')->insert(['locale_id' => $locale->id, ...self::layout($locale->code), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** Replaces bodies that still equal the earlier generated text; merchant-edited bodies are never touched. */
    public static function upgradeUnchangedBodies(): void
    {
        $initial = DB::table('order_statuses')->where('is_initial', true)->value('id');
        foreach (DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->get(['t.id', 't.status_id', 't.body_html', 'l.code']) as $template) {
            $legacy = self::LEGACY_BODIES[$template->code] ?? null;
            if ($legacy !== null && in_array($template->body_html, $legacy, true)) {
                DB::table('order_email_templates')->where('id', $template->id)->update(['body_html' => self::body($template->code, $template->status_id === $initial), 'updated_at' => now()]);
            }
        }
    }

    /** @return array{string, string} Subject and HTML body of a generated status template in that language. */
    public static function text(string $locale, bool $initial, ?string $code = null): array
    {
        if (in_array($code, ['ready_for_shipment', 'shipped'], true)) {
            return [__('orders.mail_'.$code.'_subject', [], $locale), self::body($locale, false, $code)];
        }

        return [__('orders.mail_default_subject_'.($initial ? 'initial' : 'update'), [], $locale), self::body($locale, $initial)];
    }

    /** @return array{header_html: string, footer_html: string, invoice_subject: string, invoice_body_html: string} */
    public static function layout(string $locale): array
    {
        $t = fn (string $key): string => e(__('orders.'.$key, [], $locale));

        return [
            'header_html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:'.self::ACCENT.'"><tbody><tr>'
                .'<td style="padding:24px 32px; color:#ffffff">{{store_logo}}</td></tr></tbody></table>',
            'footer_html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tbody><tr>'
                .'<td align="center" style="padding:28px 32px; font-size:13px; line-height:1.6; color:#4b5563; text-align:center">'
                .'<p style="margin:0 0 10px; font-size:15px; font-weight:bold; color:'.self::ACCENT.'">{{store_name}}</p>'
                .'<p style="margin:0 0 10px">'.$t('mail_footer_reason').'</p>'
                .'<p style="margin:0 0 4px">{{store_address}}</p><p style="margin:0">{{store_contact}}</p></td></tr></tbody></table>',
            'invoice_subject' => __('orders.invoice_mail_subject', [], $locale),
            'invoice_body_html' => InvoiceLayout::document($locale),
        ];
    }

    /** A designed status email body: heading, summary box, items and totals. */
    public static function body(string $locale, bool $initial, ?string $variant = null): string
    {
        $t = fn (string $key): string => e(__('orders.'.$key, [], $locale));
        [$title, $text] = in_array($variant, ['ready_for_shipment', 'shipped'], true) ? ['mail_'.$variant.'_title', 'mail_'.$variant.'_text'] : ($initial ? ['mail_thanks_title', 'mail_thanks_text'] : ['mail_update_title', 'mail_update_text']);
        $html = '<h1 style="margin:0 0 12px; font-size:24px; line-height:1.3; color:#111827">'.$t($title).'</h1>'
            .'<p style="margin:0 0 20px">'.$t($text).'</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; border-radius:12px"><tbody><tr>'
            .'<td style="padding:16px 20px; color:#4b5563">'.$t('mail_label_order_number').': <strong style="color:#111827">{{order_number}}</strong></td>'
            .'<td align="right" style="padding:16px 20px; color:#4b5563">'.$t('mail_label_date').': <strong style="color:#111827">{{order_date}}</strong></td>'
            .'</tr></tbody></table>{{order_items}}'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tbody>'
            .'<tr><td align="right" style="padding:2px 0; color:#4b5563">'.$t('mail_label_subtotal').': {{subtotal}}</td></tr>'
            .'<tr><td align="right" style="padding:2px 0; color:#4b5563">'.$t('mail_label_taxes').': {{tax_total}}</td></tr>'
            .'<tr><td align="right" style="padding:8px 0 0; font-size:18px; font-weight:bold; color:#111827">'.$t('mail_label_total').': {{grand_total}}</td></tr>'
            .'</tbody></table>';
        if ($initial || in_array($variant, ['ready_for_shipment', 'shipped'], true)) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px"><tbody><tr>'
                .'<td valign="top" width="50%" style="padding:0 12px 0 0"><strong>'.e(__('orders.billing_address', [], $locale)).'</strong><br>{{billing_address}}</td>'
                .'<td valign="top" width="50%" style="padding:0 0 0 12px"><strong>'.e(__('orders.shipping_address', [], $locale)).'</strong><br>{{shipping_address}}</td>'
                .'</tr></tbody></table>';
        }

        return $html;
    }
}
