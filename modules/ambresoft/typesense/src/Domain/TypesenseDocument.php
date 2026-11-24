<?php

namespace Ambresoft\Typesense\Domain;

use App\Search\Application\Command\SearchDocument;

/** A CMS search document in the collection's field layout (see TypesenseSchema). */
final class TypesenseDocument
{
    /** @return array<string, mixed> */
    public static function from(SearchDocument $d): array
    {
        $doc = [
            'listing_key' => $d->listingGroup === null ? 'p'.$d->id : 'g'.$d->listingGroup,
            'id' => $d->id, 'product_id' => (int) $d->id, 'codes' => $d->codes,
            'category_id' => (int) $d->categoryId, 'price' => TypesenseSchema::scaled($d->price), 'in_stock' => $d->inStock,
            'rating_tenths' => $d->ratingTenths ?? 0, 'public' => $d->groups === [], 'created_at' => $d->createdAt,
        ];
        foreach ($d->names as $code => $name) {
            $doc['name_'.$code] = $name;
        }
        foreach ($d->brandNames as $code => $name) {
            $doc['brand_'.$code] = $name;
        }
        foreach ($d->categoryNames as $code => $names) {
            $doc['category_'.$code] = $names;
        }
        foreach ($d->descriptions as $code => $text) {
            $doc['text_'.$code] = $text;
        }
        if ($d->brandId !== null) {
            $doc['brand_id'] = (int) $d->brandId;
        }
        if ($d->availabilityStatusId !== null) {
            $doc['status_id'] = (int) $d->availabilityStatusId;
        }
        if ($d->ratingTenths !== null) {
            $doc['rating'] = intdiv($d->ratingTenths, 10);
        }
        if ($d->groups !== []) {
            $doc['groups'] = $d->groups;
        }
        if ($d->flags !== []) {
            $doc['flags'] = array_map(intval(...), $d->flags);
        }
        foreach ($d->options as $feature => $options) {
            $doc['opt_'.(int) $feature] = array_map(intval(...), $options);
        }
        foreach ($d->numbers as $feature => $number) {
            $doc['num_'.(int) $feature] = TypesenseSchema::scaled($number);
        }

        return $doc;
    }
}
