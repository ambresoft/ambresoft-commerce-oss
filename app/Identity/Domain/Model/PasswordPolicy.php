<?php

namespace App\Identity\Domain\Model;

/**
 * Passphrase lengths (A26 for staff, A99 for customers). One identity table serves both, so whether the current
 * password meets the staff minimum is recorded with it and administration requires that it does.
 */
final class PasswordPolicy
{
    public const STAFF_MIN = 15;

    public const CUSTOMER_MIN = 8;

    public const MAX = 128;

    public static function meetsStaffMinimum(string $password): bool
    {
        return mb_strlen($password) >= self::STAFF_MIN;
    }
}
