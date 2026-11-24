<?php

namespace Tests\Unit;

use App\Shared\Infrastructure\Content\RichTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unsafeHtml(): array
    {
        return [
            'scripts and handlers' => ['<p onclick="alert(1)">Text<script>alert(2)</script><img src=x onerror=alert(3)></p>'],
            'encoded protocol' => ['<a href="jav&#x61;script:alert(1)">Link</a>'],
            'data protocol' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">Link</a>'],
            'relative URL' => ['<a href="//example.com">Link</a>'],
            'SVG and MathML' => ['<svg onload="alert(1)"><foreignObject><p>SVG</p></foreignObject></svg><math><mi xlink:href="javascript:alert(1)">Math</mi></math>'],
            'embeds and forms' => ['<iframe src="https://example.com"></iframe><object data="https://example.com"></object><form id="app"><input name="attributes"></form>'],
            'CSS and clobbering' => ['<p style="background:url(https://example.com)" id="app" name="attributes">Text</p>'],
        ];
    }

    #[DataProvider('unsafeHtml')]
    public function test_untrusted_html_cannot_bypass_the_server_allowlist(string $dirty): void
    {
        $clean = (new RichTextSanitizer)->sanitize($dirty);
        self::assertDoesNotMatchRegularExpression('/<script|<img|<svg|<math|<iframe|<object|<form|<input|on[a-z]+=|javascript:|data:|style=|id=|name=|href="\/\//i', $clean);
    }

    public function test_safe_editor_markup_and_unicode_survive_sanitization(): void
    {
        $html = '<h2>Περιγραφή</h2><p><strong>Bold</strong> <em>Italic</em> <u>Underline</u> <s>Strike</s><br><a href="https://example.com" title="Example">Link</a><a href="mailto:hello@example.com">Mail</a></p><ol><li>First</li></ol><blockquote>Quote</blockquote><pre><code>&lt;script&gt;</code></pre>';
        $clean = (new RichTextSanitizer)->sanitize($html);
        foreach (['<h2>Περιγραφή</h2>', '<strong>Bold</strong>', '<u>Underline</u>', '<li>First</li>', '<blockquote>Quote</blockquote>', 'href="https://example.com"', '&lt;script&gt;'] as $expected) {
            self::assertStringContainsString($expected, $clean);
        }
        self::assertStringContainsString('href="mailto:hello@example.com"', html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
