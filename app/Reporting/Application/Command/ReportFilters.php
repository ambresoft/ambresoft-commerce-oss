<?php

namespace App\Reporting\Application\Command;

use App\Reporting\Domain\Model\ReportPeriod;

/**
 * The validated filters of one report request. Every report component (cards, charts, tables, exports) receives the
 * same instance, so a figure never depends on which widget asked. Identifiers are positive integers; `currency` is one
 * ISO code because amounts of different currencies are never added together.
 */
final readonly class ReportFilters
{
    public function __construct(
        public ReportPeriod $period,
        public bool $compare,
        public string $currency,
        /** Orders report: `paid` (when the order became fully paid) or `created` (when it was placed). */
        public string $dateBasis = 'paid',
        public ?int $statusId = null,
        public ?int $paymentMethodId = null,
        public ?int $shippingMethodId = null,
        public ?int $productId = null,
        public ?int $categoryId = null,
        public ?int $customerId = null,
    ) {}

    public function forPeriod(ReportPeriod $period): self
    {
        return new self($period, $this->compare, $this->currency, $this->dateBasis, $this->statusId, $this->paymentMethodId, $this->shippingMethodId, $this->productId, $this->categoryId, $this->customerId);
    }

    /** Whether a product or category narrows the report to individual order lines. */
    public function lineScoped(): bool
    {
        return $this->productId !== null || $this->categoryId !== null;
    }

    /** @return array<string, string|int|bool> The query-string form, for links between reports and exports. */
    public function toQuery(): array
    {
        return array_filter([
            'period' => $this->period->preset,
            'from' => $this->period->preset === 'custom' ? $this->period->start->format('Y-m-d') : null,
            'to' => $this->period->preset === 'custom' ? $this->period->end->modify('-1 day')->format('Y-m-d') : null,
            'compare' => $this->compare ? 1 : null,
            'currency' => $this->currency,
            'date' => $this->dateBasis === 'created' ? 'created' : null,
            'status_id' => $this->statusId, 'payment_method_id' => $this->paymentMethodId, 'shipping_method_id' => $this->shippingMethodId,
            'product_id' => $this->productId, 'category_id' => $this->categoryId, 'customer_id' => $this->customerId,
        ], fn (mixed $v): bool => $v !== null);
    }
}
