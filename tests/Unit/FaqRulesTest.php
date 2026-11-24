<?php

namespace Tests\Unit;

use App\Website\Domain\Service\FaqRules;
use PHPUnit\Framework\TestCase;

final class FaqRulesTest extends TestCase
{
    public function test_general_faqs_are_reusable_and_specific_types_keep_their_target_meaning(): void
    {
        foreach (['category', 'product', 'page'] as $owner) {
            self::assertTrue(FaqRules::supports('general', $owner));
        }
        self::assertTrue(FaqRules::supports('category', 'category'));
        self::assertTrue(FaqRules::supports('product', 'product'));
        self::assertFalse(FaqRules::supports('category', 'product'));
        self::assertFalse(FaqRules::supports('product', 'page'));
        self::assertFalse(FaqRules::supports('general', 'unknown'));
    }
}
