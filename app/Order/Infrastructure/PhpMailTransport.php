<?php

namespace App\Order\Infrastructure;

use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use Illuminate\Support\Str;

/**
 * Sends through PHP's mail(), which hands the message to the server's sendmail (php.ini `sendmail_path`). A true
 * result only means the local mail system accepted it. Every header value is validated: no line breaks can reach the
 * headers, so a subject or name cannot inject recipients.
 */
final class PhpMailTransport implements OrderMailTransport
{
    /** @var \Closure(string, string, string, string, string): bool */
    private \Closure $mailer;

    /** @param (\Closure(string, string, string, string, string): bool)|null $mailer Replaceable for tests; defaults to mail(). */
    public function __construct(?\Closure $mailer = null)
    {
        $this->mailer = $mailer ?? fn (string $to, string $subject, string $body, string $headers, string $params): bool => mail($to, $subject, $body, $headers, $params);
    }

    public function send(OutgoingMail $mail): MailDelivery
    {
        if (($problem = self::problem($mail)) !== null) {
            return new MailDelivery(false, $problem);
        }
        $boundary = 'cms-'.Str::random(24);
        $related = 'cms-r-'.Str::random(24);
        $headers = [
            'From: '.$this->mailbox($mail->fromName, $mail->fromAddress),
            'MIME-Version: 1.0',
            $mail->inline === [] ? 'Content-Type: multipart/alternative; boundary="'.$boundary.'"' : 'Content-Type: multipart/related; boundary="'.$related.'"; type="multipart/alternative"',
            'X-Mailer: CMS order notifications',
        ];
        if ($mail->replyTo !== null) {
            $headers[] = 'Reply-To: '.$mail->replyTo;
        }
        $part = fn (string $type, string $content): string => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($content))."\r\n";
        $body = $part('text/plain', $mail->text).$part('text/html', $mail->html)."--$boundary--\r\n";
        if ($mail->inline !== []) {
            // The logo travels inside the message (Content-ID), so no public image URL is needed.
            $wrapped = "--$related\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n".$body;
            foreach ($mail->inline as $image) {
                $name = $image['cid'].'.'.substr($image['mime'], 6);
                $wrapped .= "--$related\r\nContent-Type: {$image['mime']}; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <{$image['cid']}>\r\nContent-Disposition: inline; filename=\"$name\"\r\n\r\n".chunk_split(base64_encode($image['content']))."\r\n";
            }
            $body = $wrapped."--$related--\r\n";
        }
        $subject = mb_encode_mimeheader($mail->subject, 'UTF-8', 'B', "\r\n");
        // The envelope sender is a validated address, so it is safe as the sendmail -f argument.
        $accepted = ($this->mailer)($mail->to, $subject, $body, implode("\r\n", $headers), '-f'.$mail->fromAddress);

        return $accepted ? new MailDelivery(true) : new MailDelivery(false, 'mail_rejected');
    }

    /** Shared header safety for every transport: valid addresses, no line breaks in names, subjects or content IDs. */
    public static function problem(OutgoingMail $mail): ?string
    {
        foreach ([$mail->fromAddress, $mail->to, $mail->replyTo] as $address) {
            if ($address !== null && ! self::address($address)) {
                return 'invalid_address';
            }
        }
        foreach ([$mail->fromName, $mail->subject] as $value) {
            if (preg_match('/[\r\n\x00]/', $value) === 1) {
                return 'invalid_header';
            }
        }
        foreach ($mail->inline as $image) {
            if (preg_match('/^[a-z0-9.-]{1,64}$/D', $image['cid']) !== 1 || preg_match('#^image/(png|jpeg|gif|webp)$#D', $image['mime']) !== 1) {
                return 'invalid_header';
            }
        }

        return null;
    }

    private static function address(string $address): bool
    {
        return preg_match('/[\r\n\x00\s<>"\'`$;|&\\\\]/', $address) !== 1 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function mailbox(string $name, string $address): string
    {
        return $name === '' ? $address : mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n").' <'.$address.'>';
    }
}
