<?php

namespace App\Tax\Infrastructure;

use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\TaxContext;
use Illuminate\Support\Facades\DB;

final class EloquentCustomerTaxProfiles implements CustomerTaxProfiles
{
    public function context(string $customerId, ?string $method = null): ?TaxContext
    {
        $customer = DB::table('customers')->where('id', $customerId)->first(['tax_exempt', 'default_billing_address_id', 'default_shipping_address_id']);
        if ($customer === null) {
            return null;
        }
        $ids = array_filter([$customer->default_billing_address_id, $customer->default_shipping_address_id]);
        $addresses = DB::table('customer_addresses')->where('customer_id', $customerId)->whereIn('id', $ids)->get(['id', 'country_code', 'region_code'])->keyBy('id');
        $address = fn ($id): ?Address => $id !== null && isset($addresses[$id]) ? new Address($addresses[$id]->country_code, $addresses[$id]->region_code) : null;

        return new TaxContext($address($customer->default_shipping_address_id), $address($customer->default_billing_address_id), (bool) $customer->tax_exempt, $method);
    }
}
