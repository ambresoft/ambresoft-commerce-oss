<?php

namespace App\Storefront\Infrastructure;

use App\Website\Domain\Service\MenuRules;
use Illuminate\Support\Facades\Validator;

/** Localized content owned by the Identity block, separate from its presentation settings. */
final class MobileMenuLinks
{
    /** @return array{title:string,items:list<array{label:string,url:string,new_window:bool}>} */
    public static function defaults(string $locale): array
    {
        return ['title' => __('storefront.account', [], $locale), 'items' => array_map(fn (array $link): array => ['label' => __($link[0], [], $locale), 'url' => $link[1], 'new_window' => false], [
            ['storefront.account', '/account'], ['storefront.cart', '/cart'], ['storefront.favorites', '/wishlist'],
        ])];
    }

    /** @return array<string,mixed> */
    public static function validate(mixed $value): array
    {
        $valid = Validator::make(['mobile_links' => $value], [
            'mobile_links' => ['required', 'array:title,items'],
            'mobile_links.title' => ['present', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f<>]/'],
            'mobile_links.items' => ['present', 'array', 'list', 'max:50'],
            'mobile_links.items.*' => ['required', 'array:label,url,new_window'],
            'mobile_links.items.*.label' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f<>]/'],
            'mobile_links.items.*.url' => ['required', 'string', 'max:2048', fn (string $key, mixed $url, \Closure $fail) => is_string($url) && MenuRules::safeUrl($url) ?: $fail(__('layouts.link_url_invalid'))],
            'mobile_links.items.*.new_window' => ['required', 'boolean'],
        ])->validate()['mobile_links'];
        $valid['title'] = (string) ($valid['title'] ?? '');

        return $valid;
    }
}
