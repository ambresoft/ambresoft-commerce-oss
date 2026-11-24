<?php

namespace App\Tax\Domain\Model;

final readonly class AppliedTax
{
    public const APPLIED = 'applied';

    public const EXEMPT = 'exempt';

    public const UNRESOLVED = 'unresolved';

    public const ADDRESS_REQUIRED = 'address_required';

    public const NO_RATE = 'no_rate';

    public function __construct(
        public string $taxId,
        public string $name,
        public int $priority,
        public bool $included,
        public string $status,
        public string $amount,
        public ?string $areaId = null,
        public ?string $areaName = null,
        public ?string $rateType = null,
        public ?string $rateValue = null,
        public ?string $reason = null,
    ) {}

    public function withAmount(string $amount): self
    {
        return new self($this->taxId, $this->name, $this->priority, $this->included, $this->status, $amount, $this->areaId, $this->areaName, $this->rateType, $this->rateValue, $this->reason);
    }
}
