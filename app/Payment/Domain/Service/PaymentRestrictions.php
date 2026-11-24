<?php

namespace App\Payment\Domain\Service;

/**
 * Which products a payment method cannot pay for. A method may exclude physical or digital products and any number of
 * categories; excluding a category excludes all of its subcategories too, as the category age check does. A cart can
 * use the method only when none of its products is excluded.
 */
final class PaymentRestrictions
{
    public const TYPES = ['physical', 'digital'];

    /**
     * The first product the method cannot pay for and why (`physical`, `digital` or `category`), or null when it can
     * pay for them all.
     *
     * @param  list<string>  $excludedCategories
     * @param  list<array{id:string,type:string,categories:list<string>}>  $products  categories: the product's category and all its ancestors
     * @return array{product:string,reason:string}|null
     */
    public static function blocked(bool $excludePhysical, bool $excludeDigital, array $excludedCategories, array $products): ?array
    {
        foreach ($products as $product) {
            if (($product['type'] === 'physical' && $excludePhysical) || ($product['type'] === 'digital' && $excludeDigital)) {
                return ['product' => $product['id'], 'reason' => $product['type']];
            }
            if (array_intersect($product['categories'], $excludedCategories) !== []) {
                return ['product' => $product['id'], 'reason' => 'category'];
            }
        }

        return null;
    }
}
