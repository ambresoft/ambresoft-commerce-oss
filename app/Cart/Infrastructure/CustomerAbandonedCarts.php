<?php

namespace App\Cart\Infrastructure;

use App\Cart\Application\Command\AbandonedCart;
use App\Cart\Application\Contract\AbandonedCarts;
use App\Localization\Infrastructure\LocaleCatalog;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class CustomerAbandonedCarts implements AbandonedCarts
{
    public function idleSince(DateTimeImmutable $before, int $afterId, int $limit): array
    {
        $default = app(LocaleCatalog::class)->defaultCode();
        $carts = DB::table('carts as c')->join('customers as cu', 'cu.id', '=', 'c.customer_id')->join('users as u', 'u.id', '=', 'cu.user_id')
            ->leftJoin('locales as l', fn ($j) => $j->on('l.id', '=', 'u.preferred_locale_id')->where('l.is_active', true))
            ->where('cu.status', 'active')->whereNull('cu.deleted_at')->where('c.updated_at', '<=', $before)->where('c.id', '>', $afterId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items as i')->whereColumn('i.cart_id', 'c.id'))
            ->orderBy('c.id')->limit($limit)->get(['c.id', 'c.customer_id', 'c.updated_at', 'cu.email', 'cu.first_name', 'l.code as locale']);
        $items = DB::table('cart_items')->whereIn('cart_id', $carts->pluck('id'))->orderBy('id')->get(['cart_id', 'product_id', 'quantity'])->groupBy('cart_id');
        $names = DB::table('product_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('t.product_id', $items->flatten()->pluck('product_id')->unique())
            ->get(['t.product_id', 'l.code', 't.name'])->groupBy('product_id');

        return $carts->map(function (object $c) use ($items, $names, $default): AbandonedCart {
            $locale = $c->locale ?? $default;
            $lines = ($items[$c->id] ?? collect())->map(function (object $i) use ($names, $locale, $default): array {
                $texts = ($names[$i->product_id] ?? collect())->keyBy('code');

                return ['name' => (string) ($texts[$locale]->name ?? $texts[$default]->name ?? $texts->first()->name ?? '#'.$i->product_id), 'quantity' => (int) $i->quantity];
            })->values()->all();

            return new AbandonedCart((string) $c->id, (string) $c->customer_id, (string) $c->email, (string) $c->first_name, $locale, CarbonImmutable::parse($c->updated_at), $lines, url('/cart'));
        })->values()->all();
    }
}
