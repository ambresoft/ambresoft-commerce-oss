<?php

namespace App\Website\Infrastructure;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Page content policy: richer than product descriptions (headings, tables, alignment, colours, images by URL,
 * YouTube embeds) but never scripts, event handlers, forms, arbitrary embeds or arbitrary CSS. Mirrored in the
 * browser editor (sanitizePageContent); this server result is authoritative.
 */
final class PageContentSanitizer
{
    private const STYLED = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'td', 'th', 'li', 'div', 'blockquote'];

    /** CSS property => value pattern. Anything else is dropped. */
    private const STYLES = [
        'text-align' => '/^(left|right|center|justify)$/',
        'color' => '/^(#[0-9a-f]{3}|#[0-9a-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i',
        'background-color' => '/^(#[0-9a-f]{3}|#[0-9a-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/i',
        'min-width' => '/^\d{1,4}px$/',
        'width' => '/^\d{1,4}(px|%)$/',
    ];

    private const YOUTUBE = '~^https://www\.youtube(?:-nocookie)?\.com/embed/[A-Za-z0-9_-]{11}(?:\?[A-Za-z0-9_=&;%-]*)?$~D';

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])->allowRelativeLinks()
            ->allowMediaSchemes(['https', 'http'])->allowRelativeMedias()
            ->withMaxInputLength(500000);
        foreach (['br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small', 'code', 'pre', 'ul', 'ol', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'caption', 'tr', 'colgroup'] as $tag) {
            $config = $config->allowElement($tag, []);
        }
        foreach (self::STYLED as $tag) {
            $config = $config->allowElement($tag, match ($tag) {
                'td', 'th' => ['style', 'colspan', 'rowspan', 'colwidth'],
                // The editor's YouTube wrapper, needed to recognise the embed when the page is edited again.
                'div' => ['style', 'data-youtube-video'],
                default => ['style'],
            });
        }
        $config = $config->allowElement('col', ['style', 'span'])
            ->allowElement('a', ['href', 'title', 'target'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('iframe', ['src', 'width', 'height', 'title', 'allowfullscreen']);
        $clean = (new HtmlSanitizer($config))->sanitize($html);

        return $this->refine($clean);
    }

    /** Second pass: CSS allowlist, YouTube-only iframes, safe link targets, numeric dimensions. */
    private function refine(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="page-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('page-root');
        if ($root === null) {
            return '';
        }
        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('.//*', $root) ?: []) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->hasAttribute('style')) {
                $kept = [];
                foreach (explode(';', $node->getAttribute('style')) as $declaration) {
                    [$property, $value] = array_map('trim', explode(':', $declaration, 2) + [1 => '']);
                    $property = strtolower($property);
                    if (isset(self::STYLES[$property]) && preg_match(self::STYLES[$property], $value) === 1) {
                        $kept[$property] = $property.': '.strtolower($value);
                    }
                }
                $kept === [] ? $node->removeAttribute('style') : $node->setAttribute('style', implode('; ', $kept));
            }
            foreach (['width', 'height', 'colspan', 'rowspan', 'span'] as $attribute) {
                if ($node->hasAttribute($attribute) && preg_match('/^\d{1,4}$/', $node->getAttribute($attribute)) !== 1) {
                    $node->removeAttribute($attribute);
                }
            }
            if ($node->hasAttribute('data-youtube-video')) {
                $node->setAttribute('data-youtube-video', '');
            }
            if ($node->hasAttribute('colwidth') && preg_match('/^\d{1,4}(,\d{1,4})*$/', $node->getAttribute('colwidth')) !== 1) {
                $node->removeAttribute('colwidth');
            }
            $tag = strtolower($node->tagName);
            if ($tag === 'img' && trim($node->getAttribute('src')) === '') {
                $node->parentNode?->removeChild($node);

                continue;
            }
            if ($tag === 'iframe') {
                if (preg_match(self::YOUTUBE, $node->getAttribute('src')) !== 1) {
                    $node->parentNode?->removeChild($node);

                    continue;
                }
                $node->setAttribute('allowfullscreen', 'true');
                $node->setAttribute('loading', 'lazy');
                $node->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            }
            if ($tag === 'a') {
                if ($node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $node->removeAttribute('target');
                }
            }
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }
}
