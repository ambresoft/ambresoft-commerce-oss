<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only generated subjects and standalone document headings change; merchant content and styling stay.
        $names = [
            'en' => ['INVOICE', 'ORDER SUMMARY', 'Invoice for order {{order_number}}', 'Order summary for order {{order_number}}'],
            'el' => ['ΤΙΜΟΛΟΓΙΟ', 'ΣΥΝΟΨΗ ΠΑΡΑΓΓΕΛΙΑΣ', 'Τιμολόγιο παραγγελίας {{order_number}}', 'Σύνοψη παραγγελίας {{order_number}}'],
            'es' => ['FACTURA', 'RESUMEN DEL PEDIDO', 'Factura del pedido {{order_number}}', 'Resumen del pedido {{order_number}}'],
            'fr' => ['FACTURE', 'RÉCAPITULATIF DE COMMANDE', 'Facture de la commande {{order_number}}', 'Récapitulatif de la commande {{order_number}}'],
            'it' => ['FATTURA', 'RIEPILOGO ORDINE', 'Fattura dell’ordine {{order_number}}', 'Riepilogo dell’ordine {{order_number}}'],
            'nl' => ['FACTUUR', 'BESTELOVERZICHT', 'Factuur voor bestelling {{order_number}}', 'Overzicht van bestelling {{order_number}}'],
        ];
        foreach (DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->get(['t.*', 'l.code']) as $row) {
            if (! isset($names[$row->code])) {
                continue;
            }
            [$oldTitle, $newTitle, $oldSubject, $newSubject] = $names[$row->code];
            $body = preg_replace('/(?<=>)\s*'.preg_quote($oldTitle, '/').'\s*(?=<)/u', $newTitle, $row->invoice_body_html);
            $subject = $row->invoice_subject === $oldSubject ? $newSubject : $row->invoice_subject;
            if ($body !== $row->invoice_body_html || $subject !== $row->invoice_subject) {
                DB::table('order_email_layouts')->where('id', $row->id)->update(['invoice_subject' => $subject, 'invoice_body_html' => $body, 'lock_version' => $row->lock_version + 1, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Retain merchant-facing wording if code/schema is rolled back.
    }
};
