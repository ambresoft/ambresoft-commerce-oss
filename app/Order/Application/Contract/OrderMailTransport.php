<?php

namespace App\Order\Application\Contract;

use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;

/**
 * Delivery boundary for order emails. PHP mail() is the implemented transport; an SMTP transport configured in a
 * future settings phase only needs another implementation of this interface.
 */
interface OrderMailTransport
{
    public function send(OutgoingMail $mail): MailDelivery;
}
