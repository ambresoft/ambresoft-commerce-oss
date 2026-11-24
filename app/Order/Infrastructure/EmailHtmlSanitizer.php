<?php

namespace App\Order\Infrastructure;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Email-safe HTML for order email headers, footers and bodies: layout tables, inline styles, links and https images,
 * never scripts, event handlers, forms or embedded content. Inline styles keep only declarations that cannot load
 * or run anything. `{{variables}}` are plain text and survive; the shop logo is inserted at render time.
 */
final class EmailHtmlSanitizer
{
    private const COMMON = ['style', 'align', 'valign', 'width', 'height', 'bgcolor', 'class', 'dir', 'lang', 'title'];

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        // HTML parsers move text out of tables; protect row placeholders with valid table markup until sanitization finishes.
        $html = (string) preg_replace_callback('/\{\{\s*invoice_(items|totals)\s*\}\}/', fn (array $m): string => '<tr><td class="cms-invoice-'.$m[1].'"></td></tr>', $html);
        $html = ($this->sanitizer ??= $this->build())->sanitize($html);

        return (string) preg_replace_callback('~<tr><td class="cms-invoice-(items|totals)"></td></tr>~', fn (array $m): string => '{{invoice_'.$m[1].'}}', $html);
    }

    private function build(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])->allowRelativeLinks(false)
            ->allowMediaSchemes(['https'])->allowRelativeMedias(false)
            ->withMaxInputLength(200000)
            ->withAttributeSanitizer(new class implements AttributeSanitizerInterface
            {
                /** @return list<string>|null */
                public function getSupportedElements(): ?array
                {
                    return null;
                }

                /** @return list<string> */
                public function getSupportedAttributes(): array
                {
                    return ['style'];
                }

                public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
                {
                    $kept = [];
                    foreach (explode(';', $value) as $declaration) {
                        $declaration = trim($declaration);
                        // Anything that can fetch, execute or escape the declaration is dropped.
                        if ($declaration === '' || preg_match('/url\s*\(|expression|javascript:|@import|behavior|-moz-binding|\\\\|[<>]/i', $declaration) === 1
                            || preg_match('/^[a-z-]+\s*:/i', $declaration) !== 1) {
                            continue;
                        }
                        $kept[] = $declaration;
                    }

                    return $kept === [] ? null : implode('; ', $kept);
                }
            });
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sup', 'sub', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'hr', 'div', 'span', 'center', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'pre', 'code'] as $tag) {
            $config = $config->allowElement($tag, [...self::COMMON, 'cellpadding', 'cellspacing', 'border', 'role']);
        }
        $config = $config->allowElement('td', [...self::COMMON, 'colspan', 'rowspan'])->allowElement('th', [...self::COMMON, 'colspan', 'rowspan', 'scope']);
        $config = $config->allowElement('a', [...self::COMMON, 'href', 'target', 'rel'])->allowElement('img', [...self::COMMON, 'src', 'alt', 'border']);

        return new HtmlSanitizer($config);
    }
}
