<?php

namespace App\Shared\Application\Contract;

/**
 * Sends one e-mail to a customer in the store's e-mail look (logo, colours) from the store's sender, through the
 * delivery method chosen in Settings → General → Emails.
 */
interface StoreMailer
{
    /**
     * @param  string  $html  The message body; it is placed inside the store's layout
     * @return string|null Null when the mail server accepted the message, else a short reason
     */
    public function send(string $to, string $subject, string $html, string $text, string $locale): ?string;
}
