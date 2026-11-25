<?php

namespace Tests\Unit;

use App\Order\Application\Command\OutgoingMail;
use App\Order\Domain\Service\OrderStockRules;
use App\Order\Domain\Service\OrderTotals;
use App\Order\Domain\Service\TemplateTokens;
use App\Order\Infrastructure\PhpMailTransport;
use PHPUnit\Framework\TestCase;

final class OrderRulesTest extends TestCase
{
    public function test_stock_targets_hold_release_and_keep_according_to_the_status_action(): void
    {
        $rules = new OrderStockRules;
        self::assertSame(3, $rules->target('decrease', false, 3));
        self::assertSame(0, $rules->target('restore', true, 3));
        self::assertSame(3, $rules->target('none', true, 3), '"No change" keeps what the order holds');
        self::assertSame(0, $rules->target('none', false, 3));
        self::assertTrue($rules->holds('decrease', false));
        self::assertFalse($rules->holds('restore', true));
    }

    public function test_template_tokens_are_allow_listed_and_never_evaluated(): void
    {
        $tokens = new TemplateTokens;
        self::assertSame(['customer_ip', 'system'], $tokens->unknown('{{ order_number }} {{customer_ip}} {{system}} {{order_number}}', ['order_number']));
        self::assertSame('#7 and ', $tokens->render('#{{ order_number }} and {{customer_ip}}', ['order_number' => '7']));
        self::assertSame('{{ php_info() }}', $tokens->render('{{ php_info() }}', []), 'Anything that is not a plain name is left as text');
    }

    public function test_allocation_splits_exactly_by_weight(): void
    {
        $totals = new OrderTotals;
        self::assertSame(['3.34', '3.33', '3.33'], $totals->allocate('10', ['1', '1', '1'], 2), 'The remainder goes to the earliest line on ties');
        self::assertSame(['2.40', '4.80'], $totals->allocate('7.20', ['10', '20'], 2));
        self::assertSame(['5.00', '0.00'], $totals->allocate('5', ['0', '0'], 2));
        self::assertSame('37.2', $totals->total('30', '0', '7.2', '0', '0'));
        self::assertSame('24.8', $totals->total('24.8', '0', '4.8', '4.8', '0'), 'Included tax is not added again');
    }

    public function test_php_mail_transport_encodes_utf8_and_refuses_header_injection(): void
    {
        $calls = [];
        $transport = new PhpMailTransport(function (string $to, string $subject, string $body, string $headers, string $params) use (&$calls): bool {
            $calls[] = compact('to', 'subject', 'body', 'headers', 'params');

            return true;
        });
        $mail = fn (array $changes = []): OutgoingMail => new OutgoingMail(...array_replace(['fromAddress' => 'orders@store.example', 'fromName' => 'Κατάστημα', 'to' => 'maria@example.test', 'subject' => 'Παραγγελία 100001', 'html' => '<p>Γεια</p>', 'text' => 'Γεια', 'replyTo' => 'help@store.example'], $changes));
        self::assertTrue($transport->send($mail())->accepted);
        self::assertStringStartsWith('=?UTF-8?B?', $calls[0]['subject']);
        self::assertStringContainsString('Content-Type: multipart/alternative', $calls[0]['headers']);
        self::assertStringContainsString('Reply-To: help@store.example', $calls[0]['headers']);
        self::assertSame('-forders@store.example', $calls[0]['params']);
        self::assertStringContainsString(base64_encode('<p>Γεια</p>'), str_replace("\r\n", '', $calls[0]['body']));
        self::assertSame('invalid_header', $transport->send($mail(['subject' => "Hi\r\nBcc: victim@example.test"]))->error);
        self::assertSame('invalid_address', $transport->send($mail(['to' => "maria@example.test\r\nBcc: x@example.test"]))->error);
        self::assertSame('invalid_address', $transport->send($mail(['fromAddress' => 'a@b.c -X/tmp/log']))->error);
        self::assertCount(1, $calls, 'Rejected messages never reach mail()');
        // An inline logo makes the message multipart/related with a Content-ID part.
        self::assertTrue($transport->send($mail(['inline' => [['cid' => 'store-logo', 'mime' => 'image/png', 'content' => 'PNGDATA']]]))->accepted);
        $last = end($calls);
        self::assertStringContainsString('Content-Type: multipart/related;', $last['headers']);
        self::assertStringContainsString('Content-ID: <store-logo>', $last['body']);
        self::assertStringContainsString(base64_encode('PNGDATA'), $last['body']);
        self::assertSame('invalid_header', $transport->send($mail(['inline' => [['cid' => "x\r\nBcc: y", 'mime' => 'image/png', 'content' => 'x']]]))->error);
        $rejecting = new PhpMailTransport(fn (): bool => false);
        self::assertSame('mail_rejected', $rejecting->send($mail())->error);
    }
}
