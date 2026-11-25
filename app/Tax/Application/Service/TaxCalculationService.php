<?php

namespace App\Tax\Application\Service;

use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\AppliedTax;
use App\Tax\Domain\Model\CalculationSettings;
use App\Tax\Domain\Model\RateArea;
use App\Tax\Domain\Model\ResolvedTax;
use App\Tax\Domain\Model\TaxContext;
use App\Tax\Domain\Model\TaxDefinition;
use App\Tax\Domain\Model\TaxLine;
use App\Tax\Domain\Model\TaxLinesResult;
use App\Tax\Domain\Model\TaxResult;
use App\Tax\Domain\Service\RateAreaMatcher;
use App\Tax\Domain\Service\TaxCalculator;
use InvalidArgumentException;

/**
 * The single entry point for tax calculation. Amounts are primary-currency decimal strings; nothing is persisted.
 *
 * Only active assigned taxes apply, each once. A tax whose address basis (shipping or billing) has no address
 * uses the configured estimation address when enabled; otherwise only a default-area rate can apply and a tax
 * without one is reported as unresolved and left out of the totals.
 */
final readonly class TaxCalculationService
{
    public function __construct(private TaxConfiguration $configuration, private RateAreaMatcher $matcher, private TaxCalculator $calculator) {}

    /** Taxes assigned to the product, applied to a unit price. */
    public function forProduct(string $productId, string $unitPrice, TaxContext $context = new TaxContext): TaxResult
    {
        return $this->forTaxes($this->configuration->assignments([$productId])[$productId] ?? [], $unitPrice, $context);
    }

    /**
     * @param  list<string>  $taxIds  Duplicates are applied once; unknown and disabled taxes are ignored.
     */
    public function forTaxes(array $taxIds, string $amount, TaxContext $context = new TaxContext): TaxResult
    {
        return $this->compute($this->configuration->definitions($this->unique($taxIds)), $this->configuration->areas(), $amount, $context, $this->configuration->settings(), $this->configuration->scale());
    }

    /**
     * @param  list<TaxLine>  $lines
     *
     * Unit-price method: each unit is taxed and rounded, then multiplied by the quantity. Subtotal method: lines
     * sharing the same set of taxes are summed and taxed once. Discounts must already be reflected in unit prices.
     */
    public function forLines(array $lines, TaxContext $context = new TaxContext): TaxLinesResult
    {
        $settings = $this->configuration->settings();
        $method = $context->method ?? $settings->method;
        if (! in_array($method, [CalculationSettings::UNIT_PRICE, CalculationSettings::SUBTOTAL], true)) {
            throw new InvalidArgumentException('Unknown tax calculation method.');
        }
        $scale = $this->configuration->scale();
        $areas = $this->configuration->areas();
        $assignments = $this->configuration->assignments(array_values(array_unique(array_map(fn (TaxLine $line): string => $line->productId, $lines))));
        $definitions = $this->configuration->definitions($this->unique(array_merge([], ...array_values($assignments))));
        $groups = [];
        $mapping = [];
        $sets = [];
        foreach ($lines as $index => $line) {
            if ($line->quantity < 1) {
                throw new InvalidArgumentException('Line quantity must be a positive integer.');
            }
            $ids = $this->unique($assignments[$line->productId] ?? []);
            $set = array_values(array_filter($ids, fn (string $id): bool => isset($definitions[$id])));
            if ($method === CalculationSettings::UNIT_PRICE) {
                $mapping[$index] = count($groups);
                $groups[] = $this->compute($this->subset($definitions, $set), $areas, $line->unitPrice, $context, $settings, $scale)->times($line->quantity);

                continue;
            }
            $key = implode(',', $set);
            $sets[$key] ??= ['ids' => $set, 'amount' => '0', 'lines' => []];
            $sets[$key]['amount'] = Decimal::add($sets[$key]['amount'], Decimal::multiply($line->unitPrice, (string) $line->quantity));
            $sets[$key]['lines'][] = $index;
        }
        foreach ($sets as $set) {
            foreach ($set['lines'] as $index) {
                $mapping[$index] = count($groups);
            }
            $groups[] = $this->compute($this->subset($definitions, $set['ids']), $areas, $set['amount'], $context, $settings, $scale);
        }
        ksort($mapping);

        return $this->aggregate($method, $groups, $mapping, $scale);
    }

    /**
     * @param  array<string, TaxDefinition>  $definitions
     * @param  array<string, RateArea>  $areas
     */
    private function compute(array $definitions, array $areas, string $amount, TaxContext $context, CalculationSettings $settings, int $scale): TaxResult
    {
        $resolved = [];
        $unresolved = [];
        foreach ($definitions as $tax) {
            if (! $tax->active) {
                continue;
            }
            $address = ($tax->addressBasis === TaxDefinition::BILLING ? $context->billing : $context->shipping);
            if (($address === null || ! $address->known()) && $settings->estimateWithoutAddress) {
                $address = $settings->defaultAddress;
            }
            $match = $this->matcher->select($tax, $areas, $address instanceof Address && $address->known() ? $address : null);
            if ($match instanceof ResolvedTax) {
                $resolved[] = $match;
            } else {
                $unresolved[] = new AppliedTax($tax->id, $tax->name, $tax->priority, $tax->priceIncludesTax, AppliedTax::UNRESOLVED, Decimal::round('0', $scale), reason: $match);
            }
        }

        return $this->calculator->calculate($amount, $resolved, $unresolved, $context->exempt, $scale);
    }

    /**
     * @param  list<TaxResult>  $groups
     * @param  array<int, int>  $mapping
     */
    private function aggregate(string $method, array $groups, array $mapping, int $scale): TaxLinesResult
    {
        $totals = ['base' => '0', 'included' => '0', 'added' => '0', 'tax' => '0', 'final' => '0'];
        /** @var array<string, AppliedTax> $taxes */
        $taxes = [];
        foreach ($groups as $group) {
            $totals['base'] = Decimal::add($totals['base'], $group->base);
            $totals['included'] = Decimal::add($totals['included'], $group->includedTax);
            $totals['added'] = Decimal::add($totals['added'], $group->addedTax);
            $totals['tax'] = Decimal::add($totals['tax'], $group->totalTax);
            $totals['final'] = Decimal::add($totals['final'], $group->final);
            foreach ($group->taxes as $tax) {
                $taxes[$tax->taxId] = isset($taxes[$tax->taxId]) ? $taxes[$tax->taxId]->withAmount(Decimal::add($taxes[$tax->taxId]->amount, $tax->amount)) : $tax;
            }
        }
        $taxes = array_values($taxes);
        usort($taxes, fn (AppliedTax $a, AppliedTax $b): int => [$a->priority, (int) $a->taxId] <=> [$b->priority, (int) $b->taxId]);
        $fixed = fn (string $value): string => Decimal::round($value, $scale);

        return new TaxLinesResult($method, $groups, $mapping, $taxes, $fixed($totals['base']), $fixed($totals['included']), $fixed($totals['added']), $fixed($totals['tax']), $fixed($totals['final']));
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function unique(array $ids): array
    {
        return array_values(array_unique(array_map(strval(...), $ids)));
    }

    /**
     * @param  array<string, TaxDefinition>  $definitions
     * @param  list<string>  $ids
     * @return array<string, TaxDefinition>
     */
    private function subset(array $definitions, array $ids): array
    {
        return array_intersect_key($definitions, array_flip($ids));
    }
}
