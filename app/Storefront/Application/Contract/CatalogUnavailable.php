<?php

namespace App\Storefront\Application\Contract;

use RuntimeException;

/**
 * The catalog cannot answer: the store is closed (`closed`) or text search has no working engine (`search`). Never an
 * empty result in disguise, so a caller does not report that a product does not exist.
 */
final class CatalogUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The storefront catalog is unavailable: '.$reason);
    }
}
