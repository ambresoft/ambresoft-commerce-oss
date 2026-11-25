<?php

namespace App\Pricing\Domain\Service;

use App\Shared\Domain\Service\Decimal;

final class ProductSaleRules
{
    public function price(string $base, string $kind, string $amount, int $scale): string
    {
        if (! in_array($kind, ['fixed', 'percentage'], true) || Decimal::compare($amount, '0') < 0 || ($kind === 'percentage' && Decimal::compare($amount, '100') > 0)) {
            throw new \InvalidArgumentException('Invalid sale amount or kind.');
        }

        return Decimal::round($kind === 'percentage' ? Decimal::multiply(Decimal::multiply($base, Decimal::subtract('100', $amount)), '0.01') : $amount, $scale);
    }

    /**
     * @param  string  $moment  Store-time date (Y-m-d) or date and time (Y-m-d H:i:s).
     * @param  list<array{id: string, kind: string, amount: string, minimum_quantity: int, priority: int, starts_on: ?string, ends_on: ?string, starts_time?: ?string, ends_time?: ?string, remaining: ?int}>  $offers
     * @return array{id: string, price: string}|null
     */
    public function choose(string $base, int $quantity, string $moment, array $offers, int $scale): ?array
    {
        // A date alone means the start of that day; sale times are optional and default to the whole day.
        $moment = strlen($moment) === 10 ? $moment.' 00:00:00' : $moment;
        $eligible = [];
        foreach ($offers as $offer) {
            $starts = $offer['starts_on'] === null ? null : $offer['starts_on'].' '.($offer['starts_time'] ?? '00:00:00');
            $ends = $offer['ends_on'] === null ? null : $offer['ends_on'].' '.($offer['ends_time'] ?? '23:59:59');
            if ($quantity < $offer['minimum_quantity'] || ($starts !== null && $moment < $starts) || ($ends !== null && $moment > $ends) || ($offer['remaining'] !== null && $quantity > $offer['remaining'])) {
                continue;
            }
            $price = $this->price($base, $offer['kind'], $offer['amount'], $scale);
            if (Decimal::compare($price, $base) >= 0) {
                continue;
            }
            $eligible[] = [...$offer, 'price' => $price];
        }
        usort($eligible, fn (array $a, array $b): int => ($b['minimum_quantity'] <=> $a['minimum_quantity']) ?: ($a['priority'] <=> $b['priority']) ?: Decimal::compare($a['price'], $b['price']) ?: ((int) $a['id'] <=> (int) $b['id']));

        return $eligible === [] ? null : ['id' => $eligible[0]['id'], 'price' => $eligible[0]['price']];
    }
}
