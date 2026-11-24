<?php

namespace App\Order\Application\Command;

/** One rendered customer email. Header values are validated by the transport; bodies are complete documents. */
final readonly class OutgoingMail
{
    /** @param list<array{cid: string, mime: string, content: string}> $inline Images referenced as cid: in the HTML. */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?string $replyTo = null,
        public array $inline = [],
    ) {}
}
