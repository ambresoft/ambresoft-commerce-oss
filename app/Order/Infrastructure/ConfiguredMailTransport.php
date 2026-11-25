<?php

namespace App\Order\Infrastructure;

use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use App\Settings\Infrastructure\GeneralSettings;
use Closure;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The delivery method chosen in Settings → General → Emails, read on every send: PHP mail(), an SMTP server
 * (None / STARTTLS / implicit SSL, optional authentication) or the sendmail program.
 */
final class ConfiguredMailTransport implements OrderMailTransport
{
    /** @var Closure(array<string, mixed>): TransportInterface */
    private Closure $factory;

    /** @param (Closure(array<string, mixed>): TransportInterface)|null $factory Replaceable for tests. */
    public function __construct(private GeneralSettings $settings, private PhpMailTransport $php, ?Closure $factory = null)
    {
        $this->factory = $factory ?? fn (array $config): TransportInterface => $this->transport($config);
    }

    public function send(OutgoingMail $mail): MailDelivery
    {
        $config = $this->settings->mailDelivery();
        if ($config['method'] === 'php_mail') {
            return $this->php->send($mail);
        }
        if ($config['method'] === 'smtp' && ($config['host'] === null || ($config['auth'] && $config['password'] === null))) {
            return new MailDelivery(false, 'smtp_incomplete');
        }

        return (new SymfonyMailTransport(($this->factory)($config), $config['method']))->send($mail);
    }

    /** @param array<string, mixed> $config */
    private function transport(array $config): TransportInterface
    {
        if ($config['method'] === 'sendmail') {
            // -bs: the program speaks SMTP on stdin, so recipients are never passed as shell arguments.
            return new SendmailTransport($config['sendmail'].' -bs');
        }
        $encryption = (string) $config['encryption'];
        $port = $config['port'] ?? match ($encryption) {
            'ssl' => 465, 'tls' => 587, default => 25,
        };
        $smtp = new EsmtpTransport((string) $config['host'], (int) $port, $encryption === 'ssl');
        if ($encryption === 'tls') {
            $smtp->setRequireTls(true);
        } elseif ($encryption === 'none') {
            $smtp->setAutoTls(false);
        }
        if ($config['auth']) {
            $smtp->setUsername((string) $config['username']);
            $smtp->setPassword((string) $config['password']);
        }

        return $smtp;
    }
}
