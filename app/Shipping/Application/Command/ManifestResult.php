<?php

namespace App\Shipping\Application\Command;

/**
 * Outcome of issuing a manifest: its number, or the carrier's refusal with the tracking numbers that block it
 * (e.g. ACS refuses while printed-vouchers are missing).
 */
final readonly class ManifestResult
{
    /** @param list<string> $blocking */
    public function __construct(public ?string $number, public array $blocking = [], public ?string $message = null, public ?int $count = null) {}
}
