<?php

namespace App\Shipping\Application\Command;

use InvalidArgumentException;

/** A carrier-produced document (label, manifest). Only PDF documents are accepted. */
final readonly class ShippingDocument
{
    public function __construct(public string $filename, public string $content, public string $mimeType = 'application/pdf')
    {
        if ($mimeType !== 'application/pdf' || ! str_starts_with($content, '%PDF') || preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $filename) !== 1) {
            throw new InvalidArgumentException('Unsupported shipping document.');
        }
    }
}
