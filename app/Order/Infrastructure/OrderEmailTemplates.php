<?php

namespace App\Order\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Command\MailDelivery;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use App\Order\Domain\Service\TemplateTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Editable order email content: each status's subject and body, and per language the shared header/footer and the
 * invoice email. HTML is sanitized with the email policy (placeholders are plain text and survive); only
 * allow-listed placeholders are accepted; nothing in a template is ever evaluated as code. Previews and test emails
 * render unsaved content with fictitious data and never touch an order.
 */
final class OrderEmailTemplates
{
    public const KINDS = ['status', 'invoice', 'layout', 'packing_slip'];

    public function __construct(private TemplateTokens $tokens, private EmailHtmlSanitizer $sanitizer, private OrderEmailRenderer $renderer) {}

    public function save(string $actor, string $id, int $version, string $subject, string $body): void
    {
        app(AuditLogger::class)->operation('order_email_template', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $subject, $body): void {
            $this->authorize($actor);
            $this->locked('order_email_templates', $id, $version);
            $content = $this->validated(['subject' => $subject, 'body' => $body]);
            DB::table('order_email_templates')->where('id', $id)->update(['subject' => $content['subject'], 'body_html' => $content['body'], 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    /** @param array{header?: string, footer?: string, subject?: string, body?: string, item?: string, total?: string} $fields Layout parts, the invoice subject and body, or (packing_slip) the packing slip's body and row. */
    public function saveLayout(string $actor, string $id, int $version, array $fields, string $kind = 'layout'): void
    {
        app(AuditLogger::class)->operation('order_email_layout', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $fields, $kind): void {
            $this->authorize($actor);
            $this->locked('order_email_layouts', $id, $version);
            $content = $this->validated($fields);
            $map = $kind === 'packing_slip' ? ['body' => 'packing_slip_body_html', 'item' => 'packing_slip_item_html']
                : ['header' => 'header_html', 'footer' => 'footer_html', 'subject' => 'invoice_subject', 'body' => 'invoice_body_html', 'item' => 'invoice_item_html', 'total' => 'invoice_total_html'];
            $content = array_intersect_key($content, $map);
            $values = [];
            foreach ($content as $field => $value) {
                $values[$map[$field]] = $value;
            }
            DB::table('order_email_layouts')->where('id', $id)->update([...$values, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    /**
     * Renders unsaved content as the customer will see it. Nothing is stored or sent.
     *
     * @param  array{subject?: string, body?: string, header?: string, footer?: string, item?: string, total?: string}  $content
     * @return array{subject: string, html: string}
     */
    public function preview(string $actor, string $kind, ?string $statusId, string $locale, array $content): array
    {
        $this->authorize($actor);
        $mail = $this->build($kind, $statusId, $locale, $content);

        return ['subject' => $mail['subject'], 'html' => $kind === 'packing_slip' ? $mail['html'] : $this->renderer->preview($mail['html'])];
    }

    /**
     * Sends unsaved content with sample data to one address. No order, history or notification record is involved.
     *
     * @param  array{subject?: string, body?: string, header?: string, footer?: string, item?: string, total?: string}  $content
     */
    public function test(?string $actor, string $kind, ?string $statusId, string $locale, array $content, string $recipient, ?string $from = null): MailDelivery
    {
        if ($actor !== null) {
            $this->authorize($actor);
        }
        $sender = $this->renderer->sender();
        if ($from !== null) {
            $sender = ['address' => $from, 'name' => $sender['name'] ?? (string) config('app.name'), 'replyTo' => $sender['replyTo'] ?? null];
        }
        if ($sender === null) {
            return new MailDelivery(false, 'sender_missing');
        }
        if ($kind === 'packing_slip') {
            throw ValidationException::withMessages(['kind' => __('orders.choice_invalid')]);
        }
        $mail = $this->build($kind, $statusId, $locale, $content);

        return app(OrderMailTransport::class)->send(new OutgoingMail($sender['address'], $sender['name'], $recipient, $mail['subject'], $mail['html'], $mail['text'], $sender['replyTo'], $mail['inline']));
    }

    /**
     * @param  array{subject?: string, body?: string, header?: string, footer?: string, item?: string, total?: string}  $content
     * @return array{subject: string, html: string, text: string, inline: list<array{cid: string, mime: string, content: string}>}
     */
    private function build(string $kind, ?string $statusId, string $locale, array $content): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('orders.choice_invalid')]);
        }
        $content = $this->validated($content);
        if ($kind === 'packing_slip') {
            // Printed, never e-mailed: no subject, header or footer.
            return ['subject' => '', 'html' => $this->renderer->packingSlipPreview($locale, array_intersect_key($content, ['body' => true, 'item' => true])), 'text' => '', 'inline' => []];
        }
        $saved = $this->renderer->layout($locale);
        $layout = ['header_html' => $content['header'] ?? $saved['header_html'], 'footer_html' => $content['footer'] ?? $saved['footer_html']];
        if ($kind === 'invoice') {
            // The invoice email is a standalone document: the shared header and footer never wrap it.
            $layout = ['header_html' => '', 'footer_html' => ''];
            $subject = $content['subject'] ?? $saved['invoice_subject'];
            $body = InvoiceLayout::expand($content['body'] ?? $saved['invoice_body_html'], $locale);
            $statusId ??= DB::table('order_statuses')->where('sends_invoice', true)->orderBy('position')->value('id');
        } else {
            // A layout preview shows the header and footer around the initial status's email.
            $statusId ??= DB::table('order_statuses')->where('is_initial', true)->value('id');
            $template = DB::table('order_email_templates as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $statusId)->where('l.code', $locale)->first(['t.subject', 't.body_html']);
            $subject = $content['subject'] ?? $template->subject ?? '';
            $body = $content['body'] ?? $template->body_html ?? '';
        }

        return $this->renderer->render($subject, $body, $this->renderer->sample($locale, $statusId === null ? null : (string) $statusId, $kind === 'invoice' ? $content : []), $locale, $layout);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string> Trimmed subject; sanitized HTML parts.
     */
    private function validated(array $fields): array
    {
        $errors = [];
        $clean = [];
        foreach (['subject', 'body', 'header', 'footer', 'item', 'total'] as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $value = is_string($fields[$field]) ? $fields[$field] : '';
            if ($field === 'subject') {
                $value = trim($value);
                if ($value === '' || mb_strlen($value) > 250 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    $errors['subject'] = __('orders.template_subject_invalid');
                }
            } else {
                if (strlen($value) > 100000) {
                    $errors[$field] = __('orders.template_body_invalid');
                }
                $value = in_array($field, ['item', 'total'], true) ? $this->sanitizer->sanitize('<table><tbody>'.$value.'</tbody></table>') : $this->sanitizer->sanitize($value);
                if (in_array($field, ['item', 'total'], true)) {
                    $value = (string) preg_replace('~^<table><tbody>|</tbody></table>$~', '', $value);
                }
                if (in_array($field, ['body', 'item', 'total'], true) && trim(strip_tags($value)) === '') {
                    $errors[$field] = __('orders.template_body_invalid');
                }
            }
            $unknown = $this->tokens->unknown($value, OrderEmailDefaults::VARIABLES);
            if ($unknown !== []) {
                $errors[$field] = __('orders.template_unknown_variables', ['variables' => implode(', ', array_map(fn (string $v): string => '{{'.$v.'}}', $unknown))]);
            }
            $clean[$field] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function locked(string $table, string $id, int $version): void
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('orders.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('orders.stale')]);
        }
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('order-emails.manage');
    }
}
