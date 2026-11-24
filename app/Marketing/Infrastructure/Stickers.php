<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\ImageAttachment;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Stickers
{
    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('stickers.manage');
        $images = app(ImageAttachment::class);
        try {
            return app(AuditLogger::class)->operation('sticker', $actor, fn (): string => DB::transaction(function () use ($id, $input, $images): string {
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                if ($id !== null) {
                    $this->current($id, (int) ($input['version'] ?? 0));
                }
                $data = app(StickerInput::class)->validate($input);
                $fields = array_intersect_key($data, array_flip(StickerInput::FIELDS));
                $fields['image_media_id'] = $data['style'] === 'image' ? ($data['image_media_id'] ?? null) : null;
                if ($data['style'] === 'image' && isset($data['image'])) {
                    $fields['image_media_id'] = $images->store($data['image']);
                }
                if ($data['style'] === 'image' && $fields['image_media_id'] === null) {
                    throw ValidationException::withMessages(['image' => __('stickers.image_required')]);
                }
                $fields['filter_slug'] = $this->filterSlug($data['name'], $id);
                $timezone = app(GeneralSettings::class)->all()['timezone'];
                foreach (['starts_at', 'ends_at'] as $field) {
                    $fields[$field] = $data[$field] === null ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$field], $timezone)->utc();
                }
                if ($id === null) {
                    $id = (string) DB::table('stickers')->insertGetId([...$fields, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('stickers')->where('id', $id)->update([...$fields, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                }
                foreach (app(LocaleCatalog::class)->active() as $locale) {
                    $text = $data['translations'][$locale->code];
                    DB::table('sticker_translations')->updateOrInsert(['sticker_id' => $id, 'locale_id' => $locale->id], ['text' => $text['text'], 'short_text' => $text['short_text'] ?? '', 'tooltip' => $text['tooltip'] ?? '']);
                }
                DB::table('sticker_conditions')->where('sticker_id', $id)->delete();
                foreach ($data['conditions'] as $c) {
                    DB::table('sticker_conditions')->insert(['sticker_id' => $id, ...$c]);
                }
                $before = DB::table('product_stickers')->where('sticker_id', $id)->pluck('product_id')->map(strval(...))->all();
                $wanted = array_map(strval(...), $data['products']);
                DB::table('product_stickers')->where('sticker_id', $id)->whereNotIn('product_id', $wanted)->delete();
                foreach (array_diff($wanted, $before) as $product) {
                    DB::table('product_stickers')->insert(['sticker_id' => $id, 'product_id' => $product]);
                }
                // Product editors also write this relationship. Make stale full-product saves reject rather than undo it.
                $this->bumpProducts([...array_diff($before, $wanted), ...array_diff($wanted, $before)]);

                return $id;
            }));
        } finally {
            $images->discardUncommitted();
        }
    }

    /** @param list<string> $ids */
    private function bumpProducts(array $ids): void
    {
        DB::table('products')->whereIn('id', $ids)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    private function current(string $id, int $version): object
    {
        $row = DB::table('stickers')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('stickers.stale')]);
        }

        return $row;
    }

    public function status(string $actor, string $id, int $version, string $status): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('stickers.manage');
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'disabled'])]])->validate();
        app(AuditLogger::class)->operation('sticker', $actor, fn () => DB::transaction(function () use ($id, $version, $status): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $this->current($id, $version);
            DB::table('stickers')->where('id', $id)->update(['status' => $status, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('stickers.manage');
        app(AuditLogger::class)->operation('sticker', $actor, fn () => DB::transaction(function () use ($id, $version): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $this->current($id, $version);
            $this->bumpProducts(DB::table('product_stickers')->where('sticker_id', $id)->pluck('product_id')->map(strval(...))->all());
            DB::table('stickers')->where('id', $id)->delete();
        }));
    }

    /** Called within the product's locked, authorized save.
     * @param list<string> $ids */
    public function assignProduct(string $actor, string $product, array $ids): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('stickers.manage');
        Validator::make(['sticker_ids' => $ids], ['sticker_ids' => ['array', 'max:100'], 'sticker_ids.*' => ['integer', 'distinct', Rule::exists('stickers', 'id')->where('type', 'manual')]])->validate();
        $current = DB::table('product_stickers')->where('product_id', $product)->pluck('sticker_id')->map(strval(...))->all();
        $ids = array_map(strval(...), $ids);
        DB::table('product_stickers')->where('product_id', $product)->whereNotIn('sticker_id', $ids)->delete();
        foreach (array_diff($ids, $current) as $id) {
            DB::table('product_stickers')->insert(['product_id' => $product, 'sticker_id' => $id]);
        }
        DB::table('stickers')->whereIn('id', [...array_diff($ids, $current), ...array_diff($current, $ids)])->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $input */
    public function limits(string $actor, array $input): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('stickers.manage');
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'card_limit' => ['required', 'integer', 'between:0,20'], 'detail_limit' => ['required', 'integer', 'between:0,20']])->validate();
        app(AuditLogger::class)->operation('sticker_settings', $actor, fn () => DB::transaction(function () use ($data): void {
            $updated = DB::table('sticker_display_settings')->where('id', 1)->where('lock_version', $data['version'])->update(['card_limit' => $data['card_limit'], 'detail_limit' => $data['detail_limit'], 'lock_version' => DB::raw('lock_version + 1')]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['version' => __('stickers.stale')]);
            }
        }));
    }

    /** The readable shopping-filter address name (?product-label=…), from the sticker name and unique among stickers.
     * Saves are serialized by the sticker advisory lock. */
    private function filterSlug(string $name, ?string $id): string
    {
        $base = substr(Str::slug($name), 0, 70) ?: 'label';
        $slug = $base;
        for ($n = 2; DB::table('stickers')->where('filter_slug', $slug)->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
