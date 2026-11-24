<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Order\Infrastructure\OrderMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Emails about a gift card: the card itself (with its code) for the recipient, and a notice when it is cancelled. Each
 * request is recorded with the change that caused it and delivered only after that change commits, so a mail problem
 * never undoes a card and nothing is sent for a change that did not happen. The code travels only in the delivery
 * email; the cancellation notice shows its last group. The shop's header and footer wrap every message.
 */
final class GiftCardMail
{
    /** A "sending" claim older than this is an interrupted attempt and may be taken over. */
    private const STALE_MINUTES = 10;

    public function __construct(private OrderEmailRenderer $renderer, private OrderMailTransport $transport, private OrderMoney $money) {}

    /** Records an email for the card's recipient and schedules its delivery once the surrounding transaction commits. */
    public function queue(string $cardId, string $kind, ?string $actor): string
    {
        $recipient = (string) DB::table('gift_cards')->where('id', $cardId)->value('recipient_email');
        $id = (string) DB::table('gift_card_emails')->insertGetId([
            'gift_card_id' => $cardId, 'kind' => $kind, 'recipient' => $recipient, 'state' => 'pending', 'requested_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->deliver($id));

        return $id;
    }

    /** Staff send the card's email again (for example the recipient lost it). Only an active card can be sent. */
    public function resend(string $actor, string $cardId): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('giftcards.manage');
        DB::transaction(function () use ($actor, $cardId): void {
            app(AuditContext::class)->actor($actor);
            $card = DB::table('gift_cards')->where('id', $cardId)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($card === null || $card->status !== 'active') {
                throw ValidationException::withMessages(['status' => __('giftcards.send_inactive')]);
            }
            $this->queue($cardId, 'delivery', $actor);
        });
    }

    /** Claims the record atomically, so concurrent requests cannot send the same email twice. */
    public function deliver(string $id): bool
    {
        $claimed = DB::update("UPDATE gift_card_emails SET state = 'sending', attempts = attempts + 1, last_attempt_at = now(), updated_at = now()
            WHERE id = ? AND (state IN ('pending', 'failed') OR (state = 'sending' AND last_attempt_at < now() - make_interval(mins => ?)))", [$id, self::STALE_MINUTES]);
        if ($claimed === 0) {
            return false;
        }
        try {
            $email = DB::table('gift_card_emails')->where('id', $id)->first();
            $sender = $this->renderer->sender();
            if ($sender === null) {
                return $this->finish($id, 'sender_missing');
            }
            $card = DB::table('gift_cards as g')->join('locales as l', 'l.id', '=', 'g.locale_id')->where('g.id', $email->gift_card_id)->first(['g.*', 'l.code as locale_code']);
            [$subject, $body] = $this->compose($card, $email->kind);
            $locale = $card->locale_code;
            $mail = $this->renderer->render($subject, $body, $this->renderer->storeVariables($locale), $locale);
            $result = $this->transport->send(new OutgoingMail($sender['address'], $sender['name'], $email->recipient, $mail['subject'], $mail['html'], $mail['text'], $sender['replyTo'], $mail['inline']));

            return $this->finish($id, $result->accepted ? null : ($result->error ?? 'mail_rejected'));
        } catch (Throwable $e) {
            // The record keeps a short, credential-free reason; the details stay in the server log.
            Log::warning('gift_card_email_failed', ['email_id' => $id, 'type' => $e::class]);

            return $this->finish($id, 'exception');
        }
    }

    /**
     * Subject and HTML body in the card's language. Text from people (names, message) is escaped and its braces are
     * neutralised, because the renderer would otherwise read `{{...}}` in it as a placeholder.
     *
     * @return array{string, string}
     */
    private function compose(object $card, string $kind): array
    {
        $locale = $card->locale_code;
        $t = fn (string $key, array $replace = []): string => __('giftcards.'.$key, $replace, $locale);
        $html = fn (string $value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], e($value));
        $store = (string) ($this->renderer->storeVariables($locale)['text']['store_name']);
        $amount = $this->money->format($card->amount, $card->currency_code, (int) $card->currency_decimals);
        $plain = fn (string $value): string => trim(str_replace(['{', '}'], '', $value));
        if ($kind === 'cancelled') {
            $balance = $this->money->format($card->balance, $card->currency_code, (int) $card->currency_decimals);

            return [
                $t('mail_cancelled_subject', ['store' => $plain($store)]),
                '<h1 style="margin:0 0 12px; font-size:24px; line-height:1.3; color:#111827">'.$html($t('mail_cancelled_title')).'</h1>'
                .'<p style="margin:0 0 16px">'.$html($t('mail_cancelled_text', ['code' => GiftCardRules::masked($card->number), 'amount' => $amount, 'store' => $store])).'</p>'
                .'<p style="margin:0 0 16px">'.$html($t('mail_cancelled_balance', ['balance' => $balance])).'</p>'
                .'<p style="margin:0">'.$html($t('mail_questions', ['store' => $store])).'</p>',
            ];
        }
        $message = trim((string) $card->message) === '' ? '' : '<blockquote style="margin:0 0 20px; padding:12px 16px; border-left:4px solid #d1d5db; background-color:#f9fafb; color:#374151">'.nl2br($html((string) $card->message), false).'</blockquote>';

        return [
            $t('mail_subject', ['sender' => $plain($card->sender_name), 'store' => $plain($store)]),
            '<h1 style="margin:0 0 12px; font-size:24px; line-height:1.3; color:#111827">'.$html($t('mail_title', ['name' => $card->recipient_name])).'</h1>'
            .'<p style="margin:0 0 20px">'.$html($t('mail_intro', ['sender' => $card->sender_name, 'store' => $store])).'</p>'.$message
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; border-radius:12px"><tbody><tr><td align="center" style="padding:24px 20px">'
            .'<div style="font-size:13px; color:#4b5563">'.$html($t('mail_value')).'</div><div style="margin:2px 0 14px; font-size:30px; font-weight:bold; color:#111827">'.$html($amount).'</div>'
            .'<div style="font-size:13px; color:#4b5563">'.$html($t('mail_code')).'</div><div style="margin-top:2px; font-family:Menlo,Consolas,monospace; font-size:22px; font-weight:bold; letter-spacing:1px; color:#111827">'.$html($card->number).'</div>'
            .'</td></tr></tbody></table>'
            .'<p style="margin:20px 0 0">'.$html($t('mail_how_to', ['store' => $store])).'</p>',
        ];
    }

    private function finish(string $id, ?string $error): bool
    {
        DB::table('gift_card_emails')->where('id', $id)->update([
            'state' => $error === null ? 'sent' : 'failed', 'sent_at' => $error === null ? now() : null, 'last_error' => $error, 'updated_at' => now(),
        ]);

        return $error === null;
    }
}
