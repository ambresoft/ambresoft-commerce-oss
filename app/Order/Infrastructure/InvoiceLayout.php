<?php

namespace App\Order\Infrastructure;

/** Editable HTML only; values and arithmetic remain server-owned. */
final class InvoiceLayout
{
    public const VARIABLES = ['invoice_logo', 'invoice_store_address', 'invoice_customer_details', 'invoice_items', 'invoice_totals', 'item_name', 'item_sku', 'item_variation', 'item_quantity', 'item_unit_net', 'item_vat_rate', 'item_unit_gross', 'item_line_net', 'item_line_vat', 'item_line_total', 'item_original_unit_net', 'item_sale_discount', 'item_discount', 'invoice_total_label', 'invoice_total_value'];

    public static function expand(string $body, string $locale): string
    {
        return (string) preg_replace_callback('/\{\{\s*invoice\s*\}\}/', fn (): string => self::document($locale), $body);
    }

    public static function document(string $locale): string
    {
        $t = fn (string $key): string => e(__('orders.'.$key, [], $locale));
        $cell = 'padding:10px 12px; border:1px solid #e5e7eb; vertical-align:top;';

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; color:#1f2937"><tbody>'
            .'<tr><td style="padding:0 0 12px; border-bottom:2px solid #9ca3af">{{invoice_logo}}</td><td align="right" valign="bottom" style="padding:0 0 12px; border-bottom:2px solid #9ca3af; font-size:30px; font-weight:bold; letter-spacing:1px">'.$t('invoice_title').'</td></tr>'
            .'<tr><td valign="top" width="50%" style="padding:20px 12px 20px 0"><strong>{{store_name}}</strong><br>{{invoice_store_address}}'
            .'{{store_details}}</td>'
            .'<td valign="top" width="50%" style="padding:20px 0"><div style="font-size:22px; font-weight:bold">'.e(__('orders.invoice_order', ['number' => '{{order_number}}'], $locale)).'</div>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px"><tbody>'.self::contact(self::upper($t('invoice_status')), 'current_status').self::contact(self::upper($t('invoice_date')), 'order_date').'</tbody></table></td></tr>'
            .'<tr><td valign="top" style="padding:8px 12px 20px 0"><div style="font-size:20px; font-weight:bold; margin-bottom:6px">'.$t('invoice_bill_to').'</div>{{billing_address}}</td>'
            .'<td valign="top" style="padding:8px 0 20px"><div style="font-size:20px; font-weight:bold; margin-bottom:6px">'.$t('invoice_ship_to').'</div>{{shipping_address}}</td></tr>'
            .'<tr><td colspan="2">{{invoice_customer_details}}</td></tr>'
            .'<tr><td colspan="2"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px"><thead><tr style="background-color:#f3f4f6">'
            .'<th align="left" style="'.$cell.'">'.$t('invoice_product').'</th><th align="center" style="'.$cell.'">'.$t('invoice_quantity').'</th><th align="right" style="'.$cell.'">'.$t('invoice_unit_price_net').'</th><th align="right" style="'.$cell.'">'.$t('invoice_vat_rate').'</th><th align="right" style="'.$cell.'">'.$t('invoice_unit_price_gross').'</th><th align="right" style="'.$cell.'">'.$t('invoice_line_total').'</th>'
            .'</tr></thead><tbody>{{invoice_items}}</tbody></table></td></tr><tr><td colspan="2" align="right" style="padding-top:12px"><table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px"><tbody>{{invoice_totals}}</tbody></table></td></tr></tbody></table>';

        return str_replace('><', ">\n<", $html);
    }

    public static function item(string $locale = 'en'): string
    {
        $cell = 'padding:10px 12px; border:1px solid #e5e7eb; vertical-align:top;';

        return '<tr><td style="'.$cell.'">{{item_name}}<div style="color:#4b5563; font-size:13px"><div>'.e(__('orders.invoice_code', [], $locale)).': {{item_sku}}</div><div>{{item_variation}}</div><div>{{item_sale_discount}}</div><div>{{item_discount}}</div></div></td><td align="center" style="'.$cell.'">{{item_quantity}}</td><td align="right" style="'.$cell.'white-space:nowrap">{{item_unit_net}}</td><td align="right" style="'.$cell.'white-space:nowrap">{{item_vat_rate}}</td><td align="right" style="'.$cell.'white-space:nowrap">{{item_unit_gross}}</td><td align="right" style="'.$cell.'white-space:nowrap; font-weight:bold">{{item_line_total}}</td></tr>';
    }

    public static function total(): string
    {
        return '<tr><td align="right" style="padding:3px 16px 3px 0">{{invoice_total_label}}</td><td align="right" style="padding:3px 0; white-space:nowrap">{{invoice_total_value}}</td></tr>';
    }

    private static function upper(string $text): string
    {
        $decomposed = \Normalizer::normalize(mb_strtoupper($text), \Normalizer::FORM_D);

        return (string) \Normalizer::normalize(str_replace("\u{0301}", '', (string) $decomposed), \Normalizer::FORM_C);
    }

    private static function contact(string $label, string $token): string
    {
        return '<tr><td style="padding:1px 12px 1px 0; color:#4b5563; white-space:nowrap">'.$label.':</td><td style="padding:1px 0">{{'.$token.'}}</td></tr>';
    }
}
