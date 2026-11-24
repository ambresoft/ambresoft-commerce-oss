<?php

namespace Tests\Unit\Marketing;

use App\Marketing\Domain\Service\GiftCardRules;
use PHPUnit\Framework\TestCase;

final class GiftCardRulesTest extends TestCase
{
    public function test_codes_use_the_unambiguous_alphabet_and_the_agreed_shape(): void
    {
        self::assertSame('GC-9999-9999-9999', GiftCardRules::code(fn (int $bound): int => $bound - 1));
        self::assertSame('GC-AAAA-AAAA-AAAA', GiftCardRules::code(fn (): int => 0));
        self::assertFalse(strpbrk(GiftCardRules::ALPHABET, 'O0I1'), 'Easily misread characters are not used');
        for ($i = 0; $i < 50; $i++) {
            self::assertTrue(GiftCardRules::isCode(GiftCardRules::code(fn (int $bound): int => random_int(0, $bound - 1))));
        }
    }

    public function test_typed_codes_are_normalised_but_not_guessed(): void
    {
        self::assertSame('GC-ABCD-EFGH-JKLM', GiftCardRules::normalize("  gc-abcd-efgh-jklm \n"));
        self::assertTrue(GiftCardRules::isCode('GC-ABCD-EFGH-JKLM'));
        foreach (['GC-ABCD-EFGH', 'gc-abcd-efgh-jklm', 'GC-ABCD-EFGH-JKLM-X', 'XX-ABCD-EFGH-JKLM', "GC-ABCD-EFGH-JKLM\n", ''] as $bad) {
            self::assertFalse(GiftCardRules::isCode($bad), $bad);
        }
    }

    public function test_status_changes_follow_the_lifecycle(): void
    {
        foreach (['pending', 'active', 'cancelled'] as $from) {
            foreach (['pending', 'active', 'cancelled'] as $to) {
                self::assertTrue(GiftCardRules::canChange($from, $to));
            }
            self::assertFalse(GiftCardRules::canChange($from, 'used'), 'Staff never choose "used"');
        }
        foreach (['pending', 'active', 'cancelled', 'used'] as $to) {
            self::assertFalse(GiftCardRules::canChange('used', $to), 'A used card has nothing left');
        }
        self::assertTrue(GiftCardRules::canRedeem('active'));
        foreach (['pending', 'cancelled', 'used'] as $status) {
            self::assertFalse(GiftCardRules::canRedeem($status));
        }
    }

    public function test_a_card_pays_no_more_than_its_balance_or_the_amount_due(): void
    {
        self::assertSame('30', GiftCardRules::applicable('30.00', '100.00'));
        self::assertSame('100', GiftCardRules::applicable('150.00', '100.00'));
        self::assertSame('99.99', GiftCardRules::applicable('99.990000', '100'));
        self::assertSame('0', GiftCardRules::applicable('50', '0'));
        self::assertSame('0', GiftCardRules::applicable('0', '50'));
        self::assertSame('used', GiftCardRules::afterRedemption('active', '0.000000'));
        self::assertSame('active', GiftCardRules::afterRedemption('active', '0.01'));
        self::assertSame('GC-••••-••••-JKLM', GiftCardRules::masked('GC-ABCD-EFGH-JKLM'));
    }
}
