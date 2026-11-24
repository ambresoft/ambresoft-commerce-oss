<?php

namespace App\Customer\Application\Contract;

use App\Customer\Application\Service\BlacklistMatch;

/**
 * Read port for purchasing flows (checkout and order creation, a later phase): a blacklisted email or phone must
 * not place an order. Both values are normalized before comparison.
 */
interface CustomerBlacklist
{
    /** The first active full block (email before phone), or null when neither value is blacklisted. */
    public function match(?string $email, ?string $phone): ?BlacklistMatch;

    /**
     * Payment methods taken away from this email or any of these phones by active `payment_methods` entries.
     *
     * @param  list<?string>  $phones
     * @return list<string> Payment method IDs.
     */
    public function restrictedPaymentMethods(?string $email, array $phones): array;
}
