<?php

namespace App\Marketing\Infrastructure;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Banner titles allow a few inline tags for the title presets: <i> (thin), <br> (new row), <mark>, <b>/<strong>, <em>,
 * <small>, and <span> with only a colour ("another colour") or the "above title" class. Everything else is reduced to
 * escaped text. The browser preview mirrors this policy; the server result is authoritative.
 */
final class BannerTitleSanitizer
{
    private const TAGS = ['i', 'b', 'strong', 'em', 'mark', 'small', 'br', 'span'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('root');

        return $root === null ? htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8') : trim($this->children($root));
    }

    private function children(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->node($child);
        }

        return $out;
    }

    private function node(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (! in_array($tag, self::TAGS, true)) {
            // Unknown elements keep their text; script/style content is dropped entirely.
            return in_array($tag, ['script', 'style', 'template', 'iframe', 'object'], true) ? '' : $this->children($node);
        }
        if ($tag === 'br') {
            return '<br>';
        }
        $attributes = '';
        if ($tag === 'span') {
            if ($node->getAttribute('class') === 'banner-title-above') {
                $attributes .= ' class="banner-title-above"';
            }
            if (preg_match('/^\s*color\s*:\s*(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))\s*;?\s*$/D', $node->getAttribute('style'), $m) === 1) {
                $attributes .= ' style="color: '.strtolower(preg_replace('/\s+/', '', $m[1]) ?? '').'"';
            }
        }

        return '<'.$tag.$attributes.'>'.$this->children($node).'</'.$tag.'>';
    }
}
