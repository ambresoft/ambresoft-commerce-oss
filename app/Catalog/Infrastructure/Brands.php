<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveBrandData;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Brand is the authoritative product association. Descriptive feature assignments are never inferred or synced. */
final class Brands
{
    /** @return array<string,mixed> */
    public function rules(?string $id): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $rules = ['code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]{0,79}$/D', Rule::unique('brands', 'code')->ignore($id)],
            'files_submitted' => ['sometimes', 'boolean'], 'files' => ['sometimes', 'array', 'max:50'], 'files.*' => ['array:id,display_name,replacement'],
            'files.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'files.*.display_name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'], 'files.*.replacement' => ['nullable', 'integer', 'min:0', 'max:9'],
            'status' => ['required', Rule::in(['active', 'disabled'])], 'position' => ['required', 'integer', 'between:0,2147483647'], 'remove_image' => ['sometimes', 'boolean'], 'remove_banner_image' => ['sometimes', 'boolean'],
            'banner_link' => ['sometimes', 'nullable', 'string', 'max:2048', fn (string $attribute, mixed $value, \Closure $fail) => ($value === null || $value === '' || MenuRules::safeUrl((string) $value)) ?: $fail(__('brands.banner_link_invalid'))], 'translations' => ['required', 'array:'.implode(',', $locales->pluck('code')->all())]];
        foreach ($locales as $locale) {
            $prefix = 'translations.'.$locale->code;
            $rules[$prefix] = ['required', 'array:name,description,image_alt,banner_alt'];
            $rules[$prefix.'.name'] = ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'];
            $rules[$prefix.'.description'] = ['present', 'nullable', 'string', 'max:100000'];
            $rules[$prefix.'.image_alt'] = ['present', 'nullable', 'string', 'max:200'];
            $rules[$prefix.'.banner_alt'] = ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'];
        }

        return $rules;
    }

    public function save(string $actor, ?string $id, SaveBrandData $input): string
    {
        $stored = [];
        try {
            return app(AuditLogger::class)->operation('brand', $actor, function () use ($actor, $id, $input, &$stored): string {
                return DB::transaction(function () use ($actor, $id, $input, &$stored): string {
                    $this->authorize($actor);
                    app(CategoryHierarchy::class)->lock();
                    $row = $id === null ? null : $this->row($id, (int) ($input->fields['version'] ?? 0));
                    $data = $input->fields;
                    $data['code'] = strtolower(trim((string) ($data['code'] ?? '')));
                    $locales = app(LocaleCatalog::class)->active();
                    $valid = Validator::make($data, $this->rules($id))->validate();
                    $image = ($valid['remove_image'] ?? false) ? null : $row?->image_media_id;
                    if ($input->image !== null) {
                        $metadata = app(PrivateImageStorage::class)->store($input->image);
                        $stored[] = $metadata;
                        $image = MediaFileRecord::create($metadata)->id;
                    }
                    $banner = ($valid['remove_banner_image'] ?? false) ? null : $row?->banner_image_media_id;
                    if ($input->bannerImage !== null) {
                        try {
                            $metadata = app(PrivateImageStorage::class)->store($input->bannerImage);
                        } catch (ValidationException $failure) {
                            throw ValidationException::withMessages(['banner_image' => $failure->errors()['image']]);
                        }
                        $stored[] = $metadata;
                        $banner = MediaFileRecord::create($metadata)->id;
                    }
                    $values = ['code' => $valid['code'], 'status' => $valid['status'], 'position' => (int) $valid['position'], 'image_media_id' => $image, 'banner_image_media_id' => $banner, 'updated_by' => $actor, 'updated_at' => now()];
                    if (array_key_exists('banner_link', $valid)) {
                        $values['banner_link'] = ($valid['banner_link'] ?? '') === '' ? null : $valid['banner_link'];
                    }
                    if ($id === null) {
                        $id = (string) DB::table('brands')->insertGetId([...$values, 'created_by' => $actor, 'created_at' => now()]);
                    } else {
                        DB::table('brands')->where('id', $id)->update([...$values, 'lock_version' => (int) $row->lock_version + 1]);
                    }
                    foreach ($locales as $locale) {
                        $text = $valid['translations'][$locale->code];
                        DB::table('brand_translations')->updateOrInsert(['brand_id' => $id, 'locale_id' => $locale->id], ['name' => trim($text['name']), 'description' => app(RichTextSanitizer::class)->sanitize($text['description'] ?? ''), 'image_alt' => trim($text['image_alt'] ?? ''), ...(array_key_exists('banner_alt', $text) ? ['banner_alt' => trim($text['banner_alt'] ?? '')] : [])]);
                    }
                    if (($valid['files_submitted'] ?? false) || array_key_exists('files', $valid) || $input->stagedFiles !== []) {
                        app(ProductFiles::class)->syncBrand($id, $valid['files'] ?? [], $input->stagedFiles);
                    }

                    return $id;
                });
            });
        } catch (\Throwable $e) {
            foreach ($stored as $metadata) {
                if (! DB::table('media_files')->where('storage_key', $metadata['storage_key'])->exists()) {
                    app(PrivateImageStorage::class)->discard($metadata['storage_key']);
                }
            }
            throw $e;
        }
    }

    public function status(string $actor, string $id, int $version, string $status): void
    {
        app(AuditLogger::class)->operation('brand', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $status): void {
            $this->authorize($actor);
            app(CategoryHierarchy::class)->lock();
            $this->row($id, $version);
            Validator::make(['status' => $status], ['status' => [Rule::in(['active', 'disabled'])]])->validate();
            DB::table('brands')->where('id', $id)->update(['status' => $status, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('brand', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            app(CategoryHierarchy::class)->lock();
            $this->row($id, $version);
            if (DB::table('products')->where('brand_id', $id)->exists()) {
                throw ValidationException::withMessages(['brand' => __('brands.delete_used')]);
            }
            $files = DB::table('brand_files')->where('brand_id', $id)->pluck('private_file_id')->map(fn ($file): string => (string) $file)->all();
            DB::table('brands')->where('id', $id)->delete();
            app(ProductFiles::class)->schedule($files);
            // Detached originals remain under the existing media retention policy.
        }));
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('brands')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('brands.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('brands.manage');
    }
}
