<?php

namespace App\Settings\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One installation-wide business identity, independently versioned from general defaults. */
final class CompanySettings
{
    public const FIELDS = ['name', 'address', 'city', 'country_code', 'region', 'postal_code', 'phone', 'mobile', 'website', 'opening_year', 'legal_name', 'business_activity', 'tax_id', 'tax_office', 'gemi_number', 'email_customers', 'email_admin', 'email_orders', 'email_support', 'email_newsletter'];

    /** Footer link presets with their default names; a preset link may use its built-in mark instead of a picture. */
    public const PRESETS = [
        'social' => ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest'],
        'app' => ['app_store' => 'App Store', 'google_play' => 'Google Play'],
    ];

    public const MAX_LINKS = 20;

    public const MAX_PAYMENT_ICONS = 12;

    /** @return array<string, mixed> */
    public function all(): array
    {
        $row = DB::table('company_settings')->where('id', 1)->firstOrFail();
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $field === 'opening_year' && $row->$field !== null ? (int) $row->$field : $row->$field;
        }

        return [...$values, 'version' => (int) $row->lock_version, 'logo' => $this->logo($row->logo_media_id, 'desktop'), 'logo_mobile' => $this->logo($row->logo_mobile_media_id, 'mobile'), 'logo_email' => $this->logo($row->logo_email_media_id, 'email'), 'favicon' => $this->logo($row->favicon_media_id, 'favicon'), 'payment_icons' => $this->paymentIcons(), 'links' => $this->links()];
    }

    /** @return list<array{id:string,kind:string,preset:?string,name:string,url:string,image:?string}> */
    public function links(): array
    {
        return DB::table('company_links')->orderBy('kind')->orderBy('position')->orderBy('id')->get()
            ->map(fn ($r): array => ['id' => (string) $r->id, 'kind' => $r->kind, 'preset' => $r->preset, 'name' => $r->name, 'url' => $r->url,
                'image' => $r->media_file_id === null ? null : '/admin/settings/company/links/'.$r->id.'/image?v='.$r->media_file_id])->all();
    }

    public function linkMedia(string $id): ?MediaFileRecord
    {
        $media = DB::table('company_links')->where('id', $id)->value('media_file_id');

        return $media === null ? null : MediaFileRecord::find($media);
    }

    /**
     * Adds a footer link at the end of its list, or edits one ($id). A new picture replaces the old one;
     * $removeImage goes back to the preset's built-in mark.
     *
     * @param  array<string,mixed>  $input  kind (new links only), preset, name, url
     */
    public function saveLink(string $actor, ?string $id, array $input, ?UploadedFile $image, bool $removeImage = false): void
    {
        $stored = null;
        try {
            $this->linkOperation($actor, function () use ($id, $input, $image, $removeImage, &$stored): void {
                $current = $id === null ? null : (DB::table('company_links')->where('id', $id)->first() ?? abort(404));
                $kind = $current->kind ?? ($input['kind'] ?? null);
                $data = Validator::make([...$input, 'kind' => $kind, 'name' => is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null), 'url' => is_string($input['url'] ?? null) ? trim($input['url']) : ($input['url'] ?? null)], [
                    'kind' => ['required', Rule::in(array_keys(self::PRESETS))],
                    'preset' => ['nullable', 'string', Rule::in(array_keys(self::PRESETS[is_string($kind) && isset(self::PRESETS[$kind]) ? $kind : 'social']))],
                    'name' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1f\x7f]/u', 'required_without:preset'],
                    'url' => ['required', 'string', 'max:2048', 'url:http,https', 'regex:/^https?:\/\/[^\s]+$/D'],
                ], [], ['name' => __('company.link_name'), 'url' => __('company.link_url')])->validate();
                if ($image !== null && Validator::make(['file' => $image], ['file' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']])->fails()) {
                    throw ValidationException::withMessages(['image' => __('company.logo_invalid')]);
                }
                $media = $removeImage ? null : ($current->media_file_id ?? null);
                if ($image !== null) {
                    try {
                        $stored = app(PrivateImageStorage::class)->store(new ImageUpload($image->getPathname()));
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['image' => __('company.logo_invalid')]);
                    }
                    $media = MediaFileRecord::create($stored)->id;
                }
                $preset = $data['preset'] ?? null;
                if ($preset === null && $media === null) {
                    throw ValidationException::withMessages(['image' => __('company.link_image_required')]);
                }
                $values = ['preset' => $preset, 'name' => ($data['name'] ?? '') !== '' ? $data['name'] : self::PRESETS[$data['kind']][$preset], 'url' => $data['url'], 'media_file_id' => $media, 'updated_at' => now()];
                if ($current !== null) {
                    DB::table('company_links')->where('id', $current->id)->update($values);

                    return;
                }
                if (DB::table('company_links')->where('kind', $data['kind'])->count() >= self::MAX_LINKS) {
                    throw ValidationException::withMessages(['url' => __('company.links_limit', ['count' => self::MAX_LINKS])]);
                }
                DB::table('company_links')->insert([...$values, 'kind' => $data['kind'], 'position' => ((int) DB::table('company_links')->where('kind', $data['kind'])->max('position')) + 1, 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            if ($stored !== null && ! DB::table('media_files')->where('storage_key', $stored['storage_key'])->exists()) {
                app(PrivateImageStorage::class)->discard($stored['storage_key']);
            }
            throw $e;
        }
    }

    /** @param list<string> $ids The whole list of one kind, in its new order. */
    public function reorderLinks(string $actor, string $kind, array $ids): void
    {
        $this->linkOperation($actor, function () use ($kind, $ids): void {
            Validator::make(['kind' => $kind, 'ids' => $ids], ['kind' => ['required', Rule::in(array_keys(self::PRESETS))], 'ids' => ['present', 'array', 'max:'.self::MAX_LINKS], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']])->validate();
            $current = DB::table('company_links')->where('kind', $kind)->pluck('id')->map(strval(...))->all();
            if (count($current) !== count($ids) || array_diff($current, $ids) !== []) {
                throw ValidationException::withMessages(['ids' => __('settings.stale')]);
            }
            foreach ($ids as $position => $id) {
                DB::table('company_links')->where('id', $id)->where('position', '<>', $position + 1)->update(['position' => $position + 1, 'updated_at' => now()]);
            }
        });
    }

    public function removeLink(string $actor, string $id): void
    {
        $this->linkOperation($actor, fn () => abort_unless(DB::table('company_links')->where('id', $id)->delete() === 1, 404));
    }

    /** Audited, authorized and serialized with the other company writes. */
    private function linkOperation(string $actor, \Closure $work): void
    {
        app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $work): void {
            DB::transaction(function () use ($actor, $work): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                $work();
            });
        });
    }

    /** @return list<array{id:string,name:string,url:string,meta:array{size:int,width:int,height:int}}> */
    public function paymentIcons(): array
    {
        return DB::table('company_payment_icons as p')->join('media_files as m', 'm.id', '=', 'p.media_file_id')->orderBy('p.position')->orderBy('p.id')
            ->get(['p.id', 'p.name', 'p.media_file_id', 'm.byte_size', 'm.width', 'm.height'])
            ->map(fn ($r): array => ['id' => (string) $r->id, 'name' => $r->name, 'url' => '/admin/settings/company/payment-icons/'.$r->id.'/image?v='.$r->media_file_id, 'meta' => ['size' => (int) $r->byte_size, 'width' => (int) $r->width, 'height' => (int) $r->height]])->all();
    }

    public function paymentIconMedia(string $id): ?MediaFileRecord
    {
        $media = DB::table('company_payment_icons')->where('id', $id)->value('media_file_id');

        return $media === null ? null : MediaFileRecord::find($media);
    }

    /** Adds a payment picture at the end of the list (at most MAX_PAYMENT_ICONS). */
    public function addPaymentIcon(string $actor, UploadedFile $file, string $name): void
    {
        $stored = null;
        try {
            app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $file, $name, &$stored): void {
                DB::transaction(function () use ($actor, $file, $name, &$stored): void {
                    app(AuditContext::class)->actor($actor);
                    Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                    DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                    $data = Validator::make(['name' => trim($name)], ['name' => ['nullable', 'string', 'max:80', 'not_regex:/[\x00-\x1f\x7f]/u']])->validate();
                    if (Validator::make(['file' => $file], ['file' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']])->fails()) {
                        throw ValidationException::withMessages(['icon' => __('company.logo_invalid')]);
                    }
                    if (DB::table('company_payment_icons')->count() >= self::MAX_PAYMENT_ICONS) {
                        throw ValidationException::withMessages(['icon' => __('company.payment_icons_limit', ['count' => self::MAX_PAYMENT_ICONS])]);
                    }
                    try {
                        $stored = app(PrivateImageStorage::class)->store(new ImageUpload($file->getPathname()));
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['icon' => __('company.logo_invalid')]);
                    }
                    DB::table('company_payment_icons')->insert(['media_file_id' => MediaFileRecord::create($stored)->id, 'name' => $data['name'] ?? '', 'position' => ((int) DB::table('company_payment_icons')->max('position')) + 1, 'created_at' => now(), 'updated_at' => now()]);
                });
            });
        } catch (\Throwable $e) {
            if ($stored !== null && ! DB::table('media_files')->where('storage_key', $stored['storage_key'])->exists()) {
                app(PrivateImageStorage::class)->discard($stored['storage_key']);
            }
            throw $e;
        }
    }

    /** @param list<string> $ids */
    public function reorderPaymentIcons(string $actor, array $ids): void
    {
        app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $ids): void {
            DB::transaction(function () use ($actor, $ids): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                Validator::make(['ids' => $ids], ['ids' => ['present', 'array', 'max:'.self::MAX_PAYMENT_ICONS], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']])->validate();
                $current = DB::table('company_payment_icons')->pluck('id')->map(strval(...))->all();
                if (count($current) !== count($ids) || array_diff($current, $ids) !== []) {
                    throw ValidationException::withMessages(['ids' => __('settings.stale')]);
                }
                foreach ($ids as $position => $id) {
                    DB::table('company_payment_icons')->where('id', $id)->where('position', '<>', $position + 1)->update(['position' => $position + 1, 'updated_at' => now()]);
                }
            });
        });
    }

    public function removePaymentIcon(string $actor, string $id): void
    {
        app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $id): void {
            DB::transaction(function () use ($actor, $id): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                abort_unless(DB::table('company_payment_icons')->where('id', $id)->delete() === 1, 404);
            });
        });
    }

    /** @return array{url: string, meta: array{size: int, width: int, height: int}}|null */
    private function logo(mixed $media, string $kind): ?array
    {
        if ($media === null) {
            return null;
        }
        $file = DB::table('media_files')->where('id', $media)->first(['byte_size', 'width', 'height', 'updated_at']);

        return $file === null ? null : [
            'url' => '/admin/settings/company/logo/'.$kind.'?v='.rawurlencode((string) $media),
            'meta' => ['size' => (int) $file->byte_size, 'width' => (int) $file->width, 'height' => (int) $file->height],
        ];
    }

    /** The stored media file of a company logo or favicon, without a fallback. */
    public function logoMedia(string $kind): ?MediaFileRecord
    {
        $column = ['mobile' => 'logo_mobile_media_id', 'email' => 'logo_email_media_id', 'favicon' => 'favicon_media_id'][$kind] ?? 'logo_media_id';
        $id = DB::table('company_settings')->where('id', 1)->value($column);

        return $id === null ? null : MediaFileRecord::find($id);
    }

    /** One selection for all email templates; missing files also fall back to the store logo. */
    public function emailLogo(): ?MediaFileRecord
    {
        foreach (['email', 'desktop'] as $kind) {
            $media = $this->logoMedia($kind);
            if ($media !== null && $media->disk === 'local' && Storage::disk('local')->exists($media->storage_key)) {
                return $media;
            }
        }

        return null;
    }

    /**
     * Replaces and/or removes the store logos in one versioned write. Removing the main logo also removes the mobile one.
     * The stored files are kept for the media retention policy.
     */
    public function saveLogos(string $actor, int $version, ?UploadedFile $logo, ?UploadedFile $mobile, bool $removeLogo, bool $removeMobile, ?UploadedFile $favicon = null, bool $removeFavicon = false, ?UploadedFile $email = null, bool $removeEmail = false): void
    {
        $stored = [];
        try {
            app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $version, $logo, $mobile, $removeLogo, $removeMobile, $favicon, $removeFavicon, $email, $removeEmail, &$stored): void {
                DB::transaction(function () use ($actor, $version, $logo, $mobile, $removeLogo, $removeMobile, $favicon, $removeFavicon, $email, $removeEmail, &$stored): void {
                    app(AuditContext::class)->actor($actor);
                    Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                    $row = DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                    if ((int) $row->lock_version !== $version) {
                        throw ValidationException::withMessages(['version' => __('settings.stale')]);
                    }
                    $errors = [];
                    foreach (['logo' => $logo, 'logo_mobile' => $mobile, 'favicon' => $favicon, 'logo_email' => $email] as $field => $file) {
                        if ($file !== null && Validator::make(['file' => $file], ['file' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']])->fails()) {
                            $errors[$field] = __('company.logo_invalid');
                        }
                    }
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                    $main = $row->logo_media_id;
                    $small = $row->logo_mobile_media_id;
                    $icon = $row->favicon_media_id;
                    $mail = $removeEmail ? null : $row->logo_email_media_id;
                    if ($removeFavicon) {
                        $icon = null;
                    }
                    if ($removeLogo) {
                        $main = $small = null;
                    }
                    if ($removeMobile) {
                        $small = null;
                    }
                    foreach (['logo' => [$logo, &$main], 'logo_mobile' => [$mobile, &$small], 'favicon' => [$favicon, &$icon], 'logo_email' => [$email, &$mail]] as $field => [$file, &$target]) {
                        if ($file === null) {
                            continue;
                        }
                        try {
                            $info = app(PrivateImageStorage::class)->store(new ImageUpload($file->getPathname()));
                        } catch (ValidationException) {
                            throw ValidationException::withMessages([$field => __('company.logo_invalid')]);
                        }
                        $stored[] = $info['storage_key'];
                        $target = MediaFileRecord::create($info)->id;
                    }
                    if ($small !== null && $main === null) {
                        throw ValidationException::withMessages(['logo_mobile' => __('company.logo_mobile_needs_main')]);
                    }
                    if ($main === $row->logo_media_id && $small === $row->logo_mobile_media_id && $icon === $row->favicon_media_id && $mail === $row->logo_email_media_id) {
                        return;
                    }
                    DB::table('company_settings')->where('id', 1)->update(['logo_media_id' => $main, 'logo_mobile_media_id' => $small, 'favicon_media_id' => $icon, 'logo_email_media_id' => $mail, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                });
            });
        } catch (\Throwable $e) {
            foreach ($stored as $key) {
                if (! DB::table('media_files')->where('storage_key', $key)->exists()) {
                    app(PrivateImageStorage::class)->discard($key);
                }
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $fields */
    public function save(string $actor, array $fields): void
    {
        app(AuditLogger::class)->operation('company_settings', $actor, function () use ($actor, $fields): void {
            DB::transaction(function () use ($actor, $fields): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                $row = DB::table('company_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
                foreach (self::FIELDS as $field) {
                    if (isset($fields[$field]) && is_string($fields[$field])) {
                        $fields[$field] = trim($fields[$field]);
                        if ($fields[$field] === '') {
                            $fields[$field] = null;
                        }
                    }
                }
                if (is_string($fields['country_code'] ?? null)) {
                    $fields['country_code'] = strtoupper($fields['country_code']);
                }
                $rules = ['version' => ['required', 'integer', 'min:1']];
                foreach (['legal_name' => 200, 'business_activity' => 200, 'tax_id' => 32, 'tax_office' => 100, 'name' => 200, 'address' => 500, 'city' => 200, 'region' => 200, 'postal_code' => 32, 'phone' => 64, 'mobile' => 64, 'website' => 2048, 'email_customers' => 254, 'email_admin' => 254, 'email_orders' => 254, 'email_support' => 254, 'email_newsletter' => 254] as $field => $length) {
                    $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.$length, 'not_regex:/[\x00-\x1f\x7f]/u'];
                    if (str_starts_with($field, 'email_')) {
                        $rules[$field][] = 'email:rfc';
                    }
                }
                $rules['website'][] = 'url:http,https';
                $rules['country_code'] = ['sometimes', 'nullable', 'string', Rule::in(app(Countries::class)->codes())];
                $rules['opening_year'] = ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.now()->year];
                // Greek companies: the ΑΦΜ is 9 digits with its check digit, and the GEMI number exists only for them.
                $greek = ($fields['country_code'] ?? $row->country_code) === 'GR';
                if ($greek) {
                    $rules['tax_id'][] = fn (string $attribute, mixed $value, \Closure $fail) => $value === null || TaxIds::greek((string) $value) || $fail(__('company.tax_id_invalid'));
                    $rules['gemi_number'] = ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{1,12}$/D'];
                } else {
                    $fields['gemi_number'] = null;
                    $rules['gemi_number'] = ['nullable'];
                }
                $attributes = [];
                foreach (self::FIELDS as $field) {
                    $attributes[$field] = __('company.'.$field);
                }
                $validated = Validator::make($fields, $rules, [], $attributes)->validate();
                if ((int) $row->lock_version !== (int) $validated['version']) {
                    throw ValidationException::withMessages(['version' => __('settings.stale')]);
                }
                $values = array_intersect_key($validated, array_flip(self::FIELDS));
                // Preserve omitted fields and avoid mutation/audit noise for an unchanged form.
                $changed = false;
                foreach ($values as $field => $value) {
                    if (($row->$field === null ? null : (string) $row->$field) !== ($value === null ? null : (string) $value)) {
                        $changed = true;
                        break;
                    }
                }
                if ($changed) {
                    DB::table('company_settings')->where('id', 1)->update([...$values, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                }
            });
        });
    }
}
