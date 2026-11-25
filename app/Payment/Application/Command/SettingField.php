<?php

namespace App\Payment\Application\Command;

use App\Shared\Application\Command\SettingField as SharedSettingField;

/**
 * One method-specific setting (published payment contract). It is the shared setting field, kept under this name so
 * payment add-ons written for contract 2 keep working.
 */
final readonly class SettingField extends SharedSettingField {}
