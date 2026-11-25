<?php

namespace Tests\Unit;

use Ambresoft\StripeCheckout\Domain\StripeCurrencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../modules/ambresoft/stripe-checkout/src/Domain/StripeCurrencies.php';

final class StripeCurrenciesTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'euro cents' => ['24.80', 'EUR', '2480'],
            'whole euros' => ['25', 'EUR', '2500'],
            'trailing zeros are trimmed first' => ['24.500000', 'EUR', '2450'],
            'leading digits are kept' => ['0.05', 'EUR', '5'],
            'zero-decimal currency' => ['1500', 'JPY', '1500'],
            'zero-decimal never multiplied by 100' => ['1', 'KRW', '1'],
            'three-decimal currency, last digit zero' => ['1.25', 'KWD', '1250'],
            'largest allowed amount' => ['999999.99', 'EUR', '99999999'],
            'too many decimals is never rounded' => ['24.805', 'EUR', null],
            'zero-decimal with decimals is refused' => ['10.5', 'JPY', null],
            'three-decimal needs the last digit zero' => ['1.255', 'KWD', null],
            'above Stripe maximum' => ['1000000.00', 'EUR', null],
            'zero' => ['0', 'EUR', null],
            'negative' => ['-5', 'EUR', null],
            'non canonical' => ['1e3', 'EUR', null],
            'special currency not offered' => ['100', 'HUF', null],
            'unknown currency' => ['100', 'XXX', null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_minor_units_are_exact_integers_or_refused(string $amount, string $currency, ?string $minor): void
    {
        self::assertSame($minor, StripeCurrencies::toMinor($amount, $currency));
    }

    public function test_minor_units_convert_back_to_canonical_decimals(): void
    {
        self::assertSame('24.8', StripeCurrencies::fromMinor(2480, 'EUR'));
        self::assertSame('0.05', StripeCurrencies::fromMinor(5, 'EUR'));
        self::assertSame('25', StripeCurrencies::fromMinor(2500, 'EUR'));
        self::assertSame('1500', StripeCurrencies::fromMinor(1500, 'JPY'));
        self::assertSame('1.25', StripeCurrencies::fromMinor(1250, 'KWD'));
        self::assertNull(StripeCurrencies::fromMinor(100, 'HUF'));
        self::assertNull(StripeCurrencies::fromMinor(-1, 'EUR'));
    }

    public function test_support_depends_on_the_shop_precision_fitting_stripes(): void
    {
        self::assertTrue(StripeCurrencies::supports('EUR', 2));
        self::assertTrue(StripeCurrencies::supports('EUR', 0));
        self::assertFalse(StripeCurrencies::supports('EUR', 3));
        self::assertTrue(StripeCurrencies::supports('JPY', 0));
        self::assertFalse(StripeCurrencies::supports('JPY', 2));
        self::assertTrue(StripeCurrencies::supports('KWD', 2));
        self::assertFalse(StripeCurrencies::supports('KWD', 3));
        self::assertFalse(StripeCurrencies::supports('ISK', 0));
        self::assertFalse(StripeCurrencies::supports('HUF', 0));
    }
}
