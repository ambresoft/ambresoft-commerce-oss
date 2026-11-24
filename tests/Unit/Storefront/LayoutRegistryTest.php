<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Domain\Service\LayoutRules;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class LayoutRegistryTest extends TestCase
{
    public function test_trusted_theme_templates_can_be_extended_without_replacing_metadata(): void
    {
        $registry = new StorefrontRegistry;
        $registry->theme('default', ['name' => 'Default', 'regions' => ['content'], 'templates' => ['html']]);
        $registry->templates('default', ['custom_grid', 'html']);
        self::assertSame(['html', 'custom_grid'], $registry->activeTheme('default')['templates']);
        self::assertSame('Default', $registry->activeTheme('missing')['name']);
        $this->expectException(LogicException::class);
        $registry->templates('default', ['../arbitrary.php']);
    }

    public function test_registered_ids_are_unique(): void
    {
        $registry = new StorefrontRegistry;
        $registry->page('homepage', ['regions' => ['content']]);
        $this->expectException(LogicException::class);
        $registry->page('homepage', ['regions' => ['header']]);
    }

    public function test_multiple_rows_and_absolute_child_widths(): void
    {
        $rules = new LayoutRules;
        $grids = [
            ['id' => 'a', 'parent_id' => null, 'width' => 16, 'row' => 1, 'position' => 1, 'region' => 'content'],
            ['id' => 'b', 'parent_id' => 'a', 'width' => 4, 'row' => 1, 'position' => 1, 'region' => 'content'],
            ['id' => 'c', 'parent_id' => 'a', 'width' => 8, 'row' => 1, 'position' => 2, 'region' => 'content'],
            ['id' => 'd', 'parent_id' => 'a', 'width' => 4, 'row' => 1, 'position' => 3, 'region' => 'content'],
            ['id' => 'e', 'parent_id' => 'a', 'width' => 16, 'row' => 2, 'position' => 1, 'region' => 'content'],
        ];
        $rules->validate($grids, ['content']);
        self::assertTrue(true);
        $grids[4]['row'] = 1;
        $this->expectException(DomainException::class);
        $rules->validate($grids, ['content']);
    }
}
