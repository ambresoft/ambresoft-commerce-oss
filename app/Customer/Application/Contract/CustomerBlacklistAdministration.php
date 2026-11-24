<?php

namespace App\Customer\Application\Contract;

interface CustomerBlacklistAdministration
{
    /**
     * Adds one email or phone. The value is normalized; a reason is mandatory. `full` blocks it completely;
     * `payment_methods` only takes the given payment methods away at checkout. Returns the entry ID.
     *
     * @param  list<string>  $paymentMethodIds
     */
    public function add(string $actor, string $kind, string $value, string $reason, string $scope = 'full', array $paymentMethodIds = []): string;

    /**
     * Blacklists the customer's own stored email and/or phone (the values come from the record, never the request).
     *
     * @param  list<string>  $kinds  email, phone
     * @param  list<string>  $paymentMethodIds
     * @return list<string> Entry IDs created.
     */
    public function addForCustomer(string $actor, string $customerId, array $kinds, string $reason, string $scope = 'full', array $paymentMethodIds = []): array;

    /** Lifts an active entry; the record and reason are kept. */
    public function release(string $actor, string $entryId): void;
}
