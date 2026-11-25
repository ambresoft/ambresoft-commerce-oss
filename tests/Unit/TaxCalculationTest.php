<?php

namespace Tests\Unit;

use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Application\Service\TaxCalculationService;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\AppliedTax;
use App\Tax\Domain\Model\CalculationSettings;
use App\Tax\Domain\Model\RateArea;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxDefinition;
use App\Tax\Domain\Model\TaxLine;
use App\Tax\Domain\Model\TaxRate;
use App\Tax\Domain\Model\TaxResult;
use App\Tax\Domain\Service\RateAreaMatcher;
use App\Tax\Domain\Service\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxCalculationTest extends TestCase
{
    /** @var array<string, RateArea> */
    private array $areas = [];

    /** @var array<string, TaxDefinition> */
    private array $taxes = [];

    /** @var array<string, list<string>> */
    private array $assignments = [];

    private CalculationSettings $settings;

    private int $scale = 2;

    protected function setUp(): void
    {
        $this->settings = new CalculationSettings;
        $this->area('1', 'Greece', ['GR']);
        $this->area('2', 'Everywhere else', [], default: true);
    }

    /** @param list<string> $countries @param array<string, list<string>> $regions */
    private function area(string $id, string $name, array $countries, bool $default = false, bool $active = true, array $regions = []): void
    {
        $this->areas[$id] = new RateArea($id, $name, $active, $default, $countries, $regions);
    }

    /** @param list<TaxRate> $rates */
    private function tax(string $id, int $priority, bool $included, array $rates, bool $active = true, string $basis = 'shipping'): void
    {
        $this->taxes[$id] = new TaxDefinition($id, 'Tax '.$id, $priority, $active, $included, $basis, $rates);
    }

    private function service(): TaxCalculationService
    {
        $test = $this;
        $configuration = new class($test) implements TaxConfiguration
        {
            public function __construct(private TaxCalculationTest $test) {}

            public function definitions(array $taxIds): array
            {
                return array_intersect_key($this->test->taxes(), array_flip($taxIds));
            }

            public function areas(): array
            {
                return $this->test->areaList();
            }

            public function assignments(array $productIds): array
            {
                $result = [];
                foreach ($productIds as $id) {
                    $result[$id] = $this->test->assigned($id);
                }

                return $result;
            }

            public function settings(): CalculationSettings
            {
                return $this->test->settingsValue();
            }

            public function scale(): int
            {
                return $this->test->scaleValue();
            }
        };

        return new TaxCalculationService($configuration, new RateAreaMatcher, new TaxCalculator);
    }

    /** @return array<string, TaxDefinition> */
    public function taxes(): array
    {
        return $this->taxes;
    }

    /** @return array<string, RateArea> */
    public function areaList(): array
    {
        return $this->areas;
    }

    /** @return list<string> */
    public function assigned(string $product): array
    {
        return $this->assignments[$product] ?? [];
    }

    public function settingsValue(): CalculationSettings
    {
        return $this->settings;
    }

    public function scaleValue(): int
    {
        return $this->scale;
    }

    private function greece(): TaxContext
    {
        return new TaxContext(new Address('GR'), new Address('GR'));
    }

    /** @param list<string> $ids */
    private function apply(array $ids, string $price, ?TaxContext $context = null): TaxResult
    {
        return $this->service()->forTaxes($ids, $price, $context ?? $this->greece());
    }

    public function test_percentage_and_fixed_taxes_are_added_to_the_price(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.24')]);
        $result = $this->apply(['1'], '100.00');
        self::assertSame(['100.00', '100.00', '0.00', '24.00', '24.00', '124.00'], [$result->base, $result->net, $result->includedTax, $result->addedTax, $result->totalTax, $result->final]);
        self::assertSame('Tax 1', $result->taxes[0]->name);
        self::assertSame('1', $result->taxes[0]->areaId);
        self::assertSame('Greece', $result->taxes[0]->areaName);
        self::assertSame(['percent', '0.24'], [$result->taxes[0]->rateType, $result->taxes[0]->rateValue]);

        $this->tax('2', 0, false, [TaxRate::fixed('1', '5.00')]);
        $fixed = $this->apply(['2'], '80.00');
        self::assertSame(['5.00', '85.00'], [$fixed->totalTax, $fixed->final]);
    }

    public function test_included_taxes_are_extracted_without_changing_the_gross_amount(): void
    {
        $this->tax('1', 0, true, [TaxRate::percent('1', '0.24')]);
        $result = $this->apply(['1'], '124.00');
        self::assertSame(['100.00', '24.00', '0.00', '24.00', '124.00'], [$result->net, $result->includedTax, $result->addedTax, $result->totalTax, $result->final]);
        // Rounding reconciles: the parts always add up to the configured price.
        $odd = $this->apply(['1'], '10.00');
        self::assertSame(['8.06', '1.94', '10.00'], [$odd->net, $odd->includedTax, $odd->final]);
        self::assertSame('10.00', bcadd($odd->net, $odd->includedTax, 2));
        // A fixed included amount is subtracted before percentages.
        $this->tax('2', 0, true, [TaxRate::fixed('1', '2.00')]);
        $fixed = $this->apply(['2'], '12.00');
        self::assertSame(['10.00', '2.00', '12.00'], [$fixed->net, $fixed->includedTax, $fixed->final]);
    }

    public function test_taxes_of_the_same_priority_share_a_base_and_different_priorities_compound(): void
    {
        $this->tax('1', 1, false, [TaxRate::percent('1', '0.10')]);
        $this->tax('2', 1, false, [TaxRate::percent('1', '0.05')]);
        $same = $this->apply(['2', '1'], '100.00');
        self::assertSame(['15.00', '115.00'], [$same->totalTax, $same->final]);
        // Output order is deterministic: priority, then tax ID, whatever the input order.
        self::assertSame(['1', '2'], array_map(fn (AppliedTax $t): string => $t->taxId, $same->taxes));
        self::assertSame([1 => '15.00'], $same->priorities);

        $this->tax('3', 2, false, [TaxRate::percent('1', '0.05')]);
        $compound = $this->apply(['1', '3'], '100.00');
        // 10% on 100, then 5% on 110.
        self::assertSame(['10.00', '5.50', '15.50', '115.50'], [$compound->taxes[0]->amount, $compound->taxes[1]->amount, $compound->totalTax, $compound->final]);
        self::assertSame([1 => '10.00', 2 => '5.50'], $compound->priorities);
    }

    public function test_included_and_added_taxes_combine_by_explicit_rules(): void
    {
        // Included 20% (priority 1) then added 10% (priority 2): the added tax compounds on net plus the lower tax.
        $this->tax('1', 1, true, [TaxRate::percent('1', '0.20')]);
        $this->tax('2', 2, false, [TaxRate::percent('1', '0.10')]);
        $a = $this->apply(['1', '2'], '120.00');
        self::assertSame(['100.00', '20.00', '12.00', '32.00', '132.00'], [$a->net, $a->includedTax, $a->addedTax, $a->totalTax, $a->final]);

        // Added 10% (priority 1) and included 20% (priority 2): the included tax never changes because of an added tax.
        $this->taxes = [];
        $this->tax('1', 1, false, [TaxRate::percent('1', '0.10')]);
        $this->tax('2', 2, true, [TaxRate::percent('1', '0.20')]);
        $b = $this->apply(['1', '2'], '120.00');
        self::assertSame(['100.00', '20.00', '10.00', '30.00', '130.00'], [$b->net, $b->includedTax, $b->addedTax, $b->totalTax, $b->final]);
    }

    public function test_an_explicit_zero_rate_never_falls_back_but_a_missing_rate_uses_only_a_default_area(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0'), TaxRate::percent('2', '0.24')]);
        $zero = $this->apply(['1'], '50.00');
        self::assertSame(['0.00', '50.00'], [$zero->totalTax, $zero->final]);
        self::assertSame(AppliedTax::APPLIED, $zero->taxes[0]->status);
        self::assertSame('Greece', $zero->taxes[0]->areaName);

        $elsewhere = $this->apply(['1'], '100.00', new TaxContext(new Address('FR'), new Address('FR')));
        self::assertSame(['24.00', 'Everywhere else'], [$elsewhere->totalTax, $elsewhere->taxes[0]->areaName]);

        // Without a default rate the configuration gap is reported, never treated as zero.
        $this->tax('2', 0, false, [TaxRate::percent('1', '0.24')]);
        $missing = $this->apply(['2'], '100.00', new TaxContext(new Address('FR'), new Address('FR')));
        self::assertFalse($missing->complete());
        self::assertSame([AppliedTax::NO_RATE], array_map(fn (AppliedTax $t): ?string => $t->reason, $missing->unresolved()));
        self::assertSame(['0.00', '100.00'], [$missing->totalTax, $missing->final]);
    }

    public function test_the_most_specific_active_area_wins_and_ties_resolve_by_lowest_id(): void
    {
        $this->area('10', 'United States', ['US']);
        $this->area('11', 'West coast', ['US'], regions: ['US' => ['CA', 'OR']]);
        $this->area('12', 'Also US', ['US']);
        $this->tax('1', 0, false, [TaxRate::percent('10', '0.05'), TaxRate::percent('11', '0.08'), TaxRate::percent('12', '0.06')]);
        $in = fn (string $country, ?string $region): array => (function () use ($country, $region): array {
            $r = $this->apply(['1'], '100.00', new TaxContext(new Address($country, $region)));

            return [$r->taxes[0]->areaName, $r->totalTax];
        })();
        self::assertSame(['West coast', '8.00'], $in('US', 'CA'));
        // No region, or an unlisted one, uses the whole-country areas; equal specificity picks the lowest ID.
        self::assertSame(['United States', '5.00'], $in('US', 'TX'));
        self::assertSame(['United States', '5.00'], $in('us', null));
        // A disabled area is ignored entirely.
        $this->area('11', 'West coast', ['US'], active: false, regions: ['US' => ['CA']]);
        self::assertSame(['United States', '5.00'], $in('US', 'CA'));
    }

    public function test_the_billing_or_shipping_address_is_used_per_tax(): void
    {
        $this->area('3', 'Italy', ['IT']);
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.24'), TaxRate::percent('3', '0.22')], basis: 'billing');
        $this->tax('2', 0, false, [TaxRate::percent('1', '0.24'), TaxRate::percent('3', '0.22')], basis: 'shipping');
        $context = new TaxContext(new Address('IT'), new Address('GR'));
        $result = $this->apply(['1', '2'], '100.00', $context);
        self::assertSame(['24.00', '22.00'], array_map(fn (AppliedTax $t): string => $t->amount, $result->taxes));
    }

    public function test_missing_addresses_use_the_estimate_setting_or_only_the_default_area(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.24'), TaxRate::percent('2', '0.10')]);
        $none = new TaxContext;
        // No address and no estimation: only the default area can apply.
        self::assertSame('10.00', $this->apply(['1'], '100.00', $none)->totalTax);
        $this->tax('2', 0, false, [TaxRate::percent('1', '0.24')]);
        $unresolved = $this->apply(['2'], '100.00', $none);
        self::assertSame([AppliedTax::ADDRESS_REQUIRED], array_map(fn (AppliedTax $t): ?string => $t->reason, $unresolved->unresolved()));
        self::assertSame('100.00', $unresolved->final);
        // With estimation enabled, the configured default address selects the rate.
        $this->settings = new CalculationSettings(estimateWithoutAddress: true, defaultAddress: new Address('GR'));
        self::assertSame('24.00', $this->apply(['2'], '100.00', $none)->totalTax);
        self::assertSame('24.00', $this->apply(['1'], '100.00', $none)->totalTax);
    }

    public function test_disabled_unknown_and_duplicate_taxes_never_apply_twice(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.10')]);
        $this->tax('2', 0, false, [TaxRate::percent('1', '0.50')], active: false);
        $result = $this->apply(['1', '2', '1', '99'], '100.00');
        self::assertCount(1, $result->taxes);
        self::assertSame(['10.00', '110.00'], [$result->totalTax, $result->final]);
        self::assertSame('100.00', $this->apply([], '100.00')->final);
    }

    public function test_exempt_customers_pay_no_added_tax_and_the_included_component_is_removed(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.24')]);
        $exclusive = $this->apply(['1'], '100.00', new TaxContext(new Address('GR'), exempt: true));
        self::assertSame(['100.00', '0.00', '100.00', true], [$exclusive->net, $exclusive->totalTax, $exclusive->final, $exclusive->exempt]);
        self::assertSame(AppliedTax::EXEMPT, $exclusive->taxes[0]->status);

        $this->taxes = [];
        $this->tax('1', 0, true, [TaxRate::percent('1', '0.24')]);
        $inclusive = $this->apply(['1'], '124.00', new TaxContext(new Address('GR'), exempt: true));
        // The tax-inclusive price is reduced to the net amount: an exempt customer does not pay the included tax.
        self::assertSame(['100.00', '0.00', '100.00'], [$inclusive->net, $inclusive->totalTax, $inclusive->final]);
    }

    public function test_amounts_follow_the_currency_precision(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.08')]);
        $this->scale = 0;
        $yen = $this->apply(['1'], '999');
        self::assertSame(['80', '1079'], [$yen->totalTax, $yen->final]);
        $this->scale = 3;
        $dinar = $this->apply(['1'], '1.005');
        self::assertSame(['0.080', '1.085'], [$dinar->totalTax, $dinar->final]);
        // Half rounds away from zero: 0.5% of 1.00 = 0.005.
        $this->scale = 2;
        $this->taxes = [];
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.005')]);
        self::assertSame('0.01', $this->apply(['1'], '1.00')->totalTax);
        // Stored six-decimal prices are accepted and the result is presented at the currency precision.
        self::assertSame(['0.06', '12.40'], [$this->apply(['1'], '12.340000')->totalTax, $this->apply(['1'], '12.340000')->final]);
    }

    public function test_the_calculation_method_decides_where_rounding_happens(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.10')]);
        $this->assignments = ['10' => ['1'], '11' => ['1']];
        $lines = [new TaxLine('10', '0.05', 10), new TaxLine('11', '0.05', 10)];
        $context = $this->greece();

        $unit = $this->service()->forLines($lines, new TaxContext(new Address('GR'), method: CalculationSettings::UNIT_PRICE));
        // 10% of 0.05 is 0.005 -> 0.01 per unit, times ten units, per line.
        self::assertSame(['1.00', '0.20', '1.20'], [$unit->subtotal, $unit->totalTax, $unit->total]);
        self::assertSame([0 => 0, 1 => 1], $unit->lines);

        $subtotal = $this->service()->forLines($lines, new TaxContext(new Address('GR'), method: CalculationSettings::SUBTOTAL));
        // One combined base of 1.00 taxed once.
        self::assertSame(['1.00', '0.10', '1.10'], [$subtotal->subtotal, $subtotal->totalTax, $subtotal->total]);
        self::assertSame([0 => 0, 1 => 0], $subtotal->lines);
        self::assertCount(1, $subtotal->groups);

        // The configured method applies when the context does not choose one.
        $this->settings = new CalculationSettings(CalculationSettings::SUBTOTAL);
        self::assertSame('0.10', $this->service()->forLines($lines, $context)->totalTax);
    }

    public function test_lines_with_different_tax_sets_are_taxed_separately_and_aggregated(): void
    {
        $this->tax('1', 0, false, [TaxRate::percent('1', '0.10')]);
        $this->tax('2', 0, false, [TaxRate::percent('1', '0.20')]);
        $this->assignments = ['a' => ['1'], 'b' => ['1', '2'], 'c' => []];
        $result = $this->service()->forLines([new TaxLine('a', '100.00'), new TaxLine('b', '50.00', 2), new TaxLine('c', '7.00')], new TaxContext(new Address('GR'), method: CalculationSettings::SUBTOTAL));
        self::assertSame(['207.00', '40.00', '247.00'], [$result->subtotal, $result->totalTax, $result->total]);
        self::assertSame(['20.00', '20.00'], array_map(fn (AppliedTax $t): string => $t->amount, $result->taxes));
        self::assertCount(3, $result->groups);
    }

    public function test_display_amounts_follow_the_with_or_without_tax_choice(): void
    {
        $this->tax('1', 0, true, [TaxRate::percent('1', '0.24')]);
        $this->tax('2', 1, false, [TaxRate::percent('1', '0.10')]);
        $result = $this->apply(['1', '2'], '124.00');
        self::assertSame(['136.40', '100.00'], [$result->display(true), $result->display(false)]);
    }
}
