<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\NewsletterPlaceholders;
use App\Order\Infrastructure\EmailHtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Newsletters, reusable templates and autoresponders (`newsletters.manage`). All three keep locale-linked name,
 * subject and HTML body; newsletters add a campaign and an audience (mailing lists and registered customers).
 * Bodies are sanitized to email-safe HTML. Writes use optimistic versions.
 */
final class NewsletterContent
{
    use NewsletterWrites;

    public const TYPES = ['newsletter', 'template', 'autoresponder'];

    public function __construct(private EmailHtmlSanitizer $sanitizer) {}

    /** @param array<string, mixed> $input */
    public function save(string $actor, string $type, ?string $id, ?int $version, array $input): string
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        return $this->audited('newsletter', $actor, fn (): string => DB::transaction(function () use ($actor, $type, $id, $version, $input): string {
            $this->authorize($actor);
            $current = null;
            if ($id !== null) {
                $current = DB::table('newsletters')->where('id', $id)->where('type', $type)->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== $version) {
                    throw $this->stale();
                }
            }
            $data = $this->validated($type, $input, $current);
            $row = ['is_active' => $data['is_active'], 'campaign_id' => $data['campaign_id'], 'updated_by' => $actor, 'updated_at' => now()];
            if ($id === null) {
                $id = (string) DB::table('newsletters')->insertGetId([...$row, 'type' => $type, 'state' => $type === 'newsletter' ? 'draft' : null, 'created_by' => $actor, 'created_at' => now()]);
            } else {
                DB::table('newsletters')->where('id', $id)->update([...$row, 'lock_version' => (int) $version + 1]);
            }
            foreach ($data['translations'] as $localeId => $text) {
                if ($text === null) {
                    DB::table('newsletter_translations')->where('newsletter_id', $id)->where('locale_id', $localeId)->delete();
                } else {
                    DB::table('newsletter_translations')->updateOrInsert(['newsletter_id' => $id, 'locale_id' => $localeId], $text);
                }
            }
            if ($type === 'newsletter') {
                $this->sync('newsletter_audiences', 'list_id', $id, $data['list_ids']);
                $this->sync('newsletter_audience_customers', 'customer_id', $id, $data['customer_ids']);
            }

            return $id;
        }));
    }

    public function setActive(string $actor, string $type, string $id, int $version, bool $active): void
    {
        $this->audited('newsletter', $actor, fn () => DB::transaction(function () use ($actor, $type, $id, $version, $active): void {
            $this->authorize($actor);
            $row = DB::table('newsletters')->where('id', $id)->where('type', $type)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            DB::table('newsletters')->where('id', $id)->update(['is_active' => $active, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    public function delete(string $actor, string $type, string $id, int $version): void
    {
        $this->audited('newsletter', $actor, fn () => DB::transaction(function () use ($actor, $type, $id, $version): void {
            $this->authorize($actor);
            $row = DB::table('newsletters')->where('id', $id)->where('type', $type)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            if ($row->state === 'sending') {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.delete_sending')]);
            }
            $lists = DB::table('newsletter_lists')->where('confirmation_id', $id)->count();
            if ($lists > 0) {
                throw ValidationException::withMessages(['newsletter' => __('newsletters.autoresponder_in_use', ['count' => $lists])]);
            }
            DB::table('newsletters')->where('id', $id)->delete();
        }));
    }

    /** @param list<string> $ids */
    private function sync(string $table, string $column, string $newsletter, array $ids): void
    {
        DB::table($table)->where('newsletter_id', $newsletter)->whereNotIn($column, $ids)->delete();
        foreach ($ids as $value) {
            DB::table($table)->insertOrIgnore(['newsletter_id' => $newsletter, $column => $value]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(string $type, array $input, ?object $current): array
    {
        $errors = [];
        $locales = app(LocaleCatalog::class);
        $translations = [];
        foreach ($locales->active() as $locale) {
            $fields = (array) (($input['translations'] ?? [])[$locale->code] ?? []);
            $key = 'translations.'.$locale->code.'.';
            $name = $this->text($fields['name'] ?? '');
            $subject = $this->text($fields['subject'] ?? '');
            $random = trim(str_replace("\r", '', $this->text($fields['random_subjects'] ?? '')));
            $body = $this->text($fields['body_html'] ?? '');
            $default = $locale->code === $locales->defaultCode();
            if (mb_strlen($name) > 150 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                $errors[$key.'name'] = __('newsletters.text_invalid', ['max' => 150]);
            }
            if (mb_strlen($subject) > 300 || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1) {
                $errors[$key.'subject'] = __('newsletters.text_invalid', ['max' => 300]);
            }
            $lines = $random === '' ? [] : preg_split('/\n/', $random);
            if (count($lines) > 20 || array_filter($lines, fn (string $l): bool => mb_strlen(trim($l)) > 300 || preg_match('/[\x00-\x1f\x7f]/', $l) === 1) !== []) {
                $errors[$key.'random_subjects'] = __('newsletters.random_subjects_invalid');
            }
            $unknown = NewsletterPlaceholders::unknown($name.' '.$subject.' '.$random.' '.$body);
            if ($unknown !== []) {
                $errors[$key.'body_html'] = __('newsletters.unknown_variables', ['variables' => implode(', ', array_map(fn (string $v): string => '{{'.$v.'}}', $unknown))]);
            }
            if (mb_strlen($body) > 200000) {
                $errors[$key.'body_html'] = __('newsletters.body_too_long');
            }
            if ($default) {
                foreach (['name' => $name, 'body_html' => $body] + ($type === 'template' ? [] : ['subject' => $subject]) as $field => $value) {
                    if ($value === '') {
                        $errors[$key.$field] = __('newsletters.default_required');
                    }
                }
            }
            $translations[(int) $locale->id] = $name === '' ? null : [
                'name' => $name, 'subject' => $type === 'template' ? '' : $subject, 'random_subjects' => $type === 'template' ? '' : implode("\n", array_map('trim', $lines)),
                'body_html' => $this->sanitizer->sanitize($body),
            ];
        }
        $campaign = null;
        $lists = $customers = [];
        if ($type === 'newsletter') {
            $campaign = $this->text($input['campaign_id'] ?? '') ?: null;
            if ($campaign !== null && ! DB::table('newsletter_campaigns')->where('id', ctype_digit($campaign) ? $campaign : 0)->exists()) {
                $errors['campaign_id'] = __('newsletters.choice_invalid');
            }
            $lists = $this->existing($input, 'list_ids', 'newsletter_lists', $errors);
            $customers = $this->existing($input, 'customer_ids', 'customers', $errors);
            if (count($customers) > 500) {
                $errors['customer_ids'] = __('newsletters.too_many_customers');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['translations' => $translations, 'campaign_id' => $campaign, 'list_ids' => $lists, 'customer_ids' => $customers,
            'is_active' => $this->flag($input, 'is_active', (bool) ($current->is_active ?? true))];
    }
}
