<?php

namespace App\Storefront\Application\Command;

/** A published content page, in plain text. */
final readonly class CatalogPage
{
    /** @param list<array{question: string, answer: string}> $faqs */
    public function __construct(
        public string $id,
        public string $title,
        public string $content,
        public string $url,
        public array $faqs,
    ) {}
}
