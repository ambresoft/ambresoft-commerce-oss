<?php

namespace Tests\Fakes;

use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;

/** Test transport: records messages instead of sending them; can be told to reject. */
final class RecordingMailTransport implements OrderMailTransport
{
    /** @var list<OutgoingMail> */
    public array $sent = [];

    public bool $accept = true;

    public function send(OutgoingMail $mail): MailDelivery
    {
        $this->sent[] = $mail;

        return $this->accept ? new MailDelivery(true) : new MailDelivery(false, 'mail_rejected');
    }
}
