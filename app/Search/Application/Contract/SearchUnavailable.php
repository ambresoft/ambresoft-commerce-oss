<?php

namespace App\Search\Application\Contract;

use RuntimeException;

/**
 * The search provider could not answer: unreachable, timed out, out of capacity (`unavailable`), its index does not
 * exist yet (`not_initialized`) or it refused the request (`rejected`). Never an empty result in disguise: the CMS
 * shows search as unavailable and keeps queued index changes for a retry.
 */
final class SearchUnavailable extends RuntimeException
{
    public const REASONS = ['unavailable', 'not_initialized', 'rejected'];

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
