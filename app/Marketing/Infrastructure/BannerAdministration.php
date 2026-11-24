<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\BannerRules;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\PrivateVideoStorage;
use App\Shared\Application\Command\ImageUpload;
use App\Website\Domain\Service\MenuRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes advanced banners: device-independent settings, per-device layouts (tablet/phone NULL = inherit PC),
 * per-device-and-language texts and media, product and customer-group selections, groups, clone and bulk actions.
 * Every write authorizes `banners.manage`; edits are optimistic-locked by `lock_version`.
 */
final class BannerAdministration
{
    private const MEDIA_SLOTS = ['main_image' => 'main_image_media_id', 'background_image' => 'background_image_media_id', 'background_video' => 'background_video_id'];

    private const TEXTS = ['title' => 500, 'description' => 1000, 'button_text' => 120];

    /** @var list<array{string, string}> Files staged during the current operation, removed again if it fails. */
    private array $staged = [];

    public function __construct(private BannerTitleSanitizer $titles) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $files  Nested like the input: contents[device][locale][main_image|background_image|background_video].
     */
    public function save(string $actor, ?string $id, ?int $version, array $input, array $files = []): string
    {
        $this->staged = [];
        try {
            return app(AuditLogger::class)->operation('banner', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input, $files): string {
                $this->authorize($actor);
                $data = $this->validated($input);
                if ($id === null) {
                    $id = (string) DB::table('banners')->insertGetId([...$data['settings'], 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $this->row($id, (int) $version);
                    DB::table('banners')->where('id', $id)->update([...$data['settings'], 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => (int) $version + 1]);
                }
                $this->writeNames((int) $id, $data['names']);
                $this->writeLayouts((int) $id, $data['layouts']);
                $this->writeContents((int) $id, $data['contents'], $files);
                $this->writeList('banner_products', 'product_id', (int) $id, $data['products'], true);
                $this->writeList('banner_customer_groups', 'group_id', (int) $id, $data['customer_groups'], false);

                return $id;
            }));
        } catch (Throwable $e) {
            $this->discardStaged();
            throw $e;
        }
    }

    /**
     * Runs the save validation without writing, so drafts from other sources (the AI assistant) obey the same rules.
     *
     * @param  array<string, mixed>  $input
     * @return array{settings: array<string, mixed>, names: array<string, string>, layouts: array<string, array<string, mixed>>, contents: array<string, array<string, array<string, mixed>>>, products: list<int>, customer_groups: list<int>}
     */
    public function check(array $input): array
    {
        return $this->validated($input);
    }

    /** Copies everything (sharing the same stored media) as a disabled banner whose names start with [CLONE]. */
    public function clone(string $actor, string $id): string
    {
        return app(AuditLogger::class)->operation('banner', $actor, fn (): string => DB::transaction(function () use ($actor, $id): string {
            $this->authorize($actor);
            $source = (array) (DB::table('banners')->where('id', $id)->first() ?? throw ValidationException::withMessages(['banner' => __('banners.missing')]));
            unset($source['id']);
            $copy = (string) DB::table('banners')->insertGetId([...$source, 'status' => 'disabled', 'lock_version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('banner_translations')->where('banner_id', $id)->get() as $row) {
                DB::table('banner_translations')->insert(['banner_id' => $copy, 'locale_id' => $row->locale_id, 'name' => mb_substr('[CLONE] '.$row->name, 0, 200)]);
            }
            foreach (['banner_layouts', 'banner_contents', 'banner_products', 'banner_customer_groups'] as $table) {
                $rows = DB::table($table)->where('banner_id', $id)->get()->map(fn ($row): array => [...(array) $row, 'banner_id' => $copy])->all();
                if ($rows !== []) {
                    DB::table($table)->insert($rows);
                }
            }

            return $copy;
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('banner', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            $this->row($id, $version);
            // Stored media stay for the media retention policy; nothing is removed from disk here.
            DB::table('banners')->where('id', $id)->delete();
        }));
    }

    /**
     * @param  list<string>  $ids
     * @return int Banners changed.
     */
    public function bulk(string $actor, array $ids, string $action, ?string $group = null): int
    {
        return app(AuditLogger::class)->operation('banner', $actor, fn (): int => DB::transaction(function () use ($actor, $ids, $action, $group): int {
            $this->authorize($actor);
            $query = fn () => DB::table('banners')->whereIn('id', $ids);
            $touch = ['updated_by' => $actor, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')];

            return match ($action) {
                'activate', 'disable' => $query()->where('status', '<>', $action === 'activate' ? 'active' : 'disabled')->update(['status' => $action === 'activate' ? 'active' : 'disabled', ...$touch]),
                'group' => $query()->update(['group_id' => $this->group($group), ...$touch]),
                'ungroup' => $query()->whereNotNull('group_id')->update(['group_id' => null, ...$touch]),
                'delete' => $query()->delete(),
                default => throw ValidationException::withMessages(['action' => __('banners.action_invalid')]),
            };
        }));
    }

    public function createGroup(string $actor, string $name): string
    {
        return app(AuditLogger::class)->operation('banner_group', $actor, fn (): string => $this->uniqueGroup(fn (): string => DB::transaction(function () use ($actor, $name): string {
            $this->authorize($actor);

            return (string) DB::table('banner_groups')->insertGetId(['name' => $this->groupName($name), 'created_at' => now(), 'updated_at' => now()]);
        })));
    }

    public function renameGroup(string $actor, string $id, string $name): void
    {
        app(AuditLogger::class)->operation('banner_group', $actor, fn () => $this->uniqueGroup(fn () => DB::transaction(function () use ($actor, $id, $name): void {
            $this->authorize($actor);
            $this->group($id);
            DB::table('banner_groups')->where('id', $id)->update(['name' => $this->groupName($name), 'updated_at' => now()]);
        })));
    }

    /** Removes the group; its banners stay, ungrouped. */
    public function disbandGroup(string $actor, string $id): void
    {
        app(AuditLogger::class)->operation('banner_group', $actor, fn () => DB::transaction(function () use ($actor, $id): void {
            $this->authorize($actor);
            $this->group($id);
            DB::table('banners')->where('group_id', $id)->update(['group_id' => null, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
            DB::table('banner_groups')->where('id', $id)->delete();
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{settings: array<string, mixed>, names: array<string, string>, layouts: array<string, array<string, mixed>>, contents: array<string, array<string, array<string, mixed>>>, products: list<int>, customer_groups: list<int>}
     */
    private function validated(array $input): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $errors = [];
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $bool = fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);

        // Names: default language required, inactive languages refused.
        $names = [];
        foreach ((array) ($input['names'] ?? []) as $code => $name) {
            $name = $text($name);
            if (! in_array($code, $codes, true)) {
                $errors['names'] = __('banners.language_invalid');
            } elseif ($name !== '') {
                if (mb_strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                    $errors['names.'.$code] = __('banners.name_invalid');
                }
                $names[$code] = $name;
            }
        }
        if (! isset($names[$default])) {
            $errors['names.'.$default] = __('banners.name_required');
        }

        // Device-independent settings.
        $settings = [];
        foreach (BannerRules::SETTINGS as $field => $allowed) {
            // Older editors and imports know nothing of the hover effect: none.
            $value = $text($input[$field] ?? ($field === 'button_hover' ? 'none' : ''));
            if (! in_array($value, $allowed, true)) {
                $errors[$field] = __('banners.choice_invalid');
            }
            $settings[$field] = $value;
        }
        foreach (['products_columns' => [1, 6], 'products_rows' => [1, 4]] as $field => [$min, $max]) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                $errors[$field] = __('banners.number_invalid', ['min' => $min, 'max' => $max]);
            }
            $settings[$field] = (int) $value;
        }
        // Seconds the hover change takes: 0 to 2, in tenths.
        $seconds = $text($input['button_transition'] ?? '0.3');
        if (preg_match('/^(?:[01](?:\.\d)?|2(?:\.0)?)$/D', $seconds) !== 1) {
            $errors['button_transition'] = __('banners.number_invalid', ['min' => 0, 'max' => 2]);
        }
        $settings['button_transition'] = (float) $seconds;
        foreach (['youtube_autoplay', 'youtube_hide_controls', 'new_window', 'tablet_custom', 'phone_custom'] as $field) {
            $settings[$field] = $bool($input[$field] ?? false);
        }
        foreach (['background_color', 'title_color', 'description_color', 'description_background'] as $field) {
            $settings[$field] = $this->color($text($input[$field] ?? ''), $field, $errors);
        }
        $position = filter_var($input['position'] ?? 0, FILTER_VALIDATE_INT);
        if ($position === false || $position < -99999 || $position > 99999) {
            $errors['position'] = __('banners.number_invalid', ['min' => -99999, 'max' => 99999]);
        }
        $settings['position'] = (int) $position;
        $settings['status'] = $text($input['status'] ?? 'active');
        if (! in_array($settings['status'], ['active', 'disabled'], true)) {
            $errors['status'] = __('banners.choice_invalid');
        }
        $group = $text($input['group_id'] ?? '');
        $settings['group_id'] = $group === '' ? null : (int) $group;
        if ($group !== '' && ! DB::table('banner_groups')->where('id', (int) $group)->exists()) {
            $errors['group_id'] = __('banners.group_missing');
        }
        $url = $text($input['url'] ?? '');
        if ($url !== '' && ! MenuRules::safeUrl($url)) {
            $errors['url'] = __('banners.url_invalid');
        }
        $settings['url'] = $url === '' ? null : $url;
        $created = $text($input['created_on'] ?? '');
        $settings['created_on'] = $created === '' ? now()->toDateString() : $created;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $settings['created_on']) !== 1 || ! checkdate((int) substr($settings['created_on'], 5, 2), (int) substr($settings['created_on'], 8, 2), (int) substr($settings['created_on'], 0, 4))) {
            $errors['created_on'] = __('banners.date_invalid');
        }
        $settings['available_from'] = $settings['available_until'] = null;
        if ($bool($input['available'] ?? false)) {
            foreach (['available_from', 'available_until'] as $field) {
                $value = $text($input[$field] ?? '');
                try {
                    // The editor sends ISO 8601 with the administrator's UTC offset; bare values use the app timezone.
                    $settings[$field] = $value === '' ? null : CarbonImmutable::parse($value, config('app.timezone'))->utc();
                } catch (Throwable) {
                    $errors[$field] = __('banners.date_invalid');
                }
            }
            if ($settings['available_from'] === null && $settings['available_until'] === null) {
                $errors['available_until'] = __('banners.period_required');
            } elseif ($settings['available_from'] !== null && $settings['available_until'] !== null && $settings['available_until'] <= $settings['available_from']) {
                $errors['available_until'] = __('banners.period_order');
            }
        }

        // Layouts per device: PC complete (defaults fill gaps), tablet/phone only the overridden fields.
        $layouts = [];
        foreach (BannerRules::DEVICES as $device) {
            $raw = (array) (($input['layouts'] ?? [])[$device] ?? []);
            if ($device !== 'pc' && ! array_key_exists($device, (array) ($input['layouts'] ?? []))) {
                continue;
            }
            $row = [];
            foreach (BannerRules::LAYOUT as $field => $kind) {
                $value = $raw[$field] ?? null;
                $value = is_string($value) ? trim($value) : $value;
                if ($value === null || $value === '') {
                    $row[$field] = $device === 'pc' ? (in_array($field, BannerRules::LAYOUT_REQUIRED, true) ? BannerRules::LAYOUT_DEFAULTS[$field] : null) : null;

                    continue;
                }
                $key = 'layouts.'.$device.'.'.$field;
                $row[$field] = match (true) {
                    is_array($kind) => in_array($value, $kind, true) ? $value : $this->fail($errors, $key, __('banners.choice_invalid')),
                    $kind === 'bool' => $bool($value),
                    $kind === 'size' => $this->integer($value, 6, 200, $key, $errors),
                    $kind === 'percent' => $this->integer($value, 0, 100, $key, $errors),
                    $kind === 'color' => $this->color((string) $value, $key, $errors),
                    $kind === 'classes' => BannerRules::classes((string) $value) ?? $this->fail($errors, $key, __('banners.classes_invalid')),
                    default => BannerRules::paddings((string) $value) ?? $this->fail($errors, $key, __('banners.paddings_invalid')),
                };
            }
            $layouts[$device] = $row;
        }

        // Texts per device and active language; YouTube only on PC.
        $contents = [];
        foreach ((array) ($input['contents'] ?? []) as $device => $byLocale) {
            if (! in_array($device, BannerRules::DEVICES, true)) {
                continue;
            }
            foreach ((array) $byLocale as $code => $fields) {
                if (! in_array($code, $codes, true)) {
                    $errors['contents'] = __('banners.language_invalid');

                    continue;
                }
                $fields = (array) $fields;
                $row = [];
                foreach (self::TEXTS as $field => $max) {
                    $value = is_scalar($fields[$field] ?? null) ? trim((string) $fields[$field]) : '';
                    if ($field === 'title') {
                        $value = $this->titles->sanitize($value);
                    } elseif (preg_match('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
                        $errors['contents.'.$device.'.'.$code.'.'.$field] = __('banners.text_invalid');
                    }
                    if (mb_strlen($value) > $max) {
                        $errors['contents.'.$device.'.'.$code.'.'.$field] = __('banners.text_long', ['max' => $max]);
                    }
                    $row[$field] = $value === '' ? null : $value;
                }
                $video = is_scalar($fields['youtube_video'] ?? null) ? trim((string) $fields['youtube_video']) : '';
                $row['youtube_video'] = null;
                if ($video !== '' && $device === 'pc') {
                    $row['youtube_video'] = BannerRules::youtube($video) ?? $this->fail($errors, 'contents.pc.'.$code.'.youtube_video', __('banners.youtube_invalid'));
                }
                foreach (array_keys(self::MEDIA_SLOTS) as $slot) {
                    $row['remove_'.$slot] = $bool($fields['remove_'.$slot] ?? false);
                }
                $contents[$device][$code] = $row;
            }
        }

        $products = $this->ids($input['products'] ?? [], 'products', 24, $errors);
        if ($products !== [] && DB::table('products')->whereIn('id', $products)->whereNull('deleted_at')->count() !== count($products)) {
            $errors['products'] = __('banners.products_missing');
        }
        $groups = $this->ids($input['customer_groups'] ?? [], 'customer_groups', 100, $errors);
        if ($groups !== [] && DB::table('customer_groups')->whereIn('id', $groups)->count() !== count($groups)) {
            $errors['customer_groups'] = __('banners.customer_groups_missing');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['settings' => $settings, 'names' => $names, 'layouts' => $layouts, 'contents' => $contents, 'products' => $products, 'customer_groups' => $groups];
    }

    /** @param array<string, string> $names */
    private function writeNames(int $id, array $names): void
    {
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $name = $names[$locale->code] ?? null;
            if ($name === null) {
                DB::table('banner_translations')->where('banner_id', $id)->where('locale_id', $locale->id)->delete();
            } else {
                DB::table('banner_translations')->updateOrInsert(['banner_id' => $id, 'locale_id' => $locale->id], ['name' => $name]);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $layouts */
    private function writeLayouts(int $id, array $layouts): void
    {
        foreach ($layouts as $device => $row) {
            if ($device !== 'pc' && array_filter($row, fn (mixed $value): bool => $value !== null) === []) {
                DB::table('banner_layouts')->where('banner_id', $id)->where('device', $device)->delete();

                continue;
            }
            DB::table('banner_layouts')->updateOrInsert(['banner_id' => $id, 'device' => $device], $row);
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $contents
     * @param  array<string, mixed>  $files
     */
    private function writeContents(int $id, array $contents, array $files): void
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        foreach ($contents as $device => $byLocale) {
            foreach ($byLocale as $code => $row) {
                $key = ['banner_id' => $id, 'device' => $device, 'locale_id' => $locales[$code]];
                $existing = DB::table('banner_contents')->where($key)->first();
                $values = array_intersect_key($row, array_flip([...array_keys(self::TEXTS), 'youtube_video']));
                foreach (self::MEDIA_SLOTS as $slot => $column) {
                    $file = (($files[$device] ?? [])[$code] ?? [])[$slot] ?? null;
                    $values[$column] = $existing?->{$column};
                    if ($file instanceof UploadedFile) {
                        $values[$column] = $this->storeMedia($slot, $file, 'contents.'.$device.'.'.$code.'.'.$slot);
                    } elseif ($row['remove_'.$slot]) {
                        $values[$column] = null;
                    }
                }
                if (array_filter($values, fn (mixed $value): bool => $value !== null) === []) {
                    DB::table('banner_contents')->where($key)->delete();
                } else {
                    DB::table('banner_contents')->updateOrInsert($key, $values);
                }
            }
        }
    }

    private function storeMedia(string $slot, UploadedFile $file, string $field): int
    {
        if ($slot === 'background_video') {
            $stored = app(PrivateVideoStorage::class)->store($file, $field);
            $this->staged[] = ['video', $stored['storage_key']];

            return (int) DB::table('media_videos')->insertGetId([...$stored, 'created_at' => now(), 'updated_at' => now()]);
        }
        $validator = Validator::make(['file' => $file], ['file' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$field => __('banners.image_invalid')]);
        }
        try {
            $stored = app(PrivateImageStorage::class)->store(new ImageUpload($file->getPathname()));
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => __('banners.image_invalid')]);
        }
        $this->staged[] = ['image', $stored['storage_key']];

        return (int) MediaFileRecord::create($stored)->id;
    }

    private function discardStaged(): void
    {
        foreach ($this->staged as [$kind, $key]) {
            $table = $kind === 'video' ? 'media_videos' : 'media_files';
            if (! DB::table($table)->where('storage_key', $key)->exists()) {
                $kind === 'video' ? app(PrivateVideoStorage::class)->discard($key) : app(PrivateImageStorage::class)->discard($key);
            }
        }
        $this->staged = [];
    }

    /** @param list<int> $ids */
    private function writeList(string $table, string $column, int $id, array $ids, bool $ordered): void
    {
        DB::table($table)->where('banner_id', $id)->delete();
        foreach ($ids as $position => $value) {
            DB::table($table)->insert(['banner_id' => $id, $column => $value, ...($ordered ? ['position' => $position] : [])]);
        }
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return list<int>
     */
    private function ids(mixed $value, string $field, int $max, array &$errors): array
    {
        $ids = [];
        foreach ((array) $value as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                $errors[$field] = __('banners.choice_invalid');

                return [];
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > $max) {
            $errors[$field] = __('banners.too_many', ['max' => $max]);
        }

        return $ids;
    }

    /** @param array<string, mixed> $errors */
    private function color(string $value, string $field, array &$errors): ?string
    {
        if ($value === '') {
            return null;
        }

        return BannerRules::color($value) ?? $this->fail($errors, $field, __('banners.color_invalid'));
    }

    /** @param array<string, mixed> $errors */
    private function integer(mixed $value, int $min, int $max, string $field, array &$errors): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) {
            return $this->fail($errors, $field, __('banners.number_invalid', ['min' => $min, 'max' => $max]));
        }

        return $number;
    }

    /** @param array<string, mixed> $errors */
    private function fail(array &$errors, string $field, string $message): null
    {
        $errors[$field] = $message;

        return null;
    }

    private function groupName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => __('banners.group_name_invalid')]);
        }

        return $name;
    }

    private function group(?string $id): int
    {
        if ($id === null || ! DB::table('banner_groups')->where('id', $id)->exists()) {
            throw ValidationException::withMessages(['group' => __('banners.group_missing')]);
        }

        return (int) $id;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function uniqueGroup(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['name' => __('banners.group_name_taken')]);
            }
            throw $e;
        }
    }

    private function row(string $id, int $version): object
    {
        $banner = DB::table('banners')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('banners.stale')]);
        if ((int) $banner->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('banners.stale')]);
        }

        return $banner;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('banners.manage');
    }
}
