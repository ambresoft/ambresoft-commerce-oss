<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mailing lists (`newsletters.manage`): locale-linked name and description, optional sender, confirmation
 * autoresponder and the storefront display flags. Campaigns are plain groupings of newsletters for reporting.
 */
final class NewsletterLists
{
    use NewsletterWrites;

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        return $this->audited('newsletter_list', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            $current = null;
            if ($id !== null) {
                $current = DB::table('newsletter_lists')->where('id', $id)->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== $version) {
                    throw $this->stale();
                }
            }
            $errors = [];
            $locales = app(LocaleCatalog::class);
            $translations = [];
            foreach ($locales->active() as $locale) {
                $fields = (array) (($input['translations'] ?? [])[$locale->code] ?? []);
                $key = 'translations.'.$locale->code.'.';
                $name = $this->text($fields['name'] ?? '');
                $description = $this->text($fields['description'] ?? '');
                if (mb_strlen($name) > 150 || mb_strlen($description) > 500 || preg_match('/[\x00-\x1f\x7f]/', $name.$description) === 1) {
                    $errors[$key.'name'] = __('newsletters.text_invalid', ['max' => 150]);
                }
                if ($name === '' && $locale->code === $locales->defaultCode()) {
                    $errors[$key.'name'] = __('newsletters.default_required');
                }
                $translations[(int) $locale->id] = $name === '' ? null : ['name' => $name, 'description' => $description];
            }
            $status = $this->text($input['status'] ?? ($current->status ?? 'active'));
            if (! in_array($status, ['active', 'disabled'], true)) {
                $errors['status'] = __('newsletters.choice_invalid');
            }
            $sender = [];
            foreach (['from_name' => 100, 'from_email' => 254, 'reply_to' => 254] as $field => $max) {
                $value = $this->text($input[$field] ?? '');
                if ($value !== '' && (mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || ($field !== 'from_name' && filter_var($value, FILTER_VALIDATE_EMAIL) === false))) {
                    $errors[$field] = __($field === 'from_name' ? 'newsletters.text_invalid' : 'newsletters.email_invalid', ['max' => $max]);
                }
                $sender[$field] = $value === '' ? null : $value;
            }
            $confirmation = $this->text($input['confirmation_id'] ?? '') ?: null;
            if ($confirmation !== null && ! DB::table('newsletters')->where('id', ctype_digit($confirmation) ? $confirmation : 0)->where('type', 'autoresponder')->exists()) {
                $errors['confirmation_id'] = __('newsletters.choice_invalid');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $row = [...$sender, 'status' => $status, 'confirmation_id' => $confirmation, 'show_at_checkout' => $this->flag($input, 'show_at_checkout', (bool) ($current->show_at_checkout ?? false)),
                'show_on_registration' => $this->flag($input, 'show_on_registration', (bool) ($current->show_on_registration ?? false)), 'updated_by' => $actor, 'updated_at' => now()];
            if ($id === null) {
                $id = (string) DB::table('newsletter_lists')->insertGetId([...$row, 'created_by' => $actor, 'created_at' => now()]);
            } else {
                DB::table('newsletter_lists')->where('id', $id)->update([...$row, 'lock_version' => (int) $version + 1]);
            }
            foreach ($translations as $localeId => $text) {
                if ($text === null) {
                    DB::table('newsletter_list_translations')->where('list_id', $id)->where('locale_id', $localeId)->delete();
                } else {
                    DB::table('newsletter_list_translations')->updateOrInsert(['list_id' => $id, 'locale_id' => $localeId], $text);
                }
            }

            return $id;
        }));
    }

    /** Deleting a list removes its memberships (subscribers stay) and its place in newsletter audiences. */
    public function delete(string $actor, string $id, int $version): void
    {
        $this->audited('newsletter_list', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            $row = DB::table('newsletter_lists')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            DB::table('newsletter_lists')->where('id', $id)->delete();
        }));
    }

    /** @param array<string, mixed> $input */
    public function saveCampaign(string $actor, ?string $id, ?int $version, array $input): string
    {
        return $this->audited('newsletter_campaign', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            $current = null;
            if ($id !== null) {
                $current = DB::table('newsletter_campaigns')->where('id', $id)->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== $version) {
                    throw $this->stale();
                }
            }
            $name = $this->text($input['name'] ?? '');
            $status = $this->text($input['status'] ?? ($current->status ?? 'active'));
            $errors = [];
            if ($name === '' || mb_strlen($name) > 150 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                $errors['name'] = __('newsletters.text_invalid', ['max' => 150]);
            } elseif (DB::table('newsletter_campaigns')->whereRaw('lower(name) = lower(?)', [$name])->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $errors['name'] = __('newsletters.campaign_name_taken');
            }
            if (! in_array($status, ['active', 'disabled'], true)) {
                $errors['status'] = __('newsletters.choice_invalid');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $row = ['name' => $name, 'status' => $status, 'updated_by' => $actor, 'updated_at' => now()];
            if ($id === null) {
                return (string) DB::table('newsletter_campaigns')->insertGetId([...$row, 'created_by' => $actor, 'created_at' => now()]);
            }
            DB::table('newsletter_campaigns')->where('id', $id)->update([...$row, 'lock_version' => (int) $version + 1]);

            return $id;
        }));
    }

    /** Newsletters of a deleted campaign are kept and simply lose the grouping. */
    public function deleteCampaign(string $actor, string $id, int $version): void
    {
        $this->audited('newsletter_campaign', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            $row = DB::table('newsletter_campaigns')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            DB::table('newsletter_campaigns')->where('id', $id)->delete();
        }));
    }
}
