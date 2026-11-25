<?php

namespace App\Customer\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's private wishlists. The general list ("All favourites") holds every saved product; named lists group some
 * of them. Saving to a named list also saves to the general list, and removing from the general list removes the
 * product from every list. Only products the customer may see can be added, and nobody reaches another customer's lists:
 * every operation names the owning customer and finds lists through it.
 */
final class Wishlists
{
    public const MAX_LISTS = 50;

    public const MAX_ITEMS = 500;

    public const NAME_MAX = 60;

    /** The customer's general list, created on first use. */
    public function general(string $customer): int
    {
        $id = DB::table('wishlists')->where('customer_id', $customer)->where('is_default', true)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        try {
            return (int) DB::table('wishlists')->insertGetId(['customer_id' => $customer, 'name' => null, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first.
            return (int) DB::table('wishlists')->where('customer_id', $customer)->where('is_default', true)->value('id');
        }
    }

    /**
     * The lists with their visible product counts and a picture of the newest product, general list first, then
     * named lists oldest first. With a product, each list also says whether it holds that product.
     *
     * @return list<array{id:string,name:string|null,default:bool,count:int,thumb:string|null,has:bool}>
     */
    public function lists(string $customer, ?string $product = null): array
    {
        $this->general($customer);
        $rows = DB::table('wishlists')->where('customer_id', $customer)->orderByDesc('is_default')->orderBy('id')->get(['id', 'name', 'is_default']);
        $visible = app(PublicCatalog::class)->products($customer)->select('products.id');
        $counts = DB::table('wishlist_items')->whereIn('wishlist_id', $rows->pluck('id'))->whereIn('product_id', $visible)->groupBy('wishlist_id')->selectRaw('wishlist_id, count(*) as n')->pluck('n', 'wishlist_id');
        // Newest visible product of each list (for its thumbnail), in one query.
        $newest = DB::table('wishlist_items as i')->whereIn('i.wishlist_id', $rows->pluck('id'))->whereIn('i.product_id', $visible)
            ->orderByDesc('i.created_at')->get(['i.wishlist_id', 'i.product_id'])->unique('wishlist_id')->pluck('product_id', 'wishlist_id');
        $pictures = DB::table('product_images')->whereIn('product_id', $newest->values())->where('is_primary', true)->pluck('media_file_id', 'product_id');
        $held = $product === null ? collect() : DB::table('wishlist_items')->whereIn('wishlist_id', $rows->pluck('id'))->where('product_id', $product)->pluck('wishlist_id');

        return $rows->map(fn (object $r): array => [
            'id' => (string) $r->id, 'name' => $r->name, 'default' => (bool) $r->is_default, 'count' => (int) ($counts[$r->id] ?? 0),
            'thumb' => isset($newest[$r->id], $pictures[$newest[$r->id]]) ? '/storefront/media/product/'.$newest[$r->id].'/'.$pictures[$newest[$r->id]] : null,
            'has' => $held->contains($r->id),
        ])->all();
    }

    /** @param list<string> $ids
     * @return list<string> The given products that are in the general list. */
    public function favourites(string $customer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $general = DB::table('wishlists')->where('customer_id', $customer)->where('is_default', true)->value('id');

        return $general === null ? [] : DB::table('wishlist_items')->where('wishlist_id', $general)->whereIn('product_id', $ids)->pluck('product_id')->map(strval(...))->all();
    }

    /** @return list<string> Product ids of a list, newest first (visibility is applied by the page). */
    public function products(string $customer, int $list): array
    {
        $this->owned($customer, $list);

        return DB::table('wishlist_items')->where('wishlist_id', $list)->orderByDesc('created_at')->orderByDesc('product_id')->pluck('product_id')->map(strval(...))->all();
    }

    public function create(string $customer, string $name, ?string $product = null): int
    {
        $name = $this->name($name);
        $this->general($customer);
        if (DB::table('wishlists')->where('customer_id', $customer)->count() > self::MAX_LISTS) {
            throw ValidationException::withMessages(['name' => __('storefront.wishlist_limit', ['max' => self::MAX_LISTS])]);
        }
        try {
            // The list and its first product are saved together, or neither is.
            return DB::transaction(function () use ($customer, $name, $product): int {
                $id = (int) DB::table('wishlists')->insertGetId(['customer_id' => $customer, 'name' => $name, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
                if ($product !== null) {
                    $this->add($customer, $id, $product);
                }

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('storefront.wishlist_name_taken')]);
        }
    }

    public function rename(string $customer, int $list, string $name): void
    {
        $row = $this->owned($customer, $list);
        abort_if($row->is_default, 404);
        try {
            DB::table('wishlists')->where('id', $list)->update(['name' => $this->name($name), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('storefront.wishlist_name_taken')]);
        }
    }

    /** Deleting a named list keeps its products in the general list. */
    public function delete(string $customer, int $list): void
    {
        abort_if($this->owned($customer, $list)->is_default, 404);
        DB::table('wishlists')->where('id', $list)->delete();
    }

    public function add(string $customer, int $list, string $product): void
    {
        $row = $this->owned($customer, $list);
        $visible = app(PublicCatalog::class)->products($customer)->where('products.id', $product)->exists();
        if (! $visible) {
            throw ValidationException::withMessages(['product' => __('storefront.wishlist_product_unavailable')]);
        }
        DB::transaction(function () use ($customer, $row, $list, $product): void {
            $general = $this->general($customer);
            foreach (array_unique([$general, $list]) as $target) {
                if (! DB::table('wishlist_items')->where('wishlist_id', $target)->where('product_id', $product)->exists()
                    && DB::table('wishlist_items')->where('wishlist_id', $target)->count() >= self::MAX_ITEMS) {
                    throw ValidationException::withMessages(['product' => __('storefront.wishlist_items_limit', ['max' => self::MAX_ITEMS])]);
                }
                DB::table('wishlist_items')->insertOrIgnore(['wishlist_id' => $target, 'product_id' => $product, 'created_at' => now()]);
            }
            DB::table('wishlists')->where('id', $row->id)->update(['updated_at' => now()]);
        });
    }

    public function remove(string $customer, int $list, string $product): void
    {
        $row = $this->owned($customer, $list);
        DB::transaction(function () use ($customer, $row, $list, $product): void {
            // Removing from the general list removes the product from every list of the customer.
            $targets = $row->is_default ? DB::table('wishlists')->where('customer_id', $customer)->pluck('id') : collect([$list]);
            DB::table('wishlist_items')->whereIn('wishlist_id', $targets)->where('product_id', $product)->delete();
        });
    }

    private function owned(string $customer, int $list): object
    {
        return DB::table('wishlists')->where('id', $list)->where('customer_id', $customer)->first() ?? abort(404);
    }

    private function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f<>]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => __('storefront.wishlist_name_invalid', ['max' => self::NAME_MAX])]);
        }

        return $name;
    }
}
