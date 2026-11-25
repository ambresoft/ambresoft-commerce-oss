<?php

namespace App\Marketing\Infrastructure;

use App\Catalog\Infrastructure\ProductCategories;
use App\Marketing\Domain\Service\CouponAmounts;
use App\Order\Domain\Service\OrderTotals;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared by order quotes and saves; only saving an order consumes a redemption. */
final class CouponPricing
{
    /** @param list<array<string, mixed>> $lines
     * @return array{id: ?string, code: ?string, discounts: list<string>, free_shipping: bool, type: ?string, amount: string, eligible: list<bool>}
     */
    public function quote(?string $code, array $lines, string $subtotalBeforeTax, ?string $customer, string $email, int $scale, ?string $order = null, bool $lock = false): array
    {
        $empty = ['id' => null, 'code' => null, 'discounts' => array_fill(0, count($lines), '0'), 'free_shipping' => false, 'type' => null, 'amount' => '0', 'eligible' => array_fill(0, count($lines), false)];
        if ($code === null || trim($code) === '') {
            return $empty;
        }
        $query = DB::table('coupons')->where('code', trim($code));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($coupon === null || ! $this->available($coupon)) {
            $this->invalid('unavailable');
        }
        if ($coupon->login_required && $customer === null) {
            $this->invalid('login_needed');
        }
        if (Decimal::compare($subtotalBeforeTax, $coupon->minimum) < 0) {
            $this->invalid('minimum_not_met');
        }
        $this->capacity($coupon, $this->customerKey($customer, $email), $order);
        $products = array_values(array_filter(array_column($lines, 'product_id')));
        $eligible = $coupon->all_products ? $products : DB::table('products')->whereIn('id', $products)->where(function ($q) use ($coupon): void {
            $q->whereIn('id', DB::table('coupon_products')->where('coupon_id', $coupon->id)->select('product_id'))
                // A product counts in a coupon category by its main or any extra category.
                ->orWhereRaw(...ProductCategories::inQuery('WITH RECURSIVE selected AS (SELECT category_id AS id FROM coupon_categories WHERE coupon_id = ? UNION SELECT c.id FROM categories c JOIN selected s ON c.parent_id = s.id) SELECT id FROM selected', [$coupon->id]));
        })->pluck('id')->map(strval(...))->all();
        $weights = array_map(fn (array $line): string => in_array((string) $line['product_id'], $eligible, true) ? Decimal::multiply($line['unit_price'], (string) $line['quantity']) : '0', $lines);
        $total = array_reduce($weights, fn (string $sum, string $v): string => Decimal::add($sum, $v), '0');
        if (! array_intersect($products, $eligible)) {
            $this->invalid('no_products');
        }
        $discount = app(CouponAmounts::class)->discount($coupon->type, $coupon->amount, $total, $scale);
        $discounts = Decimal::compare($total, '0') > 0 ? app(OrderTotals::class)->allocate($discount, $weights, $scale) : $empty['discounts'];

        return ['id' => (string) $coupon->id, 'code' => $coupon->code, 'discounts' => $discounts, 'free_shipping' => (bool) $coupon->free_shipping, 'type' => $coupon->type, 'amount' => $coupon->amount, 'eligible' => array_map(fn (array $line): bool => in_array((string) $line['product_id'], $eligible, true), $lines)];
    }

    /** Calendar dates include the whole final day in the store timezone. */
    public function available(object $coupon): bool
    {
        $today = now()->timezone(app(GeneralSettings::class)->all()['timezone'])->toDateString();

        return $coupon->deleted_at === null && $coupon->status === 'active' && ($coupon->starts_on === null || $coupon->starts_on <= $today) && ($coupon->ends_on === null || $coupon->ends_on >= $today);
    }

    public function customerKey(?string $customer, string $email): string
    {
        // A stable normalized email also prevents resetting a guest allowance by creating an account.
        if ($customer !== null) {
            $email = (string) (DB::table('customers')->where('id', $customer)->value('email') ?? $email);
        }

        return hash('sha256', strtolower(trim($email)));
    }

    public function capacity(object $coupon, string $customerKey, ?string $order): void
    {
        $used = DB::table('coupon_redemptions')->where('coupon_id', $coupon->id)->whereNull('released_at')->when($order !== null, fn ($q) => $q->where('order_id', '<>', $order));
        if (($coupon->total_limit !== null && (clone $used)->count() >= (int) $coupon->total_limit) || ($coupon->customer_limit !== null && $used->where('customer_key', $customerKey)->count() >= (int) $coupon->customer_limit)) {
            $this->invalid('limit_reached');
        }
    }

    private function invalid(string $reason): never
    {
        throw ValidationException::withMessages(['coupon_code' => __('coupons.'.$reason)]);
    }
}
