<?php

namespace App\Search\Application\Command;

/**
 * A provider's own view of its index for the administration. `ready` only when it answered and the live index exists;
 * `current` when that index has the schema this version of the provider needs (otherwise a rebuild is due).
 */
final readonly class SearchHealth
{
    public function __construct(
        public bool $reachable,
        public bool $ready,
        public bool $current,
        public ?int $documents = null,
        public ?string $index = null,
        public ?string $version = null,
        public ?string $message = null,
    ) {}
}
