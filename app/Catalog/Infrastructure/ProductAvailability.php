<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\AvailabilityRules;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Support\Facades\DB;

final class ProductAvailability
{
    /** @return array{id: string, name: string, bucket: string, inherited: bool} */
    public function forProduct(ProductRecord $product, ?string $locale = null): array
    {
        return $this->forProducts([$product], $locale)[$product->getKey()];
    }

    /**
     * The same answer for many products, in one read of the statuses.
     *
     * @param  iterable<ProductRecord>  $products
     * @return array<int|string, array{id: string, name: string, bucket: string, inherited: bool}> By product ID
     */
    public function forProducts(iterable $products, ?string $locale = null): array
    {
        $track = (bool) app(GeneralSettings::class)->all()['track_inventory'];
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', $locale ?? app()->getLocale())->id ?? $default;
        $statuses = DB::table('availability_statuses as s')
            ->leftJoin('availability_status_translations as c', fn ($j) => $j->on('c.status_id', '=', 's.id')->where('c.locale_id', $current))
            ->leftJoin('availability_status_translations as d', fn ($j) => $j->on('d.status_id', '=', 's.id')->where('d.locale_id', $default))
            ->select('s.id', 's.default_in_stock', 's.default_out_of_stock')->selectRaw("coalesce(c.name, d.name, '#' || s.id) as name")->get()->keyBy('id');
        $resolved = [];
        foreach ($products as $product) {
            $bucket = AvailabilityRules::bucket($product->quantity, $track);
            $selected = $product->getAttribute($bucket.'_status_id');
            $status = $selected === null ? $statuses->firstWhere('default_'.$bucket, true) : $statuses->get($selected);
            if ($status === null) {
                throw new \LogicException('The availability status configuration is incomplete.');
            }
            $resolved[$product->getKey()] = ['id' => (string) $status->id, 'name' => $status->name, 'bucket' => $bucket, 'inherited' => $selected === null];
        }

        return $resolved;
    }
}
