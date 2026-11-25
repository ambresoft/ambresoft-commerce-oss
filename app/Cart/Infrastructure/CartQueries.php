<?php

namespace App\Cart\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderMoney;
use App\Storefront\Infrastructure\CartPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read models for the administration's Carts screens. Filtering, sorting and pagination happen in PostgreSQL. */
final class CartQueries
{
    public const SORTS = ['updated_at', 'created_at', 'units'];

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters, bool $customerDetails): array
    {
        $query = $this->base();
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $q) use ($like, $search, $customerDetails): void {
                $q->whereRaw('c.uuid::text ILIKE ?', [$like])->orWhereRaw("(cu.first_name || ' ' || cu.last_name) ILIKE ?", [$like]);
                if ($customerDetails) {
                    $q->orWhere('cu.email', 'ilike', $like);
                }
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $search) === 1) {
                    $q->orWhere('c.id', $search);
                }
            });
        }
        if (($filters['type'] ?? '') === 'customer') {
            $query->whereNotNull('c.customer_id');
        } elseif (($filters['type'] ?? '') === 'guest') {
            $query->whereNull('c.customer_id');
        }
        if (($filters['contents'] ?? '') === 'with') {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('cart_items as i')->whereColumn('i.cart_id', 'c.id'));
        } elseif (($filters['contents'] ?? '') === 'empty') {
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('cart_items as i')->whereColumn('i.cart_id', 'c.id'));
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'updated_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort === 'units' ? 'units' : 'c.'.$sort, $direction)->orderBy('c.id', $direction);
        $page = $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $page->setCollection(collect($page->items())->map(fn (object $row): array => $this->projection($row, $customerDetails)));

        return $page->toArray();
    }

    /**
     * One cart with its lines priced for its owner as the storefront would show them now. Lines of products the owner can
     * no longer see are listed too, marked hidden.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $id, bool $customerDetails): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
            return null;
        }
        $row = $this->base()->where('c.id', $id)->first();
        if ($row === null) {
            return null;
        }
        $cart = DB::table('carts')->where('id', $id)->first();
        $page = app(CartPage::class)->data($cart, ['locale' => app()->getLocale(), 'customer' => $cart->customer_id === null ? null : (string) $cart->customer_id]);
        $shown = array_column($page['lines'], 'id');
        $default = (int) app(LocaleCatalog::class)->active()->firstWhere('code', app(LocaleCatalog::class)->defaultCode())->id;
        $hidden = DB::table('cart_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_translations as t', fn ($j) => $j->on('t.product_id', '=', 'p.id')->where('t.locale_id', $default))
            ->where('i.cart_id', $id)->whereNotIn('i.product_id', $shown)->orderBy('i.created_at')->orderBy('i.id')
            ->get(['p.id', 'p.sku', 'i.quantity', DB::raw("coalesce(nullif(t.name, ''), '#' || p.id) as name")])
            ->map(fn (object $p): array => ['id' => (string) $p->id, 'name' => $p->name, 'sku' => $p->sku, 'quantity' => (int) $p->quantity])->all();

        $format = $page['currency_format'];
        $money = fn (?string $amount): ?string => $amount === null ? null : app(OrderMoney::class)->format($amount, $format['code'], (int) $format['decimals']);
        $items = array_map(fn (array $line): array => ['id' => $line['id'], 'name' => $line['name'], 'sku' => $line['sku'], 'url' => $line['url'], 'image' => $line['image'],
            'quantity' => $line['quantity'], 'price' => $money($line['price']), 'total' => $money($line['total']), 'problem' => $line['problem']], $page['lines']);

        return [...$this->projection($row, $customerDetails), 'items' => $items, 'hidden' => $hidden, 'subtotal' => $money($page['subtotal'])];
    }

    private function base(): Builder
    {
        return DB::table('carts as c')->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->select(['c.id', 'c.uuid', 'c.customer_id', 'c.created_at', 'c.updated_at', 'cu.first_name', 'cu.last_name', 'cu.email'])
            ->selectRaw('(SELECT count(*) FROM cart_items i WHERE i.cart_id = c.id) AS lines')
            ->selectRaw('(SELECT coalesce(sum(i.quantity), 0) FROM cart_items i WHERE i.cart_id = c.id) AS units');
    }

    /** @return array<string, mixed> */
    private function projection(object $row, bool $customerDetails): array
    {
        return [
            'id' => (string) $row->id, 'uuid' => $row->uuid,
            'customer' => $row->customer_id === null ? null : ['id' => (string) $row->customer_id, 'name' => trim($row->first_name.' '.$row->last_name), 'email' => $customerDetails ? $row->email : null],
            'lines' => (int) $row->lines, 'units' => (int) $row->units,
            'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
        ];
    }
}
