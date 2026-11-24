<?php

namespace App\Catalog\Infrastructure\Import;

use App\Catalog\Infrastructure\CategoryInput;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\RemoteImageDownload;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** No writes, events or mutation participants during preview. */
final readonly class CategoryImportPlanner
{
    public function __construct(private CategoryCsvReader $reader, private CategoryImportFields $fields, private CategoryInput $input) {}

    /** @param Closure(array<string, mixed>): void $emit
     * @return array<string, mixed>
     */
    public function plan(UserRecord $actor, string $file, string $delimiter, string $separator, Closure $emit): array
    {
        $state = new CategoryImportState;
        $validationRules = $this->input->importRules('1');
        $validationAttributes = $this->input->attributes();
        $canCreate = Gate::forUser($actor)->allows('categories.create');
        $canUpdate = Gate::forUser($actor)->allows('categories.update');
        $report = ['images' => 0, 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'parents' => 0, 'errors' => 0, 'issues' => [], 'sample' => [], 'fingerprint' => $state->fingerprint, 'fileHash' => hash_file('sha256', $file), 'status' => 'preview'];
        $headers = [];
        $seen = [];
        $paths = [];
        $currentRow = 1;
        try {
            foreach ($this->reader->rows($file, $delimiter) as $number => $values) {
                $currentRow = $number;
                if ($number === 1) {
                    $headers = array_map(trim(...), $values);
                    if (count($headers) !== count(array_unique($headers))) {
                        throw new RuntimeException(__('category_import.headers'));
                    }
                    foreach (array_diff($headers, $this->fields->columns()) as $header) {
                        $parts = explode('.', $header, 2);
                        $reason = count($parts) === 2 && in_array($parts[0], CategoryImportFields::TRANSLATED, true) && ! isset($state->localeIds[$parts[1]])
                            ? __('category_import.inactive_locale', ['locale' => $parts[1]])
                            : __('category_import.headers');
                        $this->issue($report, 1, [], $header, $reason);
                    }
                    if ($report['errors'] > 0) {
                        return $report;
                    }

                    continue;
                }
                if ($values === ['']) {
                    continue;
                }
                $report['rows']++;
                $row = count($values) === count($headers) ? array_combine($headers, $values) : [];
                $column = 'category_path';
                try {
                    if ($row === []) {
                        throw new RuntimeException(__('category_import.width'));
                    }
                    $id = trim($row['category_id'] ?? '');
                    if ($id !== '') {
                        $column = 'category_id';
                        if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1 || ! isset($state->nodes[$id])) {
                            throw new RuntimeException(__('category_import.invalid_id'));
                        }
                        if (isset($seen[$id])) {
                            throw new RuntimeException(__('category_import.duplicate_id'));
                        }
                        $seen[$id] = true;
                        if ($state->nodes[$id]['system_key'] !== null) {
                            throw new RuntimeException(__('categories.errors.system'));
                        }
                    }
                    $creating = $id === '';
                    if (! ($creating ? $canCreate : $canUpdate)) {
                        throw new AuthorizationException;
                    }
                    $parent = $creating ? null : $state->nodes[$id]['parent_id'];
                    $path = trim($row['category_path'] ?? '');
                    $column = 'category_path';
                    if ($path !== '') {
                        $parts = array_map(trim(...), explode($separator, $path));
                        if (in_array('', $parts, true) || array_filter($parts, fn ($p): bool => mb_strlen($p) > 200 || preg_match('/[\x00-\x1f]/', $p) === 1)) {
                            throw new RuntimeException(__('category_import.invalid_path'));
                        }
                        $leaf = array_pop($parts);
                        $nameColumn = 'name.'.$state->default;
                        if (isset($row[$nameColumn]) && trim($row[$nameColumn]) !== '' && trim($row[$nameColumn]) !== $leaf) {
                            throw new RuntimeException(__('category_import.path_name'));
                        }
                        $row[$nameColumn] = $leaf;
                        $parent = null;
                        foreach ($parts as $index => $name) {
                            $match = $state->find($parent, $name);
                            if ($match === null) {
                                if (! $canCreate) {
                                    throw new AuthorizationException;
                                }
                                $match = 'new:'.$number.':'.$index;
                                $this->operation($state, $match, $parent, ['name.'.$state->default => $name], true, $number, $emit, $report, $validationRules, $validationAttributes);
                                $report['parents']++;
                            }
                            if (! $creating && $match === $id) {
                                throw new RuntimeException(__('categories.errors.parent'));
                            }
                            $parent = $match;
                        }
                        if ($creating && isset($paths[$path])) {
                            throw new RuntimeException(__('category_import.duplicate_path'));
                        }
                        $paths[$path] = true;
                    }
                    if ($creating) {
                        $id = 'new:'.$number.':leaf';
                        $name = trim($row['name.'.$state->default] ?? '');
                        if ($name !== '' && $state->find($parent, $name) !== null) {
                            throw new RuntimeException(__('category_import.exists'));
                        }
                    }
                    $state->assertParent($id, $parent);
                    $this->operation($state, $id, $parent, $row, $creating, $number, $emit, $report, $validationRules, $validationAttributes);
                } catch (ValidationException $failure) {
                    foreach ($failure->errors() as $field => $messages) {
                        $field = preg_replace('/^translations\.([^.]+)\.(.+)$/', '$2.$1', $field);
                        $this->issue($report, $number, $row, $field, $messages[0]);
                    }
                } catch (AuthorizationException $failure) {
                    $this->issue($report, $number, $row, 'category_id', __('category_import.denied'));
                } catch (RuntimeException $failure) {
                    $this->issue($report, $number, $row, $column, $failure->getMessage());
                }
            }
            if ($report['rows'] === 0) {
                throw new RuntimeException(__('category_import.empty'));
            }
        } catch (RuntimeException $failure) {
            $this->issue($report, $currentRow, [], 'file', $failure->getMessage());
        }

        return $report;
    }

    /**
     * Customer group IDs separated by commas, semicolons, pipes or spaces; `all` removes the restriction.
     * A disabled group may stay assigned but cannot be newly assigned.
     *
     * @param  list<string>  $current
     * @return list<string>
     */
    private function customerGroups(string $value, CategoryImportState $state, array $current): array
    {
        if (strtolower($value) === 'all') {
            return [];
        }
        $ids = preg_split('/[\s,;|]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($ids as $group) {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $group) !== 1 || ! isset($state->groupIds[$group])) {
                throw ValidationException::withMessages(['customer_groups' => __('category_import.customer_groups_invalid', ['group' => mb_substr($group, 0, 30)])]);
            }
            if (! $state->groupIds[$group] && ! in_array($group, $current, true)) {
                throw ValidationException::withMessages(['customer_groups' => __('categories.customer_group_disabled')]);
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 100) {
            throw ValidationException::withMessages(['customer_groups' => __('category_import.customer_groups_invalid', ['group' => '…'])]);
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param array<string, mixed> $report
     * @param  array<string, string>  $row
     */
    private function issue(array &$report, int $number, array $row, string $column, string $reason): void
    {
        $report['errors']++;
        // Bound browser/session payloads; an invalid file must be corrected as a whole.
        if (count($report['issues']) < 200) {
            $identifier = trim($row['category_id'] ?? '') !== '' ? $row['category_id'] : ($row['category_path'] ?? '');
            $report['issues'][] = ['row' => $number, 'identifier' => mb_substr($identifier, 0, 300), 'column' => $column, 'reason' => $reason, 'severity' => 'error'];
        }
    }

    /** @param array<string, string> $row
     * @param  Closure(array<string, mixed>): void  $emit
     * @param  array<string, mixed>  $report
     * @param  array<string, list<mixed>>  $validationRules
     * @param  array<string, string>  $validationAttributes
     */
    private function operation(CategoryImportState $state, string $id, ?string $parent, array $row, bool $creating, int $number, Closure $emit, array &$report, array $validationRules, array $validationAttributes): void
    {
        $old = $creating ? ['parent_id' => null, 'position' => $state->nextPosition($parent), 'status' => 'disabled', 'system_key' => null, 'age_verification' => false, 'age_limit' => null, 'exclude_from_discovery' => false, 'customer_groups' => [], 'image_media_id' => null, 'lock_version' => 1, 'translations' => []] : $state->nodes[$id];
        $node = $old;
        $node['parent_id'] = $parent;
        foreach (['position', 'status'] as $field) {
            if (isset($row[$field]) && trim($row[$field]) !== '') {
                $node[$field] = trim($row[$field]);
            }
        }
        foreach (['age_verification', 'exclude_from_discovery', 'remove_image'] as $field) {
            if (isset($row[$field]) && trim($row[$field]) !== '') {
                if (! in_array(trim($row[$field]), ['true', 'false', '1', '0'], true)) {
                    throw ValidationException::withMessages([$field => __('category_import.boolean')]);
                }
                $node[$field] = in_array(trim($row[$field]), ['true', '1'], true);
            }
        }
        $node['remove_image'] ??= false;
        $imageUrl = trim($row['image_url'] ?? '');
        if ($imageUrl !== '' && (! RemoteImageDownload::validUrl($imageUrl) || $node['remove_image'])) {
            throw ValidationException::withMessages(['image_url' => __('category_import.image_url_invalid')]);
        }
        if ($imageUrl !== '' && ++$report['images'] > (int) config('category-import.max_image_urls')) {
            throw ValidationException::withMessages(['image_url' => __('category_import.image_count_limit', ['count' => config('category-import.max_image_urls')])]);
        }
        if (isset($row['customer_groups']) && trim($row['customer_groups']) !== '') {
            $node['customer_groups'] = $this->customerGroups(trim($row['customer_groups']), $state, $old['customer_groups']);
        }
        if (array_key_exists('age_limit', $row)) {
            $node['age_limit'] = trim($row['age_limit']) === '' ? null : trim($row['age_limit']);
        }
        $translations = [];
        $originalTranslations = [];
        foreach ($state->localeIds as $code => $localeId) {
            $supplied = [];
            foreach (CategoryImportFields::TRANSLATED as $field) {
                if (array_key_exists($field.'.'.$code, $row)) {
                    $supplied[$field] = $row[$field.'.'.$code];
                }
            }
            if ($supplied === []) {
                continue;
            }
            $existing = $creating ? [] : $state->translation($id, $code);
            $originalTranslations[$code] = $existing;
            if ($existing === [] && ! array_filter($supplied, fn ($value): bool => trim($value) !== '')) {
                continue;
            }
            $fields = [...array_fill_keys(CategoryImportFields::TRANSLATED, ''), ...$existing, ...$supplied];
            $fields = $this->input->normalize([$code => $fields])[$code];
            $translations[$code] = $fields;
            $slugKey = $localeId.':'.$fields['slug'];
            if (isset($state->slugs[$slugKey]) && $state->slugs[$slugKey] !== $id) {
                throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('category_import.slug_conflict')]);
            }
        }
        $rules = $validationRules;
        if ($creating) {
            $rules['translations'][] = 'min:1';
        }
        $rules['parent_id'] = ['nullable']; // Virtual parents are resolved by the checked in-memory tree.
        foreach ($state->localeIds as $code => $unused) {
            array_pop($rules['translations.'.$code.'.slug']); // State enforces DB + earlier-file slug uniqueness.
        }
        Validator::make([...$node, 'version' => $old['lock_version'], 'translations' => $translations], $rules, [], $validationAttributes)->validate();
        $node['position'] = (int) $node['position'];
        $node['age_limit'] = $node['age_limit'] === null ? null : (int) $node['age_limit'];
        $changed = $imageUrl !== '' || $creating || $node['parent_id'] !== $old['parent_id'] || $node['position'] !== $old['position'] || $node['status'] !== $old['status'] || $node['age_verification'] !== $old['age_verification'] || $node['age_limit'] !== $old['age_limit'] || $node['exclude_from_discovery'] !== $old['exclude_from_discovery'] || $node['customer_groups'] !== $old['customer_groups'] || ($node['remove_image'] && $old['image_media_id'] !== null);
        foreach ($translations as $code => $fields) {
            $changed = $changed || $fields !== $originalTranslations[$code];
        }
        if ($changed && ($creating || $node['parent_id'] !== $old['parent_id'] || $node['position'] !== $old['position'])) {
            $state->reserve($id, $parent, $node['position']);
        }
        // Retain only identity metadata; full descriptions live in the streamed operation spool.
        $node['translations'] = array_map(fn ($fields): array => array_intersect_key($fields, array_flip(['name', 'slug'])), $translations);
        $node['name'] = $translations[$state->default]['name'] ?? $old['name'] ?? '';
        $state->put($id, $node);
        $action = $creating ? 'created' : ($changed ? 'updated' : 'skipped');
        $report[$action]++;
        if (count($report['sample']) < 30) {
            $report['sample'][] = ['row' => $number, 'action' => $action, 'name' => $node['name'] !== '' ? $node['name'] : ($translations[array_key_first($translations)]['name'] ?? ''), 'path' => mb_substr($row['category_path'] ?? '', 0, 300), 'status' => $node['status'], 'position' => $node['position']];
        }
        if ($changed) {
            $emit(['row' => $number, 'id' => $id, 'creating' => $creating, 'parent' => $parent, 'position' => $node['position'], 'status' => $node['status'], 'translations' => $translations, 'remove_image' => $node['remove_image'], 'image_url' => $imageUrl, 'age_verification' => $node['age_verification'], 'age_limit' => $node['age_limit'], 'exclude_from_discovery' => $node['exclude_from_discovery'], 'customer_groups' => $node['customer_groups']]);
        }
    }
}
