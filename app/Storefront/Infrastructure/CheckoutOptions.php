<?php

namespace App\Storefront\Infrastructure;

use App\Settings\Infrastructure\CheckoutSettings;
use Illuminate\Support\Facades\DB;

/**
 * The checkboxes above the checkout's order button, set on the Checkout layout's main content block: pages to accept
 * (required ones must be ticked before ordering), a newsletter sign-up and an invoice request. The storefront shows
 * them and the server enforces them from the same block.
 */
final class CheckoutOptions
{
    /**
     * @param  array<string,mixed>  $block  The checkout's main content block (settings, translations, selections)
     * @return array{pages:list<array{id:string,name:string,content:string,required:bool}>,newsletter:?array{list:string,label:string},invoice:bool}
     */
    public function forBlock(array $block, string $locale, ?string $customer): array
    {
        $required = array_map(strval(...), $block['selections']['required_pages'] ?? []);
        $optional = array_values(array_diff(array_map(strval(...), $block['selections']['optional_pages'] ?? []), $required));
        // A page the visitor may not see cannot be accepted, so it is not asked for.
        $pages = app(StorefrontPages::class);
        $visible = $pages->visible([...$required, ...$optional], $customer);
        $list = $block['selections']['newsletter_list'][0] ?? null;
        $list = $list !== null && DB::table('newsletter_lists')->where('id', $list)->where('status', 'active')->exists() ? (string) $list : null;
        // A signed-in customer already on the list (confirmed or awaiting confirmation) is not asked again. Guests are
        // always asked: telling them would reveal whether a typed address is subscribed.
        if ($list !== null && $customer !== null && $this->subscribed($list, $customer)) {
            $list = null;
        }
        $label = trim(app(BlockContent::class)->text($block['translations'] ?? [], $locale, 'link_text'));
        $invoiceSettings = app(CheckoutSettings::class)->all();

        return [
            'pages' => array_map(fn (string $id): array => [...array_intersect_key($pages->data($id, $locale), array_flip(['id', 'name', 'content'])), 'required' => in_array($id, $required, true)], $visible),
            'newsletter' => $list === null ? null : ['list' => $list, 'label' => $label !== '' ? $label : __('storefront.checkout_newsletter')],
            'invoice' => (bool) ($block['settings']['show_invoice'] ?? true) && $invoiceSettings['enabled'] && InvoiceVerification::countryCodes($invoiceSettings) !== [],
        ];
    }

    private function subscribed(string $list, string $customer): bool
    {
        $email = mb_strtolower((string) DB::table('customers')->where('id', $customer)->value('email'));

        return DB::table('newsletter_list_subscribers as m')->join('newsletter_subscribers as s', 's.id', '=', 'm.subscriber_id')
            ->where('m.list_id', $list)->where('s.status', 'active')->where(fn ($q) => $q->where('s.customer_id', $customer)->orWhereRaw('lower(s.email) = ?', [$email]))->exists();
    }

    /** @return array{pages:list<array{id:string,name:string,content:string,required:bool}>,newsletter:?array{list:string,label:string},invoice:bool} */
    public function current(string $locale, ?string $customer): array
    {
        $layout = app(LayoutStore::class)->read('checkout', true);
        foreach ($layout['placements'] as $placement) {
            if ($placement['block']['type'] === 'main_content') {
                return $this->forBlock($placement['block'], $locale, $customer);
            }
        }

        return $this->forBlock([], $locale, $customer);
    }
}
