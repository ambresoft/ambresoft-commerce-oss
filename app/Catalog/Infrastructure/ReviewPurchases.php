<?php

namespace App\Catalog\Infrastructure;

use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Reporting\Infrastructure\ReportOrders;
use Illuminate\Support\Facades\DB;

/**
 * Verified purchase: the customer has a **sale** (Reporting's single definition: captured payments cover a positive
 * total and the current status is not void, i.e. not final + restore stock) whose lines contain the exact reviewed
 * product. Placing, or merely creating, an order is not proof; any currency counts. No refund records exist, so a
 * paid order that is later cancelled stops qualifying through its void status, and partial refunds cannot be seen.
 */
final class ReviewPurchases
{
    public function __construct(private ReportOrders $orders) {}

    public function verified(string $customerId, string $productId): bool
    {
        return $this->verifiedPairs([[$customerId, $productId]]) !== [];
    }

    /**
     * Products on the customer's sales (same rule as verification), most recently paid first.
     *
     * @return list<string>
     */
    public function purchasedProducts(string $customerId): array
    {
        $filters = new ReportFilters(ReportPeriod::preset('today', new \DateTimeImmutable), false, 'EUR');
        $only = DB::table('orders')->where('customer_id', $customerId)->select('id');
        $sales = $this->orders->paid($filters, null, anyCurrency: true, only: $only)->select('o.id', 'pay.paid_at');

        return DB::table('order_items as i')->joinSub($sales, 'sale', 'sale.id', '=', 'i.order_id')->whereNotNull('i.product_id')
            ->groupBy('i.product_id')->orderByRaw('max(sale.paid_at) DESC')->orderBy('i.product_id')->limit(200)->pluck('i.product_id')->map(strval(...))->all();
    }

    /**
     * One query for any number of customer/product pairs.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, true> keys "customerId:productId" of the verified pairs
     */
    public function verifiedPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $customers = array_values(array_unique(array_column($pairs, 0)));
        $products = array_values(array_unique(array_column($pairs, 1)));
        $only = DB::table('orders as vo')->whereIn('vo.customer_id', $customers)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('order_items as vi')->whereColumn('vi.order_id', 'vo.id')->whereIn('vi.product_id', $products))
            ->select('vo.id');
        $filters = new ReportFilters(ReportPeriod::preset('today', new \DateTimeImmutable), false, 'EUR');
        $sales = $this->orders->paid($filters, null, anyCurrency: true, only: $only)->select('o.id', 'o.customer_id');
        $wanted = array_flip(array_map(fn (array $p): string => $p[0].':'.$p[1], $pairs));
        $verified = [];
        foreach (DB::table('order_items as i')->joinSub($sales, 'sale', 'sale.id', '=', 'i.order_id')->whereIn('i.product_id', $products)->distinct()->get(['sale.customer_id', 'i.product_id']) as $r) {
            $key = $r->customer_id.':'.$r->product_id;
            if (isset($wanted[$key])) {
                $verified[$key] = true;
            }
        }

        return $verified;
    }
}
