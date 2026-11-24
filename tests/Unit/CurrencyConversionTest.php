<?php

namespace Tests\Unit;

use App\Pricing\Domain\Exception\CurrencyUnavailable;
use App\Pricing\Domain\Exception\InvalidCurrencyChange;
use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Model\Money;
use App\Pricing\Domain\Service\CurrencyConverter;
use App\Pricing\Domain\Service\CurrencyFormatter;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Shared\Domain\Service\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrencyConversionTest extends TestCase
{
    private function currency(string $code, string $rate = '1', bool $primary = false, bool $active = true, int $decimals = 2, string $symbol = '$', string $position = 'before', string $thousands = ',', string $decimal = '.'): Currency
    {
        return new Currency($code, $code, $code, $symbol, $position, $thousands, $decimal, $decimals, $rate, $primary, $active);
    }

    /** @return list<Currency> */
    private function set(string $usdRate = '1.1'): array
    {
        return [
            $this->currency('EUR', primary: true, symbol: '€'),
            $this->currency('USD', $usdRate),
            $this->currency('GBP', '0.85', symbol: '£'),
            $this->currency('JPY', '160.25', decimals: 0, symbol: '¥'),
            $this->currency('CHF', '0.95', active: false, symbol: 'CHF'),
        ];
    }

    public function test_rate_is_target_units_per_primary_unit_never_the_inverse(): void
    {
        $converter = new CurrencyConverter($this->set());
        $usd = $converter->convert('100', 'USD');
        // 100 EUR × 1.10 = 110 USD (the inverse would wrongly give 90.909…).
        self::assertSame('110.0', $usd->amount);
        self::assertSame('110.00', $usd->rounded());
        self::assertSame('USD', $usd->currency->code);
        self::assertSame('85.00', $converter->convert('100', 'GBP')->rounded());
    }

    public function test_primary_target_returns_the_original_unconverted_amount(): void
    {
        $converter = new CurrencyConverter($this->set());
        foreach ([null, 'EUR'] as $target) {
            $money = $converter->convert('19.990000', $target);
            self::assertSame('19.990000', $money->amount);
            self::assertTrue($money->currency->primary);
        }
    }

    public function test_exact_arithmetic_and_half_away_from_zero_rounding_at_target_precision(): void
    {
        $converter = new CurrencyConverter($this->set());
        self::assertSame('21.703143', Decimal::multiply('19.99', '1.0857'));
        self::assertSame('21.989', $converter->convert('19.99', 'USD')->amount); // exact, unrounded
        self::assertSame('21.99', $converter->convert('19.99', 'USD')->rounded());
        self::assertSame('1.38', $converter->convert('1.25', 'USD')->rounded()); // 1.375 tie rounds up
        self::assertSame('197829', $converter->convert('1234.5', 'JPY')->rounded()); // 197828.625, zero decimals
        self::assertSame('0.30', Decimal::round(Decimal::multiply('0.1', '3'), 2)); // no binary float error
        self::assertSame('1.01', Decimal::round('1.005', 2));
        self::assertSame('-1.01', Decimal::round('-1.005', 2));
        self::assertSame('0.00', Decimal::round('-0.001', 2));
        self::assertSame('0.9090909091', Decimal::divide('1', '1.1', 10));
        self::assertSame('0.6666666667', Decimal::divide('2', '3', 10));
        foreach (['1e3', '1.1E+2', 'NaN', 'INF', '', '1,5', '.5', ' 1'] as $invalid) {
            self::assertFalse(Decimal::isCanonical($invalid), $invalid);
        }
        $this->expectException(InvalidArgumentException::class);
        $converter->convert('1e3', 'USD');
    }

    public function test_formatting_uses_symbol_position_separators_precision_zero_and_negative_values(): void
    {
        $format = new CurrencyFormatter;
        $eur = $this->currency('EUR', primary: true, symbol: '€');
        $eurGreek = $this->currency('EUR', primary: true, symbol: '€', position: 'after', thousands: '.', decimal: ',');
        self::assertSame('€1,234,567.89', $format->format(new Money('1234567.891', $eur)));
        self::assertSame("1.234.567,89\u{00A0}€", $format->format(new Money('1234567.891', $eurGreek)));
        self::assertSame('€0.00', $format->format(new Money('0', $eur)));
        self::assertSame('€0.00', $format->format(new Money('-0.004', $eur)));
        self::assertSame('-€1,234.56', $format->format(new Money('-1234.56', $eur)));
        self::assertSame("-1.234,56\u{00A0}€", $format->format(new Money('-1234.56', $eurGreek)));
        self::assertSame('€999.00', $format->format(new Money('999', $eur)));
        self::assertSame('€1,000.00', $format->format(new Money('999.995', $eur)));
        self::assertSame('¥1234568', $format->format(new Money('1234567.5', $this->currency('JPY', '160', decimals: 0, symbol: '¥', thousands: ''))));
        self::assertSame("CHF1\u{00A0}234.500", $format->format(new Money('1234.5', $this->currency('CHF', '1', decimals: 3, symbol: 'CHF', thousands: "\u{00A0}"))));
        self::assertSame("1'234.50\u{00A0}Fr.", $format->format(new Money('1234.5', $this->currency('CHF', '1', symbol: 'Fr.', position: 'after', thousands: "'"))));
    }

    public function test_changed_rate_affects_only_subsequent_snapshots_and_never_the_base_amount(): void
    {
        $base = '100.00';
        $before = new CurrencyConverter($this->set('1.1'));
        $after = new CurrencyConverter($this->set('1.2'));
        self::assertSame('110.00', $before->convert($base, 'USD')->rounded());
        self::assertSame('120.00', $after->convert($base, 'USD')->rounded());
        self::assertSame('100.00', $base);
        self::assertSame('100.00', $after->convert($base)->amount);
    }

    public function test_unknown_and_disabled_targets_are_rejected(): void
    {
        $converter = new CurrencyConverter($this->set());
        self::assertSame(['EUR', 'GBP', 'JPY', 'USD'], array_map(fn (Currency $c): string => $c->code, $converter->active()));
        foreach (['CHF', 'XXX', 'usd'] as $code) {
            try {
                $converter->convert('1', $code);
                self::fail('Expected unavailable currency '.$code);
            } catch (CurrencyUnavailable $e) {
                self::assertSame($code, $e->currencyCode);
            }
        }
    }

    public function test_currency_invariants_and_single_primary_are_required(): void
    {
        foreach ([fn () => $this->currency('EUR', '1.1', primary: true), fn () => $this->currency('EUR', primary: true, active: false), fn () => $this->currency('USD', '0'), fn () => $this->currency('USD', '-1'), fn () => $this->currency('USD', decimals: 7), fn () => $this->currency('usd'), fn () => $this->currency('USD', thousands: '.', decimal: '.')] as $invalid) {
            try {
                $invalid();
                self::fail('Expected invalid currency');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([[], [$this->currency('EUR', primary: true), $this->currency('USD', primary: true)]] as $set) {
            try {
                new CurrencyConverter($set);
                self::fail('Expected exactly one primary');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_primary_change_rebases_rates_and_converts_prices_from_stored_amounts(): void
    {
        $change = new PrimaryCurrencyChange;
        $set = $this->set();
        $usd = $set[1];
        self::assertSame(['EUR' => '0.9090909091', 'USD' => '1', 'GBP' => '0.7727272727', 'JPY' => '145.6818181818', 'CHF' => '0.8636363636'], $change->rebasedRates($set, $usd));
        self::assertSame('21.99', $change->convertPrice('19.99', $usd));
        self::assertSame('1.38', $change->convertPrice('1.25', $usd));
        self::assertSame('0.00', $change->convertPrice('0', $usd));
        self::assertSame('197829', $change->convertPrice('1234.5', $set[3]));
        foreach ([[$set, $set[0], 'currencies.already_primary'], [$set, $set[4], 'currencies.primary_requires_active'], [[...$set, $tiny = $this->currency('XAU', '0.0000000001')], $tiny, 'currencies.rebased_rate_out_of_range']] as [$currencies, $target, $key]) {
            try {
                $change->rebasedRates($currencies, $target);
                self::fail('Expected '.$key);
            } catch (InvalidCurrencyChange $e) {
                self::assertSame($key, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('currencies.price_out_of_range');
        $change->convertPrice('99999999999999', $this->currency('USD', '2'));
    }
}
