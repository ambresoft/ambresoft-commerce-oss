<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Domain\Service\AvailabilityRules;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AvailabilityStatuses
{
    /** @return list<array{id: string, name: string, position: int, version: int, default_in_stock: bool, default_out_of_stock: bool, usage: int}> */
    public function options(): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)->id;
        $current = $locales->firstWhere('code', app()->getLocale())->id ?? $default;

        return DB::table('availability_statuses as s')
            ->leftJoin('availability_status_translations as c', fn ($j) => $j->on('c.status_id', '=', 's.id')->where('c.locale_id', $current))
            ->leftJoin('availability_status_translations as d', fn ($j) => $j->on('d.status_id', '=', 's.id')->where('d.locale_id', $default))
            ->select('s.*')->selectRaw("coalesce(c.name, d.name, '#' || s.id) as name")
            ->selectSub(DB::table('products')->selectRaw('count(*)')->where(fn ($q) => $q->whereColumn('in_stock_status_id', 's.id')->orWhereColumn('out_of_stock_status_id', 's.id')), 'usage')
            ->orderBy('s.position')->orderBy('s.id')->get()->map(fn ($s): array => ['id' => (string) $s->id, 'name' => $s->name, 'position' => (int) $s->position, 'version' => (int) $s->lock_version, 'default_in_stock' => (bool) $s->default_in_stock, 'default_out_of_stock' => (bool) $s->default_out_of_stock, 'usage' => (int) $s->usage])->all();
    }

    /** @return array<string,mixed> */
    public function editor(string $id): array
    {
        $row = collect($this->options())->firstWhere('id', $id) ?? abort(404);
        $translations = DB::table('availability_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.status_id', $id)->get(['l.code', 't.name'])->mapWithKeys(fn ($t): array => [$t->code => ['name' => $t->name]])->all();

        return [...$row, 'translations' => (object) $translations];
    }

    /** @param array<string,mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        return app(AuditLogger::class)->operation('availability_status', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $input): string {
            $this->authorize($actor);
            $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
            $rules = ['version' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'position' => ['required', 'integer', 'between:0,2147483647'], 'default_in_stock' => ['required', 'boolean'], 'default_out_of_stock' => ['required', 'boolean'], 'translations' => ['required', 'array:'.implode(',', $codes)]];
            foreach ($codes as $code) {
                $rules['translations.'.$code] = ['required', 'array:name'];
                $rules['translations.'.$code.'.name'] = ['required', 'string', 'max:'.AvailabilityRules::NAME_MAX, 'not_regex:/[\x00-\x1f\x7f]/'];
            }
            $data = Validator::make($input, $rules)->validate();
            $row = $id === null ? null : $this->row($id, (int) $data['version']);
            if ($id === null && DB::table('availability_statuses')->count() >= 1000) {
                throw ValidationException::withMessages(['status' => __('availability.limit')]);
            }
            foreach (['default_in_stock', 'default_out_of_stock'] as $field) {
                if ($row !== null && $row->{$field} && ! $data[$field]) {
                    throw ValidationException::withMessages([$field => __('availability.default_required')]);
                }
                if ($data[$field]) {
                    DB::table('availability_statuses')->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->where($field, true)->update([$field => false, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                }
            }
            $values = ['position' => $data['position'], 'default_in_stock' => (bool) $data['default_in_stock'], 'default_out_of_stock' => (bool) $data['default_out_of_stock'], 'updated_at' => now()];
            if ($id === null) {
                $id = (string) DB::table('availability_statuses')->insertGetId([...$values, 'created_at' => now()]);
            } else {
                DB::table('availability_statuses')->where('id', $id)->update([...$values, 'lock_version' => (int) $row->lock_version + 1]);
            }
            foreach (app(LocaleCatalog::class)->active() as $locale) {
                DB::table('availability_status_translations')->updateOrInsert(['status_id' => $id, 'locale_id' => $locale->id], ['name' => trim($data['translations'][$locale->code]['name'])]);
            }

            return $id;
        }));
    }

    public function delete(string $actor, string $id, int $version, ?string $replacement): void
    {
        app(AuditLogger::class)->operation('availability_status', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $replacement): void {
            $this->authorize($actor);
            $row = $this->row($id, $version);
            $used = DB::table('products')->where('in_stock_status_id', $id)->orWhere('out_of_stock_status_id', $id)->exists();
            Validator::make(['replacement' => $replacement], ['replacement' => [($used || $row->default_in_stock || $row->default_out_of_stock) ? 'required' : 'nullable', 'integer', Rule::exists('availability_statuses', 'id'), Rule::notIn([$id])]])->validate();
            if ($replacement !== null) {
                $target = DB::table('availability_statuses')->where('id', $replacement)->lockForUpdate()->firstOrFail();
                DB::table('products')->where(fn ($q) => $q->where('in_stock_status_id', $id)->orWhere('out_of_stock_status_id', $id))
                    ->update(['in_stock_status_id' => DB::raw('CASE WHEN in_stock_status_id = '.(int) $id.' THEN '.(int) $replacement.' ELSE in_stock_status_id END'), 'out_of_stock_status_id' => DB::raw('CASE WHEN out_of_stock_status_id = '.(int) $id.' THEN '.(int) $replacement.' ELSE out_of_stock_status_id END'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                DB::table('availability_statuses')->where('id', $id)->update(['default_in_stock' => false, 'default_out_of_stock' => false]);
                DB::table('availability_statuses')->where('id', $replacement)->update(['default_in_stock' => (bool) $target->default_in_stock || (bool) $row->default_in_stock, 'default_out_of_stock' => (bool) $target->default_out_of_stock || (bool) $row->default_out_of_stock, 'lock_version' => (int) $target->lock_version + 1, 'updated_at' => now()]);
            }
            DB::table('availability_statuses')->where('id', $id)->delete();
        }));
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('availability_statuses')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('availability.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('availability-statuses.manage');
        app(AuditContext::class)->actor($actor);
    }
}
