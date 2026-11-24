<?php

namespace App\Storefront\Application\Command;

/** A public product. Texts are plain, with markup removed; URLs are absolute. */
final readonly class CatalogProduct
{
    /**
     * @param  list<string>  $images  Primary image first
     * @param  list<array{id: string, name: string, value: string, code?: string, type?: string, unit?: ?string, number?: ?string}>  $attributes  Descriptive features as displayed, with the feature's code, type and unit and, for a number, its plain value
     * @param  ?CatalogPricing  $pricing  Null while the store hides prices
     * @param  ?array{name:string,url:?string}  $brand
     * @param  ?array{average:?string,count:int}  $rating
     * @param  ?array{width:int,height:int}  $imageSize
     * @param  ?string  $variation  The variation this product belongs to (its group), an opaque ID shared by its variants
     * @param  list<string>  $variationFeatures  IDs of the features that tell the group's variants apart
     * @param  list<string>  $companions  Public "frequently bought together" products, in the merchant's order
     * @param  ?string  $gtin  The product's EAN/GTIN, digits only
     * @param  string  $shortDescription  The plain short description; $description falls back to it when empty
     * @param  ?string  $weightGrams  Shipping weight in grams as a decimal string, null when not set
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ?string $url,
        public array $images,
        public string $sku,
        public CatalogCategory $category,
        public array $attributes,
        public ?CatalogPricing $pricing,
        public CatalogAvailability $availability,
        public ?array $brand = null,
        public ?array $rating = null,
        public ?array $imageSize = null,
        public ?int $discountPercent = null,
        public ?string $variation = null,
        public array $variationFeatures = [],
        public array $companions = [],
        public ?string $gtin = null,
        public string $shortDescription = '',
        public ?string $weightGrams = null,
    ) {}
}
