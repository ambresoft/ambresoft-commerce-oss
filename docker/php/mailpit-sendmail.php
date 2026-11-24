<?php

/*
 * Local development only: a minimal sendmail replacement so PHP's mail() delivers to the Mailpit container.
 * PHP pipes the complete message (headers and body) to this script, as it would to /usr/sbin/sendmail -t -i.
 * Production servers use their real sendmail/MTA via php.ini sendmail_path instead; this file is not used there.
 */

$host = getenv('MAILPIT_SMTP_HOST') ?: 'mailpit';
$port = (int) (getenv('MAILPIT_SMTP_PORT') ?: 1025);
$message = stream_get_contents(STDIN);
if ($message === false || $message === '') {
    exit(1);
}
$message = str_replace(["\r\n", "\r"], "\n", $message);
[$head] = explode("\n\n", $message, 2) + [1 => ''];
$from = null;
foreach ($argv as $index => $arg) {
    if (str_starts_with($arg, '-f')) {
        $from = strlen($arg) > 2 ? substr($arg, 2) : ($argv[$index + 1] ?? null);
    }
}
$recipients = [];
// Unfold continuation lines, then collect To/Cc/Bcc addresses (-t behaviour).
foreach (preg_split("/\n(?![ \t])/", $head) as $header) {
    if (preg_match('/^(to|cc|bcc):(.*)$/is', $header, $match) === 1) {
        preg_match_all('/[^\s<>,"]+@[^\s<>,"]+/', $match[2], $addresses);
        array_push($recipients, ...$addresses[0]);
    }
    if ($from === null && preg_match('/^from:(.*)$/is', $header, $match) === 1 && preg_match('/[^\s<>,"]+@[^\s<>,"]+/', $match[1], $address) === 1) {
        $from = $address[0];
    }
}
$message = preg_replace('/^bcc:.*\n(?:[ \t].*\n)*/im', '', $message) ?? $message;
if ($recipients === [] || $from === null) {
    exit(1);
}
$socket = @fsockopen($host, $port, $errno, $error, 5);
if ($socket === false) {
    exit(1);
}
$expect = function (string $code) use ($socket): void {
    do {
        $line = fgets($socket, 1024);
        if ($line === false || ! str_starts_with($line, $code)) {
            exit(1);
        }
    } while (($line[3] ?? ' ') === '-');
};
$send = function (string $line) use ($socket): void {
    fwrite($socket, $line."\r\n");
};
$expect('220');
$send('EHLO localhost');
$expect('250');
$send('MAIL FROM:<'.$from.'>');
$expect('250');
foreach (array_unique($recipients) as $recipient) {
    $send('RCPT TO:<'.$recipient.'>');
    $expect('250');
}
$send('DATA');
$expect('354');
foreach (explode("\n", rtrim($message, "\n")) as $line) {
    // SMTP dot-stuffing.
    $send(str_starts_with($line, '.') ? '.'.$line : $line);
}
$send('.');
$expect('250');
$send('QUIT');
fclose($socket);
exit(0);
