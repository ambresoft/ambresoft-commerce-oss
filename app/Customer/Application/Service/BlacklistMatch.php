<?php

namespace App\Customer\Application\Service;

/** An active blacklist entry that matched a checked email or phone. */
final readonly class BlacklistMatch
{
    public function __construct(public string $entryId, public string $kind, public string $reason) {}
}
