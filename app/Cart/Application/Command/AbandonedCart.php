<?php

namespace App\Cart\Application\Command;

use DateTimeImmutable;

/** A signed-up customer's cart that still holds products and has not changed for a while. */
final readonly class AbandonedCart
{
    /** @param list<array{name: string, quantity: int}> $lines Product names in the customer's language */
    public function __construct(
        public string $cartId,
        public string $customerId,
        public string $email,
        public string $firstName,
        public string $locale,
        public DateTimeImmutable $updatedAt,
        public array $lines,
        public string $cartUrl,
    ) {}
}
