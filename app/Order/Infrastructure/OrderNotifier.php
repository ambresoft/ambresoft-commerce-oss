<?php

namespace App\Order\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Customer emails for order creation and every status transition. The record is written with the transition, inside
 * its transaction; delivery runs only after that transaction commits, so a mail problem never rolls back an order and
 * no email is sent for a change that did not happen. One record per transition: retries update it, never duplicate it.
 */
final class OrderNotifier
{
    /** A "sending" claim older than this is treated as an interrupted attempt and may be retried. */
    private const STALE_MINUTES = 10;

    public function __construct(private OrderEmailRenderer $renderer, private OrderMailTransport $transport) {}

    /** Records an email for a transition (its status email or the invoice) and schedules its delivery after commit. */
    public function queue(string $orderId, string $historyId, string $statusId, string $recipient, string $kind = 'status'): string
    {
        $id = (string) DB::table('order_notifications')->insertGetId([
            'order_id' => $orderId, 'history_id' => $historyId, 'status_id' => $statusId, 'recipient' => $recipient, 'kind' => $kind,
            'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->deliver($id));

        return $id;
    }

    /** Manual retry of a failed (or interrupted) email; changes neither the order nor its status. */
    public function retry(string $actor, string $orderId, string $notificationId): bool
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('orders.notify');
        $row = DB::table('order_notifications')->where('id', $notificationId)->where('order_id', $orderId)->first();
        if ($row === null || $row->state === 'sent') {
            throw ValidationException::withMessages(['notification' => __('orders.retry_not_needed')]);
        }
        DB::table('order_notifications')->where('id', $notificationId)->update(['retried_by' => $actor]);

        return $this->deliver($notificationId);
    }

    /** Claims the record atomically, so concurrent requests cannot send the same email twice. */
    public function deliver(string $id): bool
    {
        $claimed = DB::update("UPDATE order_notifications SET state = 'sending', attempts = attempts + 1, last_attempt_at = now(), updated_at = now()
            WHERE id = ? AND (state IN ('pending', 'failed') OR (state = 'sending' AND last_attempt_at < now() - make_interval(mins => ?)))", [$id, self::STALE_MINUTES]);
        if ($claimed === 0) {
            return false;
        }
        $row = DB::table('order_notifications as n')->join('order_status_history as h', 'h.id', '=', 'n.history_id')->join('orders as o', 'o.id', '=', 'n.order_id')
            ->where('n.id', $id)->first(['n.*', 'h.from_status_id', 'h.created_at as changed_at', 'o.locale_id']);
        try {
            $sender = $this->renderer->sender();
            if ($sender === null) {
                return $this->finish($id, null, null, 'sender_missing');
            }
            $locale = (string) DB::table('locales')->where('id', $row->locale_id)->value('code');
            $wrapper = null;
            if ($row->kind === 'invoice') {
                // A standalone document: the shared header and footer never wrap it.
                $wrapper = ['header_html' => '', 'footer_html' => ''];
                $layout = $this->renderer->layout($locale);
                [$templateId, $subject, $body] = [null, $layout['invoice_subject'], $layout['invoice_body_html']];
            } else {
                $template = DB::table('order_email_templates')->where('status_id', $row->status_id)->where('locale_id', $row->locale_id)->first()
                    ?? DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $row->status_id)->where('l.is_default', true)->first(['t.*']);
                if ($template === null) {
                    return $this->finish($id, null, null, 'template_missing');
                }
                // The cancel link (empty unless this status lets the customer cancel) ends every status e-mail that does not place it itself.
                [$templateId, $subject, $body] = [(string) $template->id, $template->subject, str_contains($template->body_html, '{{cancel_link}}') ? $template->body_html : $template->body_html.'{{cancel_link}}'];
            }
            $mail = $this->renderer->render($subject, $body, $this->renderer->forOrder((string) $row->order_id, $row->from_status_id === null ? null : (string) $row->from_status_id, (string) $row->status_id, (string) $row->changed_at), $locale, $wrapper);
            $result = $this->transport->send(new OutgoingMail($sender['address'], $sender['name'], $row->recipient, $mail['subject'], $mail['html'], $mail['text'], $sender['replyTo'], $mail['inline']));

            return $this->finish($id, $templateId, $mail['subject'], $result->accepted ? null : ($result->error ?? 'mail_rejected'));
        } catch (Throwable $e) {
            // Details stay in the server log; the record keeps a short, credential-free reason.
            Log::warning('order_notification_failed', ['notification_id' => $id, 'type' => $e::class]);

            return $this->finish($id, null, null, 'exception');
        }
    }

    private function finish(string $id, ?string $templateId, ?string $subject, ?string $error): bool
    {
        DB::table('order_notifications')->where('id', $id)->update(array_filter([
            'state' => $error === null ? 'sent' : 'failed', 'sent_at' => $error === null ? now() : null, 'last_error' => $error,
            'template_id' => $templateId, 'subject' => $subject, 'updated_at' => now(),
        ], fn ($value, string $key): bool => $value !== null || in_array($key, ['last_error', 'sent_at'], true), ARRAY_FILTER_USE_BOTH));

        return $error === null;
    }
}
