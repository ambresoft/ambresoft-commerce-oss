<?php

namespace App\Customer\Presentation\Http\Controllers;

use App\Customer\Infrastructure\Wishlists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in customer's wishlists as JSON for the "add to list" window and the wishlist page. */
final class WishlistController
{
    public function __construct(private Wishlists $wishlists) {}

    /** Lists with whether each holds the product, for the window opened from a product's heart. */
    public function forProduct(Request $request, string $product): JsonResponse
    {
        return $this->state($request, $product);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'product_id' => ['nullable', 'integer', 'min:1']]);
        $product = isset($data['product_id']) ? (string) $data['product_id'] : null;
        $created = $this->wishlists->create($this->customer($request), $data['name'], $product);

        return $this->state($request, $product, $created);
    }

    public function update(Request $request, string $wishlist): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $this->wishlists->rename($this->customer($request), (int) $wishlist, $data['name']);

        return $this->state($request, null);
    }

    public function destroy(Request $request, string $wishlist): JsonResponse
    {
        $this->wishlists->delete($this->customer($request), (int) $wishlist);

        return $this->state($request, null);
    }

    public function add(Request $request, string $wishlist, string $product): JsonResponse
    {
        $this->wishlists->add($this->customer($request), (int) $wishlist, $product);

        return $this->state($request, $product);
    }

    public function remove(Request $request, string $wishlist, string $product): JsonResponse
    {
        $this->wishlists->remove($this->customer($request), (int) $wishlist, $product);

        return $this->state($request, $product);
    }

    private function customer(Request $request): string
    {
        return (string) AccountProfile::of($request)->id;
    }

    private function state(Request $request, ?string $product, ?int $created = null): JsonResponse
    {
        $customer = $this->customer($request);

        return response()->json(['lists' => $this->wishlists->lists($customer, $product), 'favorite' => $product !== null && $this->wishlists->favourites($customer, [$product]) !== [], 'created' => $created === null ? null : (string) $created])
            ->header('Cache-Control', 'private, no-store');
    }
}
