<?php

namespace App\Reporting\Domain\Exception;

use InvalidArgumentException;

/** A requested reporting period that cannot be used; `reason` is a stable code the presentation layer translates. */
final class InvalidReportPeriod extends InvalidArgumentException
{
    public const PRESET = 'preset';

    public const DATE = 'date';

    public const ORDER = 'order';

    public const TOO_LONG = 'too_long';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid report period: '.$reason);
    }
}
