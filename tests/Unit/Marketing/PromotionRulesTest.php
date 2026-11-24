<?php

namespace Tests\Unit\Marketing;

use App\Marketing\Domain\Service\PromotionRules;
use PHPUnit\Framework\TestCase;

final class PromotionRulesTest extends TestCase
{
    public function test_flat_matching_empty_rules_ranges_and_server_time_boundaries(): void
    {
        $rules = new PromotionRules;
        self::assertTrue($rules->matches('all', []));
        self::assertTrue($rules->matches('any', []));
        self::assertFalse($rules->matches('all', [true, false]));
        self::assertTrue($rules->matches('any', [true, false]));
        self::assertTrue($rules->subtotal('50', '50', '200'));
        self::assertTrue($rules->subtotal('200', '50', '200'));
        self::assertFalse($rules->subtotal('200.01', '50', '200'));
        self::assertFalse($rules->active('active', '2026-10-18 14:00:00', '2026-10-18 16:00:00', '2026-10-18 13:59:59'));
        self::assertTrue($rules->active('active', '2026-10-18 14:00:00', '2026-10-18 16:00:00', '2026-10-18 14:00:00'));
        self::assertFalse($rules->active('active', '2026-10-18 14:00:00', '2026-10-18 16:00:00', '2026-10-18 16:00:00'));
        self::assertFalse($rules->active('disabled', null, null, '2026-10-18 15:00:00'));
    }
}
