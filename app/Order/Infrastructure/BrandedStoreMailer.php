<?php

namespace App\Order\Infrastructure;

use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use App\Shared\Application\Contract\StoreMailer;
use App\Storefront\Infrastructure\StoreBrand;

/** The store's own e-mails outside orders, with the newsletter confirmation's layout and the order e-mail sender. */
final class BrandedStoreMailer implements StoreMailer
{
    public function __construct(private OrderEmailRenderer $renderer, private OrderMailTransport $transport) {}

    public function send(string $to, string $subject, string $html, string $text, string $locale): ?string
    {
        $sender = $this->renderer->sender();
        if ($sender === null) {
            return 'sender_missing';
        }
        $document = view('mail.newsletter-confirmation', ['subject' => $subject, 'body' => $html, 'locale' => $locale, 'brand' => app(StoreBrand::class)->all(),
            'logoHtml' => $this->renderer->storeVariables($locale)['html']['store_logo']])->render();
        $result = $this->transport->send(new OutgoingMail($sender['address'], $sender['name'], $to, $subject, $document, $text, $sender['replyTo'], $this->renderer->inlineImages()));

        return $result->accepted ? null : ($result->error ?? 'rejected');
    }
}
