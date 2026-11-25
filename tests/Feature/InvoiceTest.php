<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\Coupons;
use App\Marketing\Infrastructure\GiftCardRedemptions;
use App\Marketing\Infrastructure\GiftCards;
use App\Order\Application\Contract\OrderMailTransport;
use App\Order\Infrastructure\InvoiceLayout;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Settings\Infrastructure\GeneralSettings;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'orders@store.example']);
    }

    private function layout(): object
    {
        return DB::table('order_email_layouts as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('l.code', 'en')->first(['t.*']);
    }

    public function test_full_document_and_repeating_rows_are_editable_without_losing_table_structure(): void
    {
        $layout = $this->layout();
        DB::table('order_email_layouts')->where('id', $layout->id)->update(['invoice_body_html' => '<p>Existing introduction</p>{{invoice}}']);
        $this->get('/admin/order-emails/order-summary')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('templates.en.body', fn ($body) => str_contains($body, '<p>Existing introduction</p><table') && ! str_contains($body, '{{invoice}}'))
            ->has('templates.en.item')->has('templates.en.total'));
        self::assertSame('<p>Existing introduction</p>{{invoice}}', $this->layout()->invoice_body_html, 'Opening the editor does not overwrite merchant content');
        $fields = ['subject' => 'My invoice {{order_number}}', 'body' => InvoiceLayout::document('en').'<p>Document edited</p>',
            'item' => str_replace('{{item_name}}', 'Custom row {{item_name}}', InvoiceLayout::item()),
            'total' => str_replace('{{invoice_total_label}}', 'Custom total {{invoice_total_label}}', InvoiceLayout::total())];
        $this->put('/admin/order-emails/order-summary/'.$layout->id, ['version' => 1, ...$fields])->assertSessionHasNoErrors();
        self::assertStringContainsString('<tbody>{{invoice_items}}</tbody>', $this->layout()->invoice_body_html);
        self::assertStringContainsString('<tbody>{{invoice_totals}}</tbody>', $this->layout()->invoice_body_html);
        $product = $this->orderProduct('20');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id));
        app(OrderAdministration::class)->changeStatus((string) $this->actor->id, $order, null, $this->orderStatus('completed'));
        $html = app(OrderEmailRenderer::class)->invoicePage($order);
        foreach (['Custom row Lamp', 'Custom total', 'Document edited'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('{{', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        self::assertSame(6, (new \DOMXPath($dom))->query('//thead/tr/th')->length, 'The original six-column invoice design stays');
        $invoice = collect(app(OrderMailTransport::class)->sent)->first(fn ($m) => str_starts_with($m->subject, 'My invoice'));
        self::assertNotNull($invoice);
        self::assertStringContainsString('Custom row Lamp', $invoice->html);
        self::assertStringContainsString('Document edited', $invoice->html);
        $this->put('/admin/order-emails/order-summary/'.$layout->id, ['version' => 1, ...$fields])->assertSessionHasErrors('version');
        // Preview uses unsaved row changes without storing or sending anything.
        $preview = $this->postJson('/admin/order-emails/preview', ['kind' => 'invoice', 'locale' => 'en', ...$fields, 'item' => str_replace('Custom row', 'Unsaved row', $fields['item'])])->assertOk();
        self::assertStringContainsString('Unsaved row', $preview->json('html'));
        self::assertStringNotContainsString('Unsaved row', $this->layout()->invoice_item_html);
        $legacyPreview = $this->postJson('/admin/order-emails/preview', ['kind' => 'invoice', 'locale' => 'en', 'body' => '<p>Only once</p>{{invoice}}'])->assertOk();
        self::assertSame(1, substr_count($legacyPreview->json('html'), 'Only once'));
    }

    public function test_legacy_labels_are_renamed_without_overwriting_merchant_copy_or_design(): void
    {
        $layout = $this->layout();
        DB::table('order_email_layouts')->where('id', $layout->id)->update(['invoice_subject' => 'Invoice for order {{order_number}}', 'invoice_body_html' => '<h1 style="color:#123456">INVOICE</h1><p>Merchant message {{grand_total}}</p>']);
        $migration = require database_path('migrations/2026_11_10_000200_rename_commercial_invoice_to_order_summary.php');
        $migration->up();
        self::assertSame('Order summary for order {{order_number}}', $this->layout()->invoice_subject);
        self::assertSame('<h1 style="color:#123456">ORDER SUMMARY</h1><p>Merchant message {{grand_total}}</p>', $this->layout()->invoice_body_html);
        DB::table('order_email_layouts')->where('id', $layout->id)->update(['invoice_subject' => 'My merchant subject']);
        $migration->up();
        self::assertSame('My merchant subject', $this->layout()->invoice_subject);
        self::assertSame(2, (int) $this->layout()->lock_version);
    }

    public function test_row_templates_are_sanitized_authorized_and_reject_unknown_variables(): void
    {
        $layout = $this->layout();
        $fields = ['version' => 1, 'subject' => 'Invoice', 'body' => InvoiceLayout::document('en'), 'item' => '<tr onclick="steal()"><td>{{item_name}}<script>steal()</script></td></tr>', 'total' => InvoiceLayout::total()];
        $this->put('/admin/order-emails/order-summary/'.$layout->id, $fields)->assertSessionHasNoErrors();
        self::assertStringNotContainsString('onclick', $this->layout()->invoice_item_html);
        self::assertStringNotContainsString('<script', $this->layout()->invoice_item_html);
        $this->put('/admin/order-emails/order-summary/'.$layout->id, [...$fields, 'version' => 2, 'item' => '<tr><td>{{customer_ip}}</td></tr>'])->assertSessionHasErrors('item');
        $this->put('/admin/order-emails/order-summary/'.$layout->id, [...$fields, 'version' => 2, 'total' => '<script>steal()</script>'])->assertSessionHasErrors('total');
        $outsider = $this->staff(['admin.access', 'orders.view']);
        $this->flushSession();
        $this->actingAs($outsider)->put('/admin/order-emails/order-summary/'.$layout->id, [...$fields, 'version' => 2])->assertForbidden();
    }

    public function test_sale_coupon_and_gift_card_are_shown_without_double_deducting_taxes_or_payment(): void
    {
        $actor = (string) $this->actor->id;
        $product = $this->orderProduct('124');
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', true, 'billing', [new SaveTaxRateData($area, 'percent', '24')]));
        DB::table('product_taxes')->insert(['product_id' => $product->id, 'tax_id' => $tax]);
        DB::table('product_sales')->insert(['product_id' => $product->id, 'kind' => 'percentage', 'amount' => '10', 'minimum_quantity' => 1, 'priority' => 1, 'is_enabled' => true]);
        app(Coupons::class)->save($actor, null, ['name' => 'Summer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $order = app(OrderAdministration::class)->create($actor, $this->orderInput((string) $product->id, ['coupon_code' => 'SUMMER']));
        $card = app(GiftCards::class)->save($actor, null, ['recipient_name' => 'Maria', 'sender_name' => 'Test', 'recipient_email' => 'maria@example.test', 'message' => '', 'amount' => '30', 'locale' => 'en', 'status' => 'active', 'notify' => false]);
        $number = DB::table('gift_cards')->where('id', $card)->value('number');
        app(GiftCardRedemptions::class)->redeem($actor, $order, $number);
        $product->update(['price' => '999']);
        $html = app(OrderEmailRenderer::class)->invoicePage($order);
        foreach (['Sale included in price: 10%', 'Allocated discount: 10%', 'SUMMER', 'Paid by gift card', '€30.00', '€70.44', '€100.44', '€81.00', '€19.44', '>24%<'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString($number, $html, 'Never expose the reusable gift-card code');
        $row = DB::table('orders')->where('id', $order)->sole();
        self::assertSame(['100.440000', '19.440000'], [$row->total, $row->tax_total]);
        // A shipping reduction is identified too, without deducting it again from the already-priced order.
        DB::table('orders')->where('id', $order)->update(['coupon_shipping_discount' => '5', 'shipping_discount' => '5']);
        self::assertStringContainsString('€16.16', app(OrderEmailRenderer::class)->invoicePage($order));
    }

    public function test_only_the_owner_can_download_after_the_configured_invoice_status_even_if_email_fails(): void
    {
        $user = UserRecord::create(['name' => 'Maria', 'email' => 'maria@example.test', 'password' => 'long test password']);
        $user->markEmailAsVerified();
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'email' => $user->email, 'first_name' => 'Maria', 'last_name' => 'Example', 'account_linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->orderProduct('20');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['customer_id' => $customer]));
        $this->flushSession();
        $this->actingAs($user);
        $url = '/account/orders/'.$order.'/invoice';
        $path = 'storefront.regions.content.0.blocks.0.content.account.';
        $this->get($url)->assertNotFound();
        $this->get('/account/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where($path.'order.invoice_url', null));
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => null]);
        app()->forgetInstance(GeneralSettings::class);
        app(OrderAdministration::class)->changeStatus((string) $this->actor->id, $order, null, $this->orderStatus('completed'));
        self::assertSame('failed', DB::table('order_notifications')->where('order_id', $order)->where('kind', 'invoice')->value('state'));
        $this->get($url)->assertOk()->assertDownload('order-summary-'.DB::table('orders')->where('id', $order)->value('order_number').'.html')->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertSee('ORDER SUMMARY');
        $this->get('/account/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where($path.'order.invoice_url', $url));
        $this->get('/account/orders')->assertInertia(fn (Assert $p) => $p->where($path.'orders.rows.0.invoice_url', $url));
        app(OrderAdministration::class)->changeStatus((string) $this->actor->id, $order, null, $this->orderStatus('processing'));
        $this->get($url)->assertOk();
        $other = UserRecord::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'long test password']);
        $other->markEmailAsVerified();
        DB::table('customers')->insert(['user_id' => $other->id, 'email' => $other->email, 'first_name' => 'Other', 'last_name' => 'Customer', 'account_linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->flushSession();
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->post('/logout');
        $this->get($url)->assertRedirect('/login');
    }

    public function test_the_company_tax_details_are_validated_and_shown_on_the_order_summary_only_when_filled(): void
    {
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $this->orderProduct('10')->id));
        $html = app(OrderEmailRenderer::class)->invoicePage($order);
        foreach (['Legal name', 'Tax ID', 'Tax office', 'GEMI no.', 'Phone:'] as $label) {
            self::assertStringNotContainsString($label, $html, 'Empty details leave no empty rows');
        }

        // Greek companies: a valid ΑΦΜ and an optional GEMI number.
        $this->put('/admin/settings/company', ['version' => 1, 'country_code' => 'GR', 'tax_id' => '123456789'])->assertSessionHasErrors('tax_id');
        $this->put('/admin/settings/company', ['version' => 1, 'country_code' => 'GR', 'phone' => '2100000000', 'legal_name' => 'Ambre Commerce IKE', 'business_activity' => 'Online retail',
            'tax_id' => '094014201', 'tax_office' => 'ΦΑΕ Αθηνών', 'gemi_number' => '123456701000'])->assertSessionHasNoErrors();
        $html = app(OrderEmailRenderer::class)->invoicePage($order);
        foreach (['Phone:', '2100000000', 'Legal name:', 'Ambre Commerce IKE', 'Activity:', 'Online retail', 'Tax ID:', '094014201', 'Tax office:', 'ΦΑΕ Αθηνών', 'GEMI no.:', '123456701000'] as $text) {
            self::assertStringContainsString($text, $html);
        }

        // The GEMI number belongs to Greek companies only; another country drops it.
        $this->put('/admin/settings/company', ['version' => 2, 'country_code' => 'CY', 'tax_id' => 'CY10000000X'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('gemi_number'));
        $html = app(OrderEmailRenderer::class)->invoicePage($order);
        self::assertStringContainsString('CY10000000X', $html);
        self::assertStringNotContainsString('GEMI', $html);
    }

    public function test_the_packing_slip_is_editable_per_language_and_printed_from_the_order_without_prices(): void
    {
        $product = $this->orderProduct('124');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['customer_note' => 'Gift wrap please']));
        $number = (string) DB::table('orders')->where('id', $order)->value('order_number');

        $html = $this->get("/admin/orders/$order/packing-slip")->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();
        foreach (['Packing slip for order #'.$number, 'Ship to:', 'Bill to:', 'Ermou 1', 'Lamp', $product->sku, 'Gift wrap please', 'Status:', '<svg', 'aria-label="'.$number.'"'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('€124', $html, 'A packing slip shows no prices');

        // Edited per language like the order summary; unknown variables are refused; never e-mailed.
        $this->get('/admin/orders/files')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/OrderFiles/Index'));
        $this->get('/admin/orders/files/packing-slip')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/OrderEmails/Edit')->where('kind', 'packing_slip')
            ->where('templates.en.body', fn (string $body): bool => str_contains($body, '{{order_barcode}}') && str_contains($body, '{{packing_items}}')));
        $layout = $this->layout();
        $this->put("/admin/orders/files/packing-slip/{$layout->id}", ['version' => $layout->lock_version, 'body' => '<p>{{nonsense}}</p>', 'item' => '<tr><td>{{item_name}}</td></tr>'])->assertSessionHasErrors('body');
        $this->put("/admin/orders/files/packing-slip/{$layout->id}", ['version' => $layout->lock_version, 'body' => '<h1>Box for {{order_number}}</h1><table><tbody>{{packing_items}}</tbody></table>', 'item' => '<tr><td>{{item_quantity}} × {{item_name}}</td></tr>'])->assertSessionHasNoErrors();
        $html = $this->get("/admin/orders/$order/packing-slip")->getContent();
        self::assertStringContainsString('Box for '.$number, $html);
        self::assertStringContainsString('1 × Lamp', $html);
        $preview = $this->postJson('/admin/order-emails/preview', ['kind' => 'packing_slip', 'locale' => 'en', 'body' => '<p>Preview {{order_number}}</p>', 'item' => '<tr><td>x</td></tr>'])->assertOk();
        self::assertStringContainsString('Preview 100123', (string) $preview->json('html'));
        $this->post('/admin/order-emails/test', ['kind' => 'packing_slip', 'locale' => 'en', 'recipient' => 'someone@example.test', 'body' => '<p>x</p>'])->assertSessionHasErrors('kind');

        // Staff without order access cannot print it.
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get("/admin/orders/$order/packing-slip")->assertForbidden();
    }

    public function test_the_printed_and_emailed_summary_shares_the_saved_customer_invoice_section(): void
    {
        $this->freezeTime();
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $this->orderProduct('30')->id, ['invoice_requested' => true]));
        $url = '/admin/orders/'.$order.'/invoice';
        // Legacy invoice requests without a saved check do not acquire invented company information.
        $this->get($url)->assertOk()->assertDontSee('Invoice details');
        $name = 'Saved Company <script>alert("unsafe")</script> & Sons';
        DB::table('order_invoice_verifications')->insert(['order_id' => $order, 'country_code' => 'GR', 'vat_number' => '000000012', 'business_name' => $name, 'provider' => 'vies', 'verified_at' => now()]);
        DB::table('order_addresses')->where('order_id', $order)->update(['company' => 'Different billing company']);
        $html = (string) $this->get($url)->assertOk()->assertSee('Invoice details')->assertSee('000000012')->assertSee($name)->assertSee('Greece (GR)')->assertDontSee('<script>', false)->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $section = (new \DOMXPath($document))->query('//h2[text()="Invoice details"]/parent::div');
        self::assertSame(1, $section->length);
        self::assertStringContainsString($name, $section->item(0)->textContent);
        self::assertStringNotContainsString('Different billing company', $section->item(0)->textContent);
        self::assertStringNotContainsString('VIES', $section->item(0)->textContent, 'Provider configuration is not invoice billing data');
        self::assertLessThan(strpos($html, '<thead>'), strpos($html, '<h2'), 'The invoice section precedes products');
        // The standalone email and customer download use the same renderer; no new email is sent by rendering.
        $renderer = app(OrderEmailRenderer::class);
        $variables = $renderer->forOrder($order, null, $this->orderStatus('open'), now()->toIso8601String());
        $email = $renderer->render('Summary', '{{invoice}}', $variables, 'en', ['header_html' => '', 'footer_html' => '']);
        self::assertStringContainsString('000000012', $email['html']);
        self::assertStringContainsString('Invoice details', $email['html']);
        self::assertStringContainsString($name, $variables['text']['invoice_customer_details']);
        self::assertStringNotContainsString('{{', $html);
        self::assertStringNotContainsString('Invoice details', $renderer->packingSlipPage($order));
        DB::table('order_invoice_verifications')->where('order_id', $order)->update(['business_name' => null]);
        $this->get($url)->assertOk()->assertSee('000000012')->assertDontSee('Business name:');
        DB::table('orders')->where('id', $order)->update(['invoice_requested' => false]);
        $this->get($url)->assertOk()->assertDontSee('Invoice details')->assertDontSee('000000012');
    }

    public function test_a_custom_summary_can_place_the_customer_invoice_block_and_preview_it_without_changing_saved_templates(): void
    {
        $layout = $this->layout();
        $body = '<h1>Merchant design</h1>{{invoice_customer_details}}';
        $fields = ['version' => 1, 'subject' => 'Summary', 'body' => $body, 'item' => InvoiceLayout::item(), 'total' => InvoiceLayout::total()];
        $this->put('/admin/order-emails/order-summary/'.$layout->id, $fields)->assertSessionHasNoErrors();
        self::assertSame($body, $this->layout()->invoice_body_html);
        $preview = $this->postJson('/admin/order-emails/preview', ['kind' => 'invoice', 'locale' => 'en', ...$fields])->assertOk();
        self::assertStringContainsString('Merchant design', $preview->json('html'));
        self::assertStringContainsString('Example Company (demo)', $preview->json('html'));
        self::assertStringContainsString('000000012', $preview->json('html'));
        self::assertSame($body, $this->layout()->invoice_body_html);
        $this->get('/admin/order-emails/order-summary')->assertInertia(fn (Assert $page) => $page->where('variables', fn ($variables) => collect($variables)->contains(fn ($v) => $v['name'] === 'invoice_customer_details' && $v['block'] === true && $v['label'] !== 'orders.var_invoice_customer_details')));
    }
}
