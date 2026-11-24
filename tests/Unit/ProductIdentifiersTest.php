<?php

namespace Tests\Unit;

use App\Catalog\Domain\Service\ProductIdentifiers;
use PHPUnit\Framework\TestCase;

final class ProductIdentifiersTest extends TestCase
{
    public function test_isbn_accepts_both_lengths_and_x_check_digits_but_rejects_invalid_codes(): void
    {
        foreach (['0306406152', '080442957X', '9780306406157', '9791090636071'] as $value) {
            self::assertTrue(ProductIdentifiers::isbn($value), $value);
        }
        foreach (['', '0306406153', '0804429570', '9780306406158', '4006381333931', '123', '978030640615X'] as $value) {
            self::assertFalse(ProductIdentifiers::isbn($value), $value);
        }
    }

    public function test_upc_retains_zeroes_and_checks_all_twelve_digits(): void
    {
        foreach (['012345678905', '036000291452'] as $value) {
            self::assertTrue(ProductIdentifiers::upc($value));
        }
        foreach (['12345678905', '012345678906', '0012345678905', '01234567890X', ''] as $value) {
            self::assertFalse(ProductIdentifiers::upc($value));
        }
    }
}
