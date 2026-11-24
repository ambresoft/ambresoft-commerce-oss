<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\PrivateVideoStorage;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReelAdministration
{
    public const MAX_VIDEO_BYTES = 67108864;

    /** @var list<array{string,string}> */
    private array $staged = [];

    /** @param array<string,mixed> $input */
    public function save(string $actor, ?string $id, array $input, ?UploadedFile $video = null, ?UploadedFile $cover = null): string
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('reels.manage');
        $this->staged = [];
        try {
            return app(AuditLogger::class)->operation('reel', $actor, fn (): string => DB::transaction(function () use ($id, $input, $video, $cover): string {
                // Share the locale lock so changing active languages cannot race a content save.
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                $existing = $id === null ? null : $this->row($id, (int) ($input['version'] ?? 0));
                $codes = app(LocaleCatalog::class)->active()->pluck('id', 'code');
                $rules = ['status' => ['required', Rule::in(['active', 'disabled'])], 'translations' => ['required', 'array:'.implode(',', $codes->keys()->all())], 'translations.*' => ['array:name'], 'translations.*.name' => ['nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'], 'products' => ['present', 'array', 'max:24'], 'products.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')]];
                $rules['translations.'.app(LocaleCatalog::class)->defaultCode().'.name'] = ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'];
                $data = Validator::make($input, $rules)->validate();
                Validator::make(['video' => $video, 'cover' => $cover], ['video' => [$existing === null ? 'required' : 'nullable', 'file', 'max:65536'], 'cover' => [$existing === null ? 'required' : 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']])->validate();
                $values = ['status' => $data['status'], 'updated_at' => now()];
                if ($video !== null) {
                    $stored = app(PrivateVideoStorage::class)->store($video, 'video', self::MAX_VIDEO_BYTES, 'reels.video_invalid');
                    $this->staged[] = ['video', $stored['storage_key']];
                    $values['video_id'] = DB::table('media_videos')->insertGetId([...$stored, 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($cover !== null) {
                    $stored = app(PrivateImageStorage::class)->store(new ImageUpload($cover->getPathname()));
                    $this->staged[] = ['image', $stored['storage_key']];
                    $values['cover_media_id'] = MediaFileRecord::create($stored)->id;
                }
                if ($existing === null) {
                    $id = (string) DB::table('reels')->insertGetId([...$values, 'created_at' => now()]);
                } else {
                    DB::table('reels')->where('id', $id)->update([...$values, 'lock_version' => $existing->lock_version + 1]);
                }
                foreach ($codes as $code => $locale) {
                    $name = trim($data['translations'][$code]['name'] ?? '');
                    if ($name === '') {
                        DB::table('reel_translations')->where('reel_id', $id)->where('locale_id', $locale)->delete();
                    } else {
                        DB::table('reel_translations')->updateOrInsert(['reel_id' => $id, 'locale_id' => $locale], ['name' => $name]);
                    }
                }
                DB::table('reel_products')->where('reel_id', $id)->delete();
                foreach ($data['products'] as $n => $product) {
                    DB::table('reel_products')->insert(['reel_id' => $id, 'product_id' => $product, 'position' => $n + 1]);
                }

                return $id;
            }));
        } catch (Throwable $e) {
            $this->discardStaged();
            throw $e;
        }
    }

    public function status(string $actor, string $id, int $version, string $status): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('reels.manage');
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'disabled'])]])->validate();
        app(AuditLogger::class)->operation('reel', $actor, fn () => DB::transaction(function () use ($id, $version, $status): void {
            $this->row($id, $version);
            DB::table('reels')->where('id', $id)->update(['status' => $status, 'lock_version' => $version + 1, 'updated_at' => now()]);
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('reels.manage');
        app(AuditLogger::class)->operation('reel', $actor, fn () => DB::transaction(function () use ($id, $version): void {
            $this->row($id, $version);
            // Like banners, detached media follow the existing retention policy; no inline disk deletion.
            DB::table('reels')->where('id', $id)->delete();
            DB::table('storefront_layouts')->increment('lock_version');
        }));
    }

    private function discardStaged(): void
    {
        foreach ($this->staged as [$kind, $key]) {
            $kind === 'video' ? app(PrivateVideoStorage::class)->discard($key) : app(PrivateImageStorage::class)->discard($key);
        }
        $this->staged = [];
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('reels')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('reels.stale')]);
        }

        return $row;
    }
}
