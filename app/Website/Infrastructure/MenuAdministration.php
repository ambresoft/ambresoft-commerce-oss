<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MenuAdministration
{
    /** @param array<string, mixed> $fields */
    public function save(string $actor, ?string $id, array $fields): string
    {
        return $this->mutation($actor, function () use ($id, $fields): string {
            $row = $id === null ? null : $this->locked($id, $fields['version'] ?? null);
            $v = Validator::make($fields, [
                'status' => ['required', Rule::in(['active', 'disabled'])],
                'placement' => ['required', Rule::in(['header', 'footer', 'other'])],
                'source' => ['required', Rule::in(['manual', 'categories'])],
                'style' => ['sometimes', Rule::in(['standard', 'link_groups'])],
                'confirmed' => ['sometimes', 'boolean'],
            ])->validate();
            if ($row && $row->source !== $v['source'] && DB::table('website_menu_items')->where('menu_id', $id)->exists() && ! ($v['confirmed'] ?? false)) {
                throw ValidationException::withMessages(['confirmed' => __('menus.mode_confirm', ['count' => DB::table('website_menu_items')->where('menu_id', $id)->count()])]);
            }
            $style = $v['style'] ?? $row->style ?? 'standard';
            if ($style === 'link_groups') {
                // Link groups are free manual lists: headings with links, two levels, no category links.
                if ($v['source'] !== 'manual') {
                    throw ValidationException::withMessages(['source' => __('menus.link_groups_manual')]);
                }
                if ($id !== null && $this->breaksLinkGroups($id)) {
                    throw ValidationException::withMessages(['style' => __('menus.link_groups_invalid_items')]);
                }
            }
            $names = $this->translations($fields, 'menu', $id);
            $values = [...array_intersect_key($v, array_flip(['status', 'placement', 'source'])), 'style' => $style, 'updated_at' => now()];
            if ($row) {
                DB::table('website_menus')->where('id', $id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
            } else {
                $id = (string) DB::table('website_menus')->insertGetId([...$values, 'created_at' => now()]);
            }
            $this->writeTranslations('menu', $id, $names);

            return $id;
        });
    }

    /** @param array<string, mixed> $fields */
    public function item(string $actor, string $menu, ?string $id, array $fields, ?UploadedFile $image = null): string
    {
        $stored = null;
        try {
            return $this->mutation($actor, function () use ($menu, $id, $fields, $image, &$stored): string {
                $owner = $this->locked($menu, $fields['version'] ?? null);
                if ($owner->source !== 'manual') {
                    throw ValidationException::withMessages(['source' => __('menus.generated_readonly')]);
                }
                $row = $id === null ? null : DB::table('website_menu_items')->where('menu_id', $menu)->where('id', $id)->firstOrFail();
                $v = Validator::make($fields, [
                    'parent_id' => ['nullable', 'integer', Rule::exists('website_menu_items', 'id')->where('menu_id', $menu)],
                    'status' => ['required', Rule::in(['active', 'disabled'])], 'position' => ['required', 'integer', 'min:0', 'max:2147483647'],
                    'kind' => ['required', Rule::in(['heading', 'url', 'category'])], 'url' => ['nullable', 'string', 'max:2048'],
                    'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('system_key')],
                    'generate_children' => ['required', 'boolean'], 'new_window' => ['required', 'boolean'], 'remove_image' => ['sometimes', 'boolean'],
                    'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                ])->validate();
                $parent = isset($v['parent_id']) ? (string) $v['parent_id'] : null;
                if ($owner->style === 'link_groups') {
                    $parentRow = $parent === null ? null : DB::table('website_menu_items')->where('id', $parent)->first();
                    if ($v['kind'] === 'category' || $v['generate_children'] || ($parentRow !== null && $parentRow->parent_id !== null) || ($parent !== null && $id !== null && DB::table('website_menu_items')->where('parent_id', $id)->exists())) {
                        throw ValidationException::withMessages(['parent_id' => __('menus.link_groups_rule')]);
                    }
                }
                if ($parent !== null && (($id !== null && in_array($parent, $this->descendants($id), true)) || DB::table('website_menu_items')->where('id', $parent)->value('generate_children'))) {
                    throw ValidationException::withMessages(['parent_id' => __('menus.invalid_parent')]);
                }
                if ($v['kind'] === 'url' && ! MenuRules::safeUrl(trim($v['url'] ?? ''))) {
                    throw ValidationException::withMessages(['url' => __('menus.invalid_url')]);
                }
                if ($v['kind'] === 'category' && ! isset($v['category_id'])) {
                    throw ValidationException::withMessages(['category_id' => __('menus.category_required')]);
                }
                if ($v['generate_children'] && ($v['kind'] !== 'category' || ($id && DB::table('website_menu_items')->where('parent_id', $id)->exists()))) {
                    throw ValidationException::withMessages(['generate_children' => __('menus.generated_conflict')]);
                }
                $names = $this->translations($fields, 'item', $id);
                $media = $row?->image_media_id;
                if ($image) {
                    $stored = app(PrivateImageStorage::class)->store(new ImageUpload($image->getPathname()));
                    $media = MediaFileRecord::create($stored)->id;
                } elseif ($v['remove_image'] ?? false) {
                    $media = null;
                }
                $values = [
                    'menu_id' => $menu, 'parent_id' => $parent, 'status' => $v['status'], 'position' => $v['position'],
                    'kind' => $v['kind'], 'url' => $v['kind'] === 'url' ? trim($v['url']) : null,
                    'category_id' => $v['kind'] === 'category' ? $v['category_id'] : null,
                    'generate_children' => (bool) $v['generate_children'], 'new_window' => (bool) $v['new_window'],
                    'image_media_id' => $media, 'updated_at' => now(),
                ];
                if ($row) {
                    DB::table('website_menu_items')->where('id', $id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
                } else {
                    $id = (string) DB::table('website_menu_items')->insertGetId([...$values, 'created_at' => now()]);
                }
                $this->writeTranslations('item', $id, $names);
                DB::table('website_menus')->where('id', $menu)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);

                return $id;
            });
        } catch (Throwable $failure) {
            if ($stored !== null && ! DB::table('media_files')->where('storage_key', $stored['storage_key'])->exists()) {
                app(PrivateImageStorage::class)->discard($stored['storage_key']);
            }
            throw $failure;
        }
    }

    /** @param array<string, mixed> $fields */
    public function delete(string $actor, string $menu, ?string $item, array $fields): void
    {
        $this->mutation($actor, function () use ($menu, $item, $fields): void {
            $owner = $this->locked($menu, $fields['version'] ?? null);
            Validator::make($fields, ['confirmed' => ['required', 'accepted']])->validate();
            if ($item !== null) {
                if ($owner->source !== 'manual') {
                    throw ValidationException::withMessages(['source' => __('menus.generated_readonly')]);
                }
                DB::table('website_menu_items')->where('menu_id', $menu)->where('id', $item)->firstOrFail();
                DB::table('website_menu_items')->where('id', $item)->delete();
                DB::table('website_menus')->where('id', $menu)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            } else {
                DB::table('website_menus')->where('id', $menu)->delete();
            }
        });
    }

    /** Whether a menu's items cannot be a link-groups menu: category links, generated children or more than two levels. */
    private function breaksLinkGroups(string $menu): bool
    {
        return DB::table('website_menu_items as i')->where('i.menu_id', $menu)->where(fn ($q) => $q->where('i.kind', 'category')->orWhere('i.generate_children', true)
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('website_menu_items as p')->whereColumn('p.id', 'i.parent_id')->whereNotNull('p.parent_id')))->exists();
    }

    /** @return list<string> */
    public function descendants(string $id): array
    {
        return array_map(fn ($r): string => (string) $r->id, DB::select('WITH RECURSIVE subtree AS (SELECT id FROM website_menu_items WHERE id = ? UNION ALL SELECT i.id FROM website_menu_items i JOIN subtree s ON i.parent_id = s.id) SELECT id FROM subtree', [$id]));
    }

    private function locked(string $id, mixed $version): object
    {
        $v = Validator::make(['version' => $version], ['version' => ['required', 'integer', 'min:1']])->validate();
        $row = DB::table('website_menus')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ((int) $row->lock_version !== (int) $v['version']) {
            throw ValidationException::withMessages(['version' => __('settings.stale')]);
        }

        return $row;
    }

    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    private function mutation(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('menu', $actor, function () use ($actor, $operation) {
            return DB::transaction(function () use ($actor, $operation) {
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('menus.manage');
                app(CategoryHierarchy::class)->lock();

                return $operation();
            });
        });
    }

    /** @param array<string, mixed> $fields
     * @return array<string, array<string,string>>
     */
    private function translations(array $fields, string $type, ?string $id): array
    {
        $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
        $rules = ['translations' => ['required', 'array:'.implode(',', $codes)]];
        foreach ($codes as $code) {
            $prefix = 'translations.'.$code;
            $rules[$prefix] = ['sometimes', 'array:name,description,image_alt'];
            foreach (['name' => 200, 'description' => 500, 'image_alt' => 200] as $field => $max) {
                $rules[$prefix.'.'.$field] = ['sometimes', 'nullable', 'string', 'max:'.$max];
            }
        }
        $v = Validator::make($fields, $rules)->validate()['translations'];
        $default = app(LocaleCatalog::class)->defaultCode();
        $names = [];
        foreach ($v as $code => $values) {
            $names[$code] = array_map(fn ($value): string => trim($value ?? ''), $values);
            $name = $names[$code]['name'] ?? '';
            if ($name === '' && (($names[$code]['description'] ?? '') !== '' || ($names[$code]['image_alt'] ?? '') !== '')) {
                throw ValidationException::withMessages(['translations.'.$code.'.name' => __('menus.name_required')]);
            }
        }
        $table = $type === 'menu' ? 'website_menu_translations' : 'website_menu_item_translations';
        $saved = $id === null ? null : DB::table($table)->where($type.'_id', $id)->where('locale_id', app(LocaleCatalog::class)->active()->firstWhere('code', $default)->id)->value('name');
        if ((array_key_exists($default, $names) ? ($names[$default]['name'] ?? '') : ($saved ?? '')) === '') {
            throw ValidationException::withMessages(['translations.'.$default.'.name' => __('menus.name_required')]);
        }

        return $names;
    }

    /** @param array<string, array<string,string>> $names */
    private function writeTranslations(string $type, string $id, array $names): void
    {
        $table = $type === 'menu' ? 'website_menu_translations' : 'website_menu_item_translations';
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            if (! array_key_exists($locale->code, $names)) {
                continue;
            }
            $values = $names[$locale->code];
            if (($values['name'] ?? '') === '') {
                DB::table($table)->where($type.'_id', $id)->where('locale_id', $locale->id)->delete();
            } else {
                $values = $type === 'menu' ? ['name' => $values['name']] : array_intersect_key($values, array_flip(['name', 'description', 'image_alt']));
                DB::table($table)->updateOrInsert([$type.'_id' => $id, 'locale_id' => $locale->id], $values);
            }
        }
    }
}
