<?php

namespace App\Cart\Application\Contract;

use App\Cart\Application\Command\AbandonedCart;
use DateTimeImmutable;

/** Carts of active customers with an account that hold products and were last changed before a moment. */
interface AbandonedCarts
{
    /**
     * Pages through the carts by ID, oldest change first within a page.
     *
     * @return list<AbandonedCart>
     */
    public function idleSince(DateTimeImmutable $before, int $afterId, int $limit): array;
}
