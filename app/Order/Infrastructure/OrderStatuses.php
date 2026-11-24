<?php

namespace App\Order\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Domain\Service\OrderStockRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Status definitions. Orders, history and emails reference the stable ID, so renaming or recolouring changes nothing
 * recorded. Invariants: exactly one initial status, which is always active (so an active status always exists);
 * referenced and reference statuses are never deleted (disable them instead); changing a stock action affects only
 * future transitions. Writes serialize on one advisory lock.
 */
final class OrderStatuses
{
    private const LOCK = 2142701;

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        return app(AuditLogger::class)->operation('order_status', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $current = $id === null ? null : $this->row($id, (int) $version);
            $data = $this->validated($input, $current);
            if ($current !== null && $current->is_initial && ! $data['is_initial']) {
                throw ValidationException::withMessages(['is_initial' => __('orders.status_initial_required')]);
            }
            if ($data['is_initial'] && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => __('orders.status_initial_active')]);
            }
            if ($data['is_initial']) {
                DB::table('order_statuses')->where('is_initial', true)->when($id !== null, fn ($q) => $q->where('id', '<>', $id))
                    ->update(['is_initial' => false, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            $row = ['color' => $data['color'], 'stock_action' => $data['stock_action'], 'is_active' => $data['is_active'], 'is_initial' => $data['is_initial'], 'is_final' => $data['is_final'], 'sends_invoice' => $data['sends_invoice'], 'customer_can_cancel' => $data['customer_can_cancel'], 'updated_by' => $actor, 'updated_at' => now()];
            if ($id === null) {
                $position = (int) DB::table('order_statuses')->max('position') + 10;
                $id = (string) DB::table('order_statuses')->insertGetId([...$row, 'code' => $data['code'], 'position' => min($position, 99999), 'created_by' => $actor, 'created_at' => now()]);
            } else {
                DB::table('order_statuses')->where('id', $id)->update([...$row, 'lock_version' => (int) $version + 1]);
            }
            foreach ($data['translations'] as $localeId => $text) {
                if ($text === null) {
                    DB::table('order_status_translations')->where('status_id', $id)->where('locale_id', $localeId)->delete();
                } else {
                    DB::table('order_status_translations')->updateOrInsert(['status_id' => $id, 'locale_id' => $localeId], $text);
                }
            }
            // A new status gets usable customer emails right away, in every language.
            OrderEmailDefaults::templates((int) $id, $data['is_initial']);

            return $id;
        }));
    }

    /** Enables or disables a status without other changes. */
    public function setActive(string $actor, string $id, int $version, bool $active): void
    {
        app(AuditLogger::class)->operation('order_status', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $active): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $this->row($id, $version);
            if (! $active && $row->is_initial) {
                throw ValidationException::withMessages(['status' => __('orders.status_initial_active')]);
            }
            DB::table('order_statuses')->where('id', $id)->update(['is_active' => $active, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    /** Swaps the status with its neighbour in display order. */
    public function move(string $actor, string $id, string $direction): void
    {
        app(AuditLogger::class)->operation('order_status', $actor, fn () => DB::transaction(function () use ($actor, $id, $direction): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $ordered = DB::table('order_statuses')->orderBy('position')->orderBy('id')->pluck('id')->map(strval(...))->all();
            $index = array_search($id, $ordered, true);
            $target = $index === false ? false : $index + ($direction === 'up' ? -1 : 1);
            if ($index === false || ! in_array($direction, ['up', 'down'], true) || $target < 0 || $target >= count($ordered)) {
                return;
            }
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $n => $statusId) {
                DB::table('order_statuses')->where('id', $statusId)->where('position', '<>', ($n + 1) * 10)->update(['position' => ($n + 1) * 10, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        }));
    }

    /** @param list<string> $ids Every status, in the new display order. */
    public function reorder(string $actor, array $ids): void
    {
        app(AuditLogger::class)->operation('order_status', $actor, fn () => DB::transaction(function () use ($actor, $ids): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $current = DB::table('order_statuses')->pluck('id')->map(strval(...))->all();
            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $current) !== [] || array_diff($current, $ids) !== []) {
                throw ValidationException::withMessages(['ids' => __('orders.stale')]);
            }
            foreach ($ids as $n => $id) {
                DB::table('order_statuses')->where('id', $id)->where('position', '<>', ($n + 1) * 10)
                    ->update(['position' => ($n + 1) * 10, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        }));
    }

    /** Only unused custom statuses can be deleted; used ones keep history readable and are disabled instead. */
    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('order_status', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $this->row($id, $version);
            if ($row->is_system || $row->is_initial) {
                throw ValidationException::withMessages(['status' => __('orders.status_protected')]);
            }
            $used = DB::table('orders')->where('status_id', $id)->count()
                + DB::table('order_status_history')->where('to_status_id', $id)->orWhere('from_status_id', $id)->count();
            if ($used > 0) {
                throw ValidationException::withMessages(['status' => __('orders.status_in_use')]);
            }
            DB::table('order_statuses')->where('id', $id)->delete();
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{code: string, color: string, stock_action: string, is_active: bool, is_initial: bool, is_final: bool, sends_invoice: bool, customer_can_cancel: bool, translations: array<int, array{name: string, description: string}|null>}
     */
    private function validated(array $input, ?object $current): array
    {
        $errors = [];
        $text = fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $bool = fn (string $field, bool $default): bool => array_key_exists($field, $input) ? filter_var($input[$field], FILTER_VALIDATE_BOOLEAN) : $default;
        $code = $current->code ?? strtolower($text($input['code'] ?? ''));
        if ($current === null) {
            if (preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $code) !== 1) {
                $errors['code'] = __('orders.status_code_invalid');
            } elseif (DB::table('order_statuses')->where('code', $code)->exists()) {
                $errors['code'] = __('orders.status_code_taken');
            }
        }
        $color = strtolower($text($input['color'] ?? ''));
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            $errors['color'] = __('orders.status_color_invalid');
        }
        $stock = $text($input['stock_action'] ?? '');
        if (! in_array($stock, OrderStockRules::ACTIONS, true)) {
            $errors['stock_action'] = __('orders.choice_invalid');
        }
        $locales = app(LocaleCatalog::class);
        $translations = [];
        foreach ($locales->active() as $locale) {
            $fields = (array) (($input['translations'] ?? [])[$locale->code] ?? []);
            $name = $text($fields['name'] ?? '');
            $description = $text($fields['description'] ?? '');
            $key = 'translations.'.$locale->code.'.';
            if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                $errors[$key.'name'] = __('orders.status_name_invalid');
            }
            if (mb_strlen($description) > 500) {
                $errors[$key.'description'] = __('orders.text_too_long', ['max' => 500]);
            }
            if ($name === '' && $locale->code === $locales->defaultCode()) {
                $errors[$key.'name'] = __('orders.status_name_required');
            }
            $translations[(int) $locale->id] = $name === '' ? null : ['name' => $name, 'description' => $description];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['code' => $code, 'color' => $color, 'stock_action' => $stock, 'is_active' => $bool('is_active', (bool) ($current->is_active ?? true)), 'is_initial' => $bool('is_initial', (bool) ($current->is_initial ?? false)), 'is_final' => $bool('is_final', (bool) ($current->is_final ?? false)), 'sends_invoice' => $bool('sends_invoice', (bool) ($current->sends_invoice ?? false)), 'customer_can_cancel' => $bool('customer_can_cancel', (bool) ($current->customer_can_cancel ?? false)), 'translations' => $translations];
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('order_statuses')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('orders.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('orders.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('order-statuses.manage');
    }
}
