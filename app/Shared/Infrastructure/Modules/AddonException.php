<?php

namespace App\Shared\Infrastructure\Modules;

use InvalidArgumentException;

/**
 * A refused manifest, package or lifecycle step. Carries a translation key (in the `addons` group) and its parameters,
 * so administrators read a localized reason and logs/CLI can render the same message.
 */
final class AddonException extends InvalidArgumentException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key.($params === [] ? '' : ' '.json_encode($params, JSON_UNESCAPED_SLASHES)));
    }

    public function translated(): string
    {
        return __($this->key, $this->params);
    }
}
