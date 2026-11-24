<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Issuing, editing, changing the status of and deleting gift cards. A card's face value and balance are in the primary
 * currency of the moment it is issued and are never converted. Staff cannot change the amount once any of it has been
 * used (the ledger would stop adding up); the balance changes only through redemption. Deleted cards are archived: their
 * code stays reserved and their payments and history stay readable, but they can no longer be used.
 */
final class GiftCards
{
    public function __construct(private GiftCardMail $mail) {}

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('giftcards.manage');

        return app(AuditLogger::class)->operation('gift_card', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $input): string {
            app(AuditContext::class)->actor($actor);
            $current = null;
            if ($id !== null) {
                $current = DB::table('gift_cards')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== (int) ($input['version'] ?? 0)) {
                    throw ValidationException::withMessages(['version' => __('giftcards.stale')]);
                }
            }
            foreach (['recipient_name', 'sender_name', 'recipient_email', 'amount', 'message'] as $field) {
                if (is_string($input[$field] ?? null)) {
                    $input[$field] = trim($input[$field]);
                }
            }
            if (($input['message'] ?? '') === '') {
                $input['message'] = null;
            }
            $currency = $current === null ? app(CurrencyRepository::class)->currentPrimary() : null;
            $decimals = $current === null ? $currency->decimals : (int) $current->currency_decimals;
            $decimal = $decimals === 0 ? 'regex:/^\d{1,14}$/D' : 'regex:/^\d{1,14}(?:\.\d{1,'.$decimals.'})?$/D';
            $locales = app(LocaleCatalog::class)->active()->pluck('code')->all();
            $data = Validator::make($input, [
                'recipient_name' => ['required', 'string', 'max:128'],
                'sender_name' => ['required', 'string', 'max:128'],
                'recipient_email' => ['required', 'string', 'email:rfc', 'max:254'],
                'message' => ['nullable', 'string', 'max:2000'],
                'amount' => ['required', 'string', $decimal, 'gt:0', 'max:99999999999999'],
                'locale' => ['required', 'string', Rule::in($locales)],
                'status' => ['required', Rule::in($current?->status === 'used' ? ['used'] : GiftCardRules::MANUAL)],
                'notify' => ['required', 'boolean'],
            ], [], array_combine(array_keys($input), array_map(fn (string $f): string => __('giftcards.'.$f), array_keys($input))))->validate();
            $localeId = (int) DB::table('locales')->where('code', $data['locale'])->value('id');
            $notify = (bool) $data['notify'];
            $fields = [
                'recipient_name' => $data['recipient_name'], 'sender_name' => $data['sender_name'], 'recipient_email' => $data['recipient_email'],
                'message' => $data['message'], 'locale_id' => $localeId, 'notify' => $notify,
            ];
            if ($current === null) {
                $id = $this->issue($actor, $fields, $currency->code, $decimals, $data['amount'], $data['status']);
                if ($notify && $data['status'] === 'active') {
                    $this->mail->queue($id, 'delivery', $actor);
                }

                return $id;
            }
            $untouched = Decimal::compare($current->amount, $current->balance) === 0;
            if (Decimal::compare($current->amount, $data['amount']) !== 0) {
                if (! $untouched) {
                    throw ValidationException::withMessages(['amount' => __('giftcards.amount_locked')]);
                }
                $fields += ['amount' => $data['amount'], 'balance' => $data['amount']];
                DB::table('gift_card_transactions')->where('gift_card_id', $id)->where('type', 'issued')->update(['amount' => $data['amount'], 'balance_after' => $data['amount']]);
            }
            DB::table('gift_cards')->where('id', $id)->update([...$fields, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($data['status'] !== $current->status) {
                $this->transition($actor, $current, $data['status'], $notify);
            }

            return $id;
        }));
    }

    /** Status change from the list. "Used" is never chosen; it follows the balance. */
    public function setStatus(string $actor, string $id, int $version, string $status, bool $notify): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('giftcards.manage');
        app(AuditLogger::class)->operation('gift_card', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $status, $notify): void {
            app(AuditContext::class)->actor($actor);
            $current = DB::table('gift_cards')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($current === null || (int) $current->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('giftcards.stale')]);
            }
            DB::table('gift_cards')->where('id', $id)->update(['notify' => $notify, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($status !== $current->status) {
                $this->transition($actor, $current, $status, $notify);
            }
        }));
    }

    /** Archive cards. Any balance left on them can no longer be used.
     * @param  list<array{id: string, version: int}>  $rows
     */
    public function delete(string $actor, array $rows): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('giftcards.delete');
        app(AuditLogger::class)->operation('gift_card', $actor, fn () => DB::transaction(function () use ($actor, $rows): void {
            app(AuditContext::class)->actor($actor);
            foreach ($rows as $row) {
                $current = DB::table('gift_cards')->where('id', $row['id'])->whereNull('deleted_at')->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== (int) $row['version']) {
                    throw ValidationException::withMessages(['version' => __('giftcards.stale')]);
                }
                DB::table('gift_cards')->where('id', $row['id'])->update(['deleted_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
        }));
    }

    /** @param array<string, mixed> $fields */
    private function issue(string $actor, array $fields, string $currency, int $decimals, string $amount, string $status): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $id = (string) DB::transaction(fn () => DB::table('gift_cards')->insertGetId([
                    ...$fields, 'number' => GiftCardRules::code(fn (int $bound): int => random_int(0, $bound - 1)), 'currency_code' => $currency, 'currency_decimals' => $decimals,
                    'amount' => $amount, 'balance' => $amount, 'status' => $status, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]));
                DB::table('gift_card_transactions')->insert(['gift_card_id' => $id, 'type' => 'issued', 'amount' => $amount, 'balance_after' => $amount, 'actor_id' => $actor, 'created_at' => now()]);

                return $id;
            } catch (UniqueConstraintViolationException) {
                // A code that already exists: draw another one.
            }
        }

        throw ValidationException::withMessages(['code' => __('giftcards.code_failed')]);
    }

    /** The caller holds the row lock and has checked the version. */
    private function transition(string $actor, object $card, string $to, bool $notify): void
    {
        if (! GiftCardRules::canChange($card->status, $to)) {
            throw ValidationException::withMessages(['status' => __('giftcards.status_locked')]);
        }
        DB::table('gift_cards')->where('id', $card->id)->update(['status' => $to, 'updated_at' => now()]);
        if ($notify && $to === 'active') {
            $this->mail->queue((string) $card->id, 'delivery', $actor);
        } elseif ($notify && $to === 'cancelled') {
            $this->mail->queue((string) $card->id, 'cancelled', $actor);
        }
    }
}
