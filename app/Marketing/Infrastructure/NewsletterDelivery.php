<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\NewsletterPlaceholders;
use App\Order\Application\Command\OutgoingMail;
use App\Order\Application\Contract\OrderMailTransport;
use App\Order\Infrastructure\EmailHtmlSanitizer;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Settings\Infrastructure\CompanySettings;
use App\Storefront\Infrastructure\StoreBrand;
use App\Storefront\Infrastructure\ThemeColors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns newsletters and list confirmations into deliveries and sends them through the configured mail transport.
 * One delivery per newsletter and recipient address (autoresponders: per list too) makes sending idempotent: sending
 * again only reaches recipients that were not addressed before. Deliveries are claimed atomically, retried up to
 * three times, and a newsletter becomes "sent" when nothing is left to deliver.
 */
final class NewsletterDelivery
{
    use NewsletterWrites;

    private const MAX_ATTEMPTS = 3;

    private const STALE_MINUTES = 10;

    public function __construct(private OrderMailTransport $transport, private OrderEmailRenderer $renderer, private EmailHtmlSanitizer $sanitizer) {}

    /** Creates deliveries for everyone in the audience who was not addressed yet; returns how many were created. */
    public function queueNewsletter(string $actor, string $id): int
    {
        $count = $this->audited('newsletter', $actor, fn (): int => DB::transaction(function () use ($actor, $id): int {
            $this->authorize($actor, 'newsletters.send');
            $row = DB::table('newsletters')->where('id', $id)->where('type', 'newsletter')->lockForUpdate()->first() ?? throw $this->stale();
            if (! $row->is_active) {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.send_inactive')]);
            }
            $default = app(LocaleCatalog::class)->defaultCode();
            $text = DB::table('newsletter_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.newsletter_id', $id)->where('l.code', $default)->first(['t.subject', 't.body_html']);
            if ($text === null || trim($text->subject) === '' || trim($text->body_html) === '') {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.send_content_missing')]);
            }
            if ($this->renderer->sender() === null) {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.sender_missing')]);
            }
            $created = DB::affectingStatement("INSERT INTO newsletter_deliveries (newsletter_id, subscriber_id, list_id, locale_id, email, dedupe_key, state, created_at, updated_at)
                SELECT DISTINCT ON (lower(s.email)) ?, s.id, ls.list_id, s.locale_id, s.email, lower(s.email), 'pending', now(), now()
                FROM newsletter_subscribers s JOIN newsletter_list_subscribers ls ON ls.subscriber_id = s.id JOIN newsletter_lists l ON l.id = ls.list_id
                WHERE ls.list_id IN (SELECT list_id FROM newsletter_audiences WHERE newsletter_id = ?) AND l.status = 'active' AND ls.confirmed_at IS NOT NULL AND s.status = 'active'
                ORDER BY lower(s.email), ls.list_id
                ON CONFLICT DO NOTHING", [$id, $id]);
            // Registered customers addressed directly, unless they unsubscribed.
            $created += DB::affectingStatement("INSERT INTO newsletter_deliveries (newsletter_id, subscriber_id, list_id, locale_id, email, dedupe_key, state, created_at, updated_at)
                SELECT ?, s.id, NULL, s.locale_id, c.email, lower(c.email), 'pending', now(), now()
                FROM customers c LEFT JOIN newsletter_subscribers s ON lower(s.email) = lower(c.email)
                WHERE c.id IN (SELECT customer_id FROM newsletter_audience_customers WHERE newsletter_id = ?) AND c.status = 'active' AND (s.id IS NULL OR s.status = 'active')
                ON CONFLICT DO NOTHING", [$id, $id]);
            if ($created === 0 && ! DB::table('newsletter_deliveries')->where('newsletter_id', $id)->exists()) {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.send_no_recipients')]);
            }
            if ($created > 0) {
                DB::table('newsletters')->where('id', $id)->update(['state' => 'sending', 'sent_at' => null, 'updated_by' => $actor, 'updated_at' => now()]);
            }

            return $created;
        }));
        if ($count > 0) {
            $this->dispatchPending();
        }

        return $count;
    }

    /** Sends the list's confirmation autoresponder to a subscriber who just joined (once per list and address). */
    public function queueConfirmation(string $listId, string $subscriberId): void
    {
        $list = DB::table('newsletter_lists')->where('id', $listId)->first(['confirmation_id']);
        $auto = $list?->confirmation_id === null ? null : DB::table('newsletters')->where('id', $list->confirmation_id)->where('is_active', true)->first(['id']);
        $subscriber = DB::table('newsletter_subscribers')->where('id', $subscriberId)->first(['email', 'locale_id']);
        if ($auto === null || $subscriber === null) {
            return;
        }
        $inserted = DB::affectingStatement("INSERT INTO newsletter_deliveries (newsletter_id, subscriber_id, list_id, locale_id, email, dedupe_key, state, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', now(), now()) ON CONFLICT DO NOTHING",
            [$auto->id, $subscriberId, $listId, $subscriber->locale_id, $subscriber->email, mb_strtolower($subscriber->email).'|'.$listId]);
        if ($inserted > 0) {
            DB::afterCommit(fn () => $this->dispatchPending());
        }
    }

    /** Sends a preview of a newsletter, template or autoresponder to one address; nothing is recorded. */
    public function sendTest(string $actor, string $id, string $email, string $localeCode): void
    {
        $this->authorize($actor, 'newsletters.send');
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw ValidationException::withMessages(['email' => __('newsletters.email_invalid')]);
        }
        $newsletter = DB::table('newsletters')->where('id', $id)->first() ?? throw $this->stale();
        $sender = $this->renderer->sender() ?? throw ValidationException::withMessages(['email' => __('newsletters.sender_missing')]);
        [$subject, $body] = $this->texts((string) $id, $localeCode, null);
        $confirmation = $newsletter->type === 'autoresponder';
        $values = $this->values($email, '#', '#', $localeCode, $confirmation);
        $result = $this->transport->send(new OutgoingMail($sender['address'], $sender['name'], $email, '['.__('newsletters.test_prefix').'] '.$subject, $this->document($subject, NewsletterPlaceholders::render($body, $values), $localeCode, $confirmation),
            NewsletterPlaceholders::text(NewsletterPlaceholders::render($body, $values)), $sender['replyTo'], $this->renderer->inlineImages()));
        if (! $result->accepted) {
            throw ValidationException::withMessages(['email' => __('newsletters.test_failed')]);
        }
        unset($newsletter);
    }

    /**
     * Renders unsaved content the way a recipient sees it, with clearly fictitious values; nothing is saved or sent.
     *
     * @return array{subject: string, html: string}
     */
    public function preview(string $actor, string $subject, string $body, string $localeCode, string $type = 'newsletter'): array
    {
        $this->authorize($actor);
        $unknown = NewsletterPlaceholders::unknown($subject.' '.$body);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body_html' => __('newsletters.unknown_variables', ['variables' => implode(', ', array_map(fn (string $v): string => '{{'.$v.'}}', $unknown))])]);
        }
        $confirmation = $type === 'autoresponder';
        $values = $this->values('subscriber@example.test', 'https://example.test/newsletter/unsubscribe/sample', 'https://example.test/newsletter/confirm/sample', $localeCode, $confirmation);
        $plain = array_map(fn (string $v): string => html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $values);
        $subject = trim((string) preg_replace('/\s+/', ' ', NewsletterPlaceholders::render($subject, $plain)));

        return ['subject' => mb_substr($subject, 0, 300), 'html' => $this->renderer->preview($this->document($subject, NewsletterPlaceholders::render($this->sanitizer->sanitize($body), $values), $localeCode, $confirmation))];
    }

    /** Dispatches queue jobs for deliveries that are waiting, retryable or interrupted. */
    public function dispatchPending(int $limit = 500): int
    {
        $ids = DB::table('newsletter_deliveries')->where(fn ($q) => $q->where('state', 'pending')
            ->orWhere(fn ($r) => $r->where('state', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)->where('last_attempt_at', '<', now()->subMinutes(5)))
            ->orWhere(fn ($r) => $r->where('state', 'sending')->where('last_attempt_at', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            Jobs\SendNewsletterDelivery::dispatch((string) $id);
        }
        $this->finishNewsletters();

        return $ids->count();
    }

    /** Claims and sends one delivery; concurrent workers cannot send the same message twice. */
    public function deliver(string $id): bool
    {
        $claimed = DB::update("UPDATE newsletter_deliveries SET state = 'sending', attempts = attempts + 1, last_attempt_at = now(), updated_at = now()
            WHERE id = ? AND (state = 'pending' OR (state = 'failed' AND attempts < ?) OR (state = 'sending' AND last_attempt_at < now() - make_interval(mins => ?)))", [$id, self::MAX_ATTEMPTS, self::STALE_MINUTES]);
        if ($claimed === 0) {
            return false;
        }
        $row = DB::table('newsletter_deliveries as d')->join('newsletters as n', 'n.id', '=', 'd.newsletter_id')->where('d.id', $id)->first(['d.*', 'n.type', 'n.is_active']);
        try {
            if (! $row->is_active) {
                return $this->finish($id, 'inactive', false);
            }
            $list = $row->list_id === null ? null : DB::table('newsletter_lists')->where('id', $row->list_id)->first();
            $sender = $this->renderer->sender();
            if ($sender === null) {
                return $this->finish($id, 'sender_missing');
            }
            $code = $row->locale_id === null ? app(LocaleCatalog::class)->defaultCode() : (string) DB::table('locales')->where('id', $row->locale_id)->value('code');
            [$subject, $body] = $this->texts((string) $row->newsletter_id, $code, (string) $row->id);
            if (trim($subject) === '' || trim($body) === '') {
                return $this->finish($id, 'content_missing');
            }
            $unsubscribe = $row->subscriber_id === null || ($row->type === 'newsletter' && $row->list_id === null) ? null
                : URL::signedRoute('newsletter.unsubscribe', array_filter(['subscriber' => $row->subscriber_id, 'list' => $row->list_id]));
            $activation = $row->type === 'autoresponder' && $row->list_id !== null && $row->subscriber_id !== null ? URL::signedRoute('newsletter.confirm', ['subscriber' => $row->subscriber_id, 'list' => $row->list_id]) : null;
            $confirmation = $row->type === 'autoresponder';
            $html = NewsletterPlaceholders::render($body, $this->values($row->email, $unsubscribe, $activation, $code, $confirmation));
            $result = $this->transport->send(new OutgoingMail($list?->from_email ?: $sender['address'], $list?->from_name ?: $sender['name'], $row->email, $subject,
                $this->document($subject, $html, $code, $confirmation), NewsletterPlaceholders::text($html), $list?->reply_to ?: $sender['replyTo'], $this->renderer->inlineImages()));

            return $this->finish($id, $result->accepted ? null : ($result->error ?? 'rejected'));
        } catch (Throwable $e) {
            Log::warning('newsletter_delivery_failed', ['delivery_id' => $id, 'type' => $e::class]);

            return $this->finish($id, 'exception');
        }
    }

    private function finish(string $id, ?string $error, bool $retry = true): bool
    {
        DB::table('newsletter_deliveries')->where('id', $id)->update([
            'state' => $error === null ? 'sent' : 'failed', 'sent_at' => $error === null ? now() : null, 'error' => $error === null ? null : substr($error, 0, 60),
            // Not retryable (e.g. the newsletter was disabled): exhaust the attempts.
            ...($retry ? [] : ['attempts' => self::MAX_ATTEMPTS]), 'updated_at' => now(),
        ]);
        $this->finishNewsletters();

        return $error === null;
    }

    /** Marks newsletters "sent" once no delivery is waiting, in progress or retryable. */
    private function finishNewsletters(): void
    {
        DB::update("UPDATE newsletters n SET state = 'sent', sent_at = now() WHERE n.type = 'newsletter' AND n.state = 'sending' AND NOT EXISTS (
            SELECT 1 FROM newsletter_deliveries d WHERE d.newsletter_id = n.id AND (d.state IN ('pending','sending') OR (d.state = 'failed' AND d.attempts < ?)))", [self::MAX_ATTEMPTS]);
    }

    /**
     * Subject (random alternative per delivery) and body for a language, falling back to the default language.
     *
     * @return array{0: string, 1: string}
     */
    private function texts(string $newsletter, string $localeCode, ?string $deliveryId): array
    {
        $default = app(LocaleCatalog::class)->defaultCode();
        $rows = DB::table('newsletter_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.newsletter_id', $newsletter)
            ->whereIn('l.code', [$localeCode, $default])->get(['l.code', 't.subject', 't.random_subjects', 't.body_html'])->keyBy('code');
        $own = $rows[$localeCode] ?? null;
        $text = $own !== null && trim($own->subject) !== '' && trim($own->body_html) !== '' ? $own : ($rows[$default] ?? null);
        if ($text === null) {
            return ['', ''];
        }
        $subjects = NewsletterPlaceholders::subjects($text->subject, $text->random_subjects);

        return [$subjects[$deliveryId === null ? 0 : random_int(0, count($subjects) - 1)], $text->body_html];
    }

    /** @return array<string, string> */
    private function values(string $email, ?string $unsubscribe, ?string $activation, string $locale, bool $confirmation): array
    {
        $company = app(CompanySettings::class)->all();
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $link = fn (?string $url): string => $url === null ? '' : '<a href="'.$e($url).'">'.$e($url).'</a>';
        $activationLink = $link($activation);
        $unsubscribeLink = $link($unsubscribe);
        if ($confirmation) {
            $colors = app(ThemeColors::class)->all();
            $activationLink = $activation === null ? '' : '<a href="'.$e($activation).'" style="display:inline-block; padding:14px 24px; color:'.$colors['on_primary'].'; background:'.$colors['primary'].'; border-radius:6px; font-weight:bold; text-decoration:none">'.$e(__('newsletters.page_button_confirm', [], $locale)).'</a>';
            $unsubscribeLink = $unsubscribe === null ? '' : '<a href="'.$e($unsubscribe).'" style="color:'.$colors['text'].'; font-size:13px; text-decoration:underline">'.$e(__('newsletters.page_button_unsubscribe', [], $locale)).'</a>';
        }
        $address = implode(', ', array_filter([$company['address'] ?? null, trim(($company['postal_code'] ?? '').' '.($company['city'] ?? '')), $company['country_code'] ?? null], fn ($v): bool => trim((string) $v) !== ''));

        return ['unsubscribe_link' => $unsubscribeLink, 'activation_link' => $activationLink, 'subscriber_email' => $e($email),
            'company_name' => $e($company['name'] ?: config('app.name')), 'company_address' => $e($address), 'company_phone' => $e($company['phone'] ?? '')];
    }

    private function document(string $subject, string $body, string $locale, bool $confirmation = false): string
    {
        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $logo = $this->renderer->storeVariables($locale)['html']['store_logo'];
        if ($confirmation) {
            return view('mail.newsletter-confirmation', ['subject' => $subject, 'body' => $body, 'locale' => $locale,
                'brand' => app(StoreBrand::class)->all(), 'logoHtml' => $logo])->render();
        }

        return '<!doctype html><html lang="'.$e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$e($subject).'</title></head><body><div style="padding:24px 0">'.$logo.'</div>'.$body.'</body></html>';
    }
}
