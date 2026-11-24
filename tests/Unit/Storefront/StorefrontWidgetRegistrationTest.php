<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Application\Service\StorefrontRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorefrontWidgetRegistrationTest extends TestCase
{
    public function test_a_widget_is_an_external_document_with_a_title(): void
    {
        $registry = new StorefrontRegistry;
        $registry->widget('acme/widget', 'https://widgets.example.test/w?v=1', 'Shop assistant');

        self::assertSame(['acme/widget' => ['src' => 'https://widgets.example.test/w?v=1', 'title' => 'Shop assistant']], $registry->widgets());
    }

    /** @return array<string, array{string, string, string}> */
    public static function refused(): array
    {
        return [
            'owner is not an add-on' => ['widget', 'https://a.test/w', 'Title'],
            'plain http' => ['acme/widget', 'http://a.test/w', 'Title'],
            'relative path' => ['acme/widget', '/w', 'Title'],
            'protocol relative' => ['acme/widget', '//a.test/w', 'Title'],
            'inline javascript' => ['acme/widget', 'javascript:alert(1)', 'Title'],
            'data url' => ['acme/widget', 'data:text/html,<script>alert(1)</script>', 'Title'],
            'credentials in the address' => ['acme/widget', 'https://user:secret@a.test/w', 'Title'],
            'fragment' => ['acme/widget', 'https://a.test/w#x', 'Title'],
            'space in the address' => ['acme/widget', 'https://a.test/w onload=x', 'Title'],
            'empty title' => ['acme/widget', 'https://a.test/w', '  '],
            'control character in the title' => ['acme/widget', 'https://a.test/w', "a\nb"],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_else_is_refused(string $addon, string $src, string $title): void
    {
        $this->expectException(LogicException::class);
        (new StorefrontRegistry)->widget($addon, $src, $title);
    }

    public function test_one_widget_per_addon(): void
    {
        $registry = new StorefrontRegistry;
        $registry->widget('acme/widget', 'https://a.test/w', 'Title');

        $this->expectException(LogicException::class);
        $registry->widget('acme/widget', 'https://a.test/other', 'Title');
    }
}
