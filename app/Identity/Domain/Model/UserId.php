<?php

namespace App\Identity\Domain\Model;

use InvalidArgumentException;

final readonly class UserId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('User ID must be a positive decimal identifier.');
        }
    }
}
