<?php

namespace App\Order\Infrastructure;

/**
 * The built-in packing slip: a cut-off delivery label on top, then the store, the order, both addresses and the products
 * without prices. Printed by staff and put in the parcel; never e-mailed. Editable HTML only; values are server-owned.
 */
final class PackingSlipLayout
{
    public const VARIABLES = ['order_barcode', 'packing_details', 'packing_items', 'packing_note'];

    public static function document(string $locale): string
    {
        $t = fn (string $key, array $replace = []): string => e(__('orders.'.$key, $replace, $locale));
        $cell = 'padding:10px 12px; border:1px solid #e5e7eb; vertical-align:top;';
        $heading = 'font-size:20px; font-weight:bold; margin-bottom:8px';

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; color:#1f2937"><tbody>'
            .'<tr><td colspan="2" style="padding:0 0 8px">{{order_barcode}}</td></tr>'
            .'<tr><td colspan="2" align="center" style="padding:8px 0 24px; font-size:16px"><div style="'.$heading.'">'.$t('invoice_ship_to').'</div>{{shipping_address}}</td></tr>'
            .'<tr><td colspan="2" style="padding:0 0 28px"><div style="border-top:2px dashed #6b7280"></div><div style="margin-top:-12px; font-size:18px; line-height:1; color:#6b7280">&#9986;</div></td></tr>'
            .'<tr><td valign="top" width="50%" style="padding:0 12px 20px 0">{{invoice_logo}}<div style="margin-top:12px"><strong>{{store_name}}</strong><br>{{invoice_store_address}}</div>{{store_details}}</td>'
            .'<td valign="top" width="50%" style="padding:0 0 20px"><div style="font-size:24px; font-weight:bold">'.$t('packing_slip_for', ['number' => '{{order_number}}']).'</div>'
            .'<div style="margin-top:6px"><span style="color:#4b5563">'.$t('invoice_date').':</span> {{order_date}}</div></td></tr>'
            .'<tr><td valign="top" style="padding:8px 12px 20px 0; border-top:1px solid #e5e7eb"><div style="'.$heading.'">'.$t('invoice_bill_to').'</div>{{billing_address}}</td>'
            .'<td valign="top" style="padding:8px 0 20px; border-top:1px solid #e5e7eb"><div style="'.$heading.'">'.$t('invoice_ship_to').'</div>{{shipping_address}}</td></tr>'
            .'<tr><td colspan="2" style="padding:0 0 16px">{{packing_details}}</td></tr>'
            .'<tr><td colspan="2"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px"><thead><tr style="background-color:#f3f4f6">'
            .'<th align="left" style="'.$cell.'">'.$t('invoice_product').'</th><th align="left" style="'.$cell.'">'.$t('invoice_code').'</th><th align="center" style="'.$cell.'">'.$t('invoice_quantity').'</th>'
            .'</tr></thead><tbody>{{packing_items}}</tbody></table></td></tr>'
            .'<tr><td colspan="2" style="padding-top:16px">{{packing_note}}</td></tr></tbody></table>';

        return str_replace('><', ">\n<", $html);
    }

    public static function item(): string
    {
        $cell = 'padding:10px 12px; border:1px solid #e5e7eb; vertical-align:top;';

        return '<tr><td style="'.$cell.'">{{item_name}}<div style="color:#4b5563; font-size:13px">{{item_variation}}</div></td><td style="'.$cell.'">{{item_sku}}</td><td align="center" style="'.$cell.'font-size:16px; font-weight:bold">{{item_quantity}}</td></tr>';
    }
}
