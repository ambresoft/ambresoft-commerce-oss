<?php

namespace App\Shared\Application\Command;

use InvalidArgumentException;

/**
 * One typed setting declared by an extension (payment method, carrier provider, carrier account or shipment form).
 * Labels, hints and option labels are translation keys (namespaced for add-ons). `secret` values are encrypted,
 * write-only and never sent to the browser; `credential` fields are what a provider verifies, so changing one requires a
 * new verification. `order_status` and `payment_method` fields store the stable ID / code of a core record.
 */
readonly class SettingField
{
    public const TYPES = ['text', 'textarea', 'secret', 'select', 'boolean', 'date', 'integer', 'decimal', 'order_status', 'payment_method'];

    /** @param array<string, string> $options Value => label translation key (select fields) */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public string $hint = '',
        public bool $required = false,
        public array $options = [],
        public string $default = '',
        public bool $credential = false,
        public int $maxLength = 255,
        public ?string $min = null,
        public ?string $max = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1 || ! in_array($type, self::TYPES, true) || $maxLength < 1 || $maxLength > 2000
            || ($type === 'select' && ($options === [] || ! array_key_exists($default, $options)))) {
            throw new InvalidArgumentException('Invalid setting definition: '.$key);
        }
    }
}
