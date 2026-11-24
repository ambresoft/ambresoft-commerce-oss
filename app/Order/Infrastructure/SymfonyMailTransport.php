<?php

namespace App\Order\Infrastructure;

use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through a Symfony Mailer transport (SMTP server or sendmail program). The same header safety as the PHP
 * mail() transport applies; the shop logo is embedded and referenced by its Content-ID. Server responses go to the
 * log, never to the notification record, so no credential or server detail reaches the admin screens.
 */
final class SymfonyMailTransport implements OrderMailTransport
{
    public function __construct(private TransportInterface $transport, private string $method) {}

    public function send(OutgoingMail $mail): MailDelivery
    {
        if (($problem = PhpMailTransport::problem($mail)) !== null) {
            return new MailDelivery(false, $problem);
        }
        $email = (new Email)->from(new Address($mail->fromAddress, $mail->fromName))->to($mail->to)->subject($mail->subject)->text($mail->text)->html($mail->html);
        if ($mail->replyTo !== null) {
            $email->replyTo($mail->replyTo);
        }
        foreach ($mail->inline as $image) {
            // Symfony rewrites cid:<name> references in the HTML to the embedded part's Content-ID.
            $email->embed($image['content'], $image['cid'], $image['mime']);
        }
        try {
            $this->transport->send($email);

            return new MailDelivery(true);
        } catch (TransportExceptionInterface $e) {
            Log::warning('order_mail_transport_failed', ['method' => $this->method, 'type' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500)]);

            return new MailDelivery(false, $this->method === 'smtp' ? 'smtp_failed' : 'sendmail_failed');
        }
    }
}
