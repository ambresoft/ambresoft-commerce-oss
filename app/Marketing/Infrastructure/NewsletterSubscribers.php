<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Newsletter subscribers and their mailing-list memberships. `subscribe()` is the single entry point for creating
 * memberships (administration now, the storefront later): a membership is "subscribed" when it exists and receives
 * list messages once confirmed. Unconfirmed memberships trigger the list's confirmation autoresponder, which carries
 * the %ACTIVATION_LINK%. Administration (`subscribers.manage`) adds, edits, imports and deletes.
 */
final class NewsletterSubscribers
{
    use NewsletterWrites;

    public function __construct(private NewsletterDelivery $deliveries) {}

    /**
     * @param  list<string>  $listIds
     * @return array{id: string, created: bool, joined: int}
     */
    public function subscribe(string $email, array $listIds, string $source, bool $confirmed, ?string $localeCode = null, ?string $customerId = null): array
    {
        $email = $this->email($email) ?? throw ValidationException::withMessages(['email' => __('newsletters.email_invalid')]);
        $localeId = $localeCode === null ? null : DB::table('locales')->where('code', $localeCode)->where('is_active', true)->value('id');
        $listIds = DB::table('newsletter_lists')->whereIn('id', $listIds)->where('status', 'active')->pluck('id')->map(strval(...))->all();

        return DB::transaction(function () use ($email, $listIds, $source, $confirmed, $localeId, $customerId): array {
            $row = DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->lockForUpdate()->first();
            $created = $row === null;
            if ($created) {
                $id = (string) DB::table('newsletter_subscribers')->insertGetId(['email' => $email, 'locale_id' => $localeId, 'customer_id' => $customerId, 'status' => 'active', 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (string) $row->id;
                if ($row->status !== 'active' || ($row->locale_id === null && $localeId !== null)) {
                    DB::table('newsletter_subscribers')->where('id', $id)->update(['status' => 'active', 'unsubscribed_at' => null, 'locale_id' => $row->locale_id ?? $localeId, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                }
            }
            $joined = 0;
            foreach ($listIds as $list) {
                $inserted = DB::affectingStatement('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id, confirmed_at, created_at) VALUES (?, ?, ?, now()) ON CONFLICT DO NOTHING', [$list, $id, $confirmed ? now() : null]);
                if ($inserted > 0) {
                    $joined++;
                    if (! $confirmed) {
                        $this->deliveries->queueConfirmation($list, $id);
                    }
                }
            }

            return ['id' => $id, 'created' => $created, 'joined' => $joined];
        });
    }

    /** @param array<string, mixed> $input */
    public function create(string $actor, array $input): string
    {
        return $this->audited('newsletter_subscriber', $actor, function () use ($actor, $input): string {
            $this->authorize($actor, 'subscribers.manage');
            [$lists, $locale] = $this->listsAndLocale($input);
            $email = $this->email($this->text($input['email'] ?? ''));
            if ($email === null) {
                throw ValidationException::withMessages(['email' => __('newsletters.email_invalid')]);
            }
            if (DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
                throw ValidationException::withMessages(['email' => __('newsletters.subscriber_exists')]);
            }

            return $this->subscribe($email, $lists, 'admin', $this->flag($input, 'confirmed', true), $locale)['id'];
        });
    }

    /**
     * Adds many addresses at once (one per line or separated by commas/semicolons) to the chosen lists.
     *
     * @param  array<string, mixed>  $input
     * @return array{added: int, existing: int, invalid: int}
     */
    public function import(string $actor, array $input): array
    {
        return $this->audited('newsletter_subscriber', $actor, function () use ($actor, $input): array {
            $this->authorize($actor, 'subscribers.manage');
            [$lists, $locale] = $this->listsAndLocale($input);
            $tokens = preg_split('/[\s,;]+/u', $this->text($input['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($tokens) > 5000) {
                throw ValidationException::withMessages(['emails' => __('newsletters.import_too_many')]);
            }
            $result = ['added' => 0, 'existing' => 0, 'invalid' => 0];
            $seen = [];
            foreach ($tokens as $token) {
                $email = $this->email($token);
                if ($email === null) {
                    $result['invalid']++;

                    continue;
                }
                if (isset($seen[mb_strtolower($email)])) {
                    continue;
                }
                $seen[mb_strtolower($email)] = true;
                $done = $this->subscribe($email, $lists, 'import', $this->flag($input, 'confirmed', true), $locale);
                $result[$done['created'] ? 'added' : 'existing']++;
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(string $actor, string $id, int $version, array $input): void
    {
        $this->audited('newsletter_subscriber', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $input): void {
            $this->authorize($actor, 'subscribers.manage');
            $row = DB::table('newsletter_subscribers')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            $errors = [];
            $email = $this->email($this->text($input['email'] ?? $row->email));
            if ($email === null) {
                $errors['email'] = __('newsletters.email_invalid');
            } elseif (DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->where('id', '<>', $id)->exists()) {
                $errors['email'] = __('newsletters.subscriber_exists');
            }
            $status = $this->text($input['status'] ?? $row->status);
            if (! in_array($status, ['active', 'unsubscribed'], true)) {
                $errors['status'] = __('newsletters.choice_invalid');
            }
            $localeId = null;
            $code = $this->text($input['locale'] ?? '');
            if ($code !== '') {
                $localeId = DB::table('locales')->where('code', $code)->where('is_active', true)->value('id');
                if ($localeId === null) {
                    $errors['locale'] = __('newsletters.choice_invalid');
                }
            }
            // memberships: [list_id => confirmed bool]; only existing lists are accepted.
            $memberships = is_array($input['memberships'] ?? null) ? $input['memberships'] : [];
            $valid = DB::table('newsletter_lists')->whereIn('id', array_filter(array_keys($memberships), fn ($k): bool => ctype_digit((string) $k)))->pluck('id')->map(strval(...))->all();
            if (count($valid) !== count($memberships)) {
                $errors['memberships'] = __('newsletters.choice_invalid');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            DB::table('newsletter_subscribers')->where('id', $id)->update([
                'email' => $email, 'status' => $status, 'locale_id' => $localeId, 'unsubscribed_at' => $status === 'unsubscribed' ? ($row->unsubscribed_at ?? now()) : null,
                'lock_version' => $version + 1, 'updated_at' => now(),
            ]);
            DB::table('newsletter_list_subscribers')->where('subscriber_id', $id)->whereNotIn('list_id', $valid)->delete();
            foreach ($memberships as $list => $confirmed) {
                $confirmed = filter_var($confirmed, FILTER_VALIDATE_BOOLEAN);
                $exists = DB::table('newsletter_list_subscribers')->where('list_id', $list)->where('subscriber_id', $id)->first();
                if ($exists === null) {
                    DB::table('newsletter_list_subscribers')->insert(['list_id' => $list, 'subscriber_id' => $id, 'confirmed_at' => $confirmed ? now() : null, 'created_at' => now()]);
                } elseif ($confirmed !== ($exists->confirmed_at !== null)) {
                    DB::table('newsletter_list_subscribers')->where('list_id', $list)->where('subscriber_id', $id)->update(['confirmed_at' => $confirmed ? now() : null]);
                }
            }
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        $this->audited('newsletter_subscriber', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor, 'subscribers.manage');
            $row = DB::table('newsletter_subscribers')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw $this->stale();
            }
            DB::table('newsletter_subscribers')->where('id', $id)->delete();
        }));
    }

    /** Public (signed link): confirms one membership; false when the membership no longer exists. */
    public function confirm(string $subscriberId, string $listId): bool
    {
        $member = DB::table('newsletter_list_subscribers')->where('list_id', $listId)->where('subscriber_id', $subscriberId);
        if (! (clone $member)->exists()) {
            return false;
        }
        $member->whereNull('confirmed_at')->update(['confirmed_at' => now()]);

        return true;
    }

    /** Public (signed link): leaves one list, or every list when none is given; a subscriber with no list left is unsubscribed. */
    public function unsubscribe(string $subscriberId, ?string $listId): void
    {
        DB::transaction(function () use ($subscriberId, $listId): void {
            $q = DB::table('newsletter_list_subscribers')->where('subscriber_id', $subscriberId);
            if ($listId !== null) {
                $q->where('list_id', $listId);
            }
            $q->delete();
            if ($listId === null || ! DB::table('newsletter_list_subscribers')->where('subscriber_id', $subscriberId)->exists()) {
                DB::table('newsletter_subscribers')->where('id', $subscriberId)->where('status', 'active')
                    ->update(['status' => 'unsubscribed', 'unsubscribed_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
        });
    }

    private function email(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: list<string>, 1: ?string}
     */
    private function listsAndLocale(array $input): array
    {
        $errors = [];
        $lists = $this->existing($input, 'list_ids', 'newsletter_lists', $errors);
        $locale = $this->text($input['locale'] ?? '') ?: null;
        if ($locale !== null && ! in_array($locale, app(LocaleCatalog::class)->active()->pluck('code')->all(), true)) {
            $errors['locale'] = __('newsletters.choice_invalid');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$lists, $locale];
    }
}
