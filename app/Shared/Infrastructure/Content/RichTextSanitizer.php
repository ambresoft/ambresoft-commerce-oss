<?php

namespace App\Shared\Infrastructure\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichTextSanitizer
{
    public function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['https', 'http', 'mailto'])->allowRelativeLinks(false)->withMaxInputLength(400000);
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'pre', 'code'] as $tag) {
            $config = $config->allowElement($tag, []);
        }

        return (new HtmlSanitizer($config->allowElement('a', ['href', 'title'])))->sanitize($html);
    }
}
