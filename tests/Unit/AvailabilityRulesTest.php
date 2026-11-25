<?php

namespace Tests\Unit;

use App\Catalog\Domain\Service\AvailabilityRules;
use PHPUnit\Framework\TestCase;

final class AvailabilityRulesTest extends TestCase
{
    public function test_display_bucket_respects_inventory_tracking(): void
    {
        self::assertSame('in_stock', AvailabilityRules::bucket(1, true));
        self::assertSame('out_of_stock', AvailabilityRules::bucket(0, true));
        self::assertSame('in_stock', AvailabilityRules::bucket(0, false));
    }
}
