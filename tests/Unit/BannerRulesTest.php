<?php

namespace Tests\Unit;

use App\Marketing\Domain\Service\BannerRules;
use App\Marketing\Infrastructure\BannerTitleSanitizer;
use PHPUnit\Framework\TestCase;

final class BannerRulesTest extends TestCase
{
    public function test_paddings_colours_classes_and_youtube_ids_are_normalised_or_refused(): void
    {
        self::assertSame('10px 20px 20px 20px', BannerRules::paddings(' 10px  20px 20PX 20 '));
        self::assertSame('5% 0px', BannerRules::paddings('5% 0'));
        self::assertNull(BannerRules::paddings('10px 20px 20px 20px 1px'));
        self::assertNull(BannerRules::paddings('10em'));
        self::assertNull(BannerRules::paddings('-5px'));
        self::assertSame('#aabbcc', BannerRules::color('#ABC'));
        self::assertSame('#1a2b3c', BannerRules::color('#1A2B3C'));
        self::assertNull(BannerRules::color('red'));
        self::assertSame('banner-button-bottom my_class', BannerRules::classes(' banner-button-bottom  my_class banner-button-bottom '));
        self::assertNull(BannerRules::classes('ok "><script>'));
        self::assertNull(BannerRules::classes('9starts-with-digit'));
        foreach (['dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1', 'https://youtube.com/shorts/dQw4w9WgXcQ'] as $value) {
            self::assertSame('dQw4w9WgXcQ', BannerRules::youtube($value), $value);
        }
        self::assertNull(BannerRules::youtube('https://evil.example/watch?v=dQw4w9WgXcQ'));
        self::assertNull(BannerRules::youtube('short'));
    }

    public function test_title_sanitizer_keeps_only_the_preset_markup(): void
    {
        $sanitizer = new BannerTitleSanitizer;
        self::assertSame('Sale <i>today</i><br><mark>-50%</mark>', $sanitizer->sanitize('Sale <i>today</i><br/><mark>-50%</mark>'));
        self::assertSame('<span class="banner-title-above">New</span> <span style="color: #e11d48">red</span>', $sanitizer->sanitize('<span class="banner-title-above">New</span> <span style="color: #E11D48">red</span>'));
        self::assertSame('<span>x</span>', $sanitizer->sanitize('<span class="evil" style="position:fixed;inset:0" onclick="alert(1)">x</span>'));
        self::assertSame('Hi', $sanitizer->sanitize('Hi<script>alert(1)</script>'));
        self::assertSame('link &lt;3', $sanitizer->sanitize('<a href="javascript:alert(1)">link</a> &lt;3'));
        self::assertSame('<b>Ελληνικά</b>', $sanitizer->sanitize('<b>Ελληνικά</b>'));
        self::assertSame('<span style="color: rgba(0,0,0,.5)">x</span>', $sanitizer->sanitize('<span style="color: rgba(0, 0, 0, .5)">x</span>'));
    }
}
