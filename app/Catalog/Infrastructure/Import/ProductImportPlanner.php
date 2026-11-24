<?php

namespace App\Catalog\Infrastructure\Import;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductInput;
use App\Catalog\Infrastructure\ProductState;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\RemoteImageDownload;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Import\CsvReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Preview is read-only. Confirmation validates again under the catalog mutation lock. */
final class ProductImportPlanner
{
    /** @return array{report:array<string,mixed>,operations:list<array<string,mixed>>} */
    public function plan(UserRecord $actor, string $file, string $delimiter): array
    {
        $fields = app(ProductImportFields::class);
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
        $default = app(LocaleCatalog::class)->defaultCode();
        $report = ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'images' => 0, 'issues' => [], 'sample' => [], 'status' => 'preview', 'fileHash' => hash_file('sha256', $file)];
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$locales, $default, app(CurrencyRepository::class)->currentPrimary(), DB::table('feature_catalog_state')->first()], JSON_THROW_ON_ERROR));
        $headers = $seen = $operations = [];
        $number = 1;
        try {
            foreach ((new CsvReader)->rows($file, $delimiter, 'product-import', 'product_import') as $number => $values) {
                if ($number === 1) {
                    $headers = array_map(trim(...), $values);
                    if (! in_array('sku', $headers, true) || count($headers) !== count(array_unique($headers)) || array_diff($headers, $fields->columns()) !== []) {
                        throw new RuntimeException(__('product_import.headers'));
                    }

                    continue;
                }
                if ($values === ['']) {
                    continue;
                }
                $report['rows']++;
                $sku = '';
                try {
                    if (count($values) !== count($headers)) {
                        throw new RuntimeException(__('product_import.width'));
                    }
                    $row = array_combine($headers, $values);
                    $sku = trim($row['sku']);
                    if ($sku === '' || mb_strlen($sku) > 200 || preg_match('/[\x00-\x1f\x7f]/', $sku)) {
                        throw new RuntimeException(__('products.sku_required'));
                    }
                    $key = DB::selectOne('SELECT lower(?) AS sku', [$sku])->sku;
                    if (isset($seen[$key])) {
                        throw new RuntimeException(__('product_import.duplicate_sku'));
                    }
                    $seen[$key] = true;
                    $existing = ProductRecord::withTrashed()->whereRaw('lower(sku) = lower(?)', [$sku])->first();
                    if ($existing?->trashed()) {
                        throw new RuntimeException(__('product_import.trashed'));
                    }
                    $creating = $existing === null;
                    Gate::forUser($actor)->authorize($creating ? 'products.create' : 'products.update');
                    Gate::forUser($actor)->authorize('products.update');
                    $input = ['sku' => $existing->sku ?? $sku, 'translations' => [$default => []]];
                    foreach ($row as $column => $value) {
                        if ($column === 'sku' || $value === '') {
                            continue;
                        }
                        $value = trim($value);
                        if ($value === '') {
                            continue;
                        }
                        $clear = $value === '__CLEAR__';
                        if (in_array($column, ProductImportFields::BOOLEANS, true)) {
                            $value = match (strtolower($value)) {
                                '1', 'true' => true, '0', 'false' => false, default => throw new RuntimeException(__('product_import.boolean'))
                            };
                        } elseif (in_array($column, ['tax_ids', 'customer_groups'], true)) {
                            $value = $clear ? [] : explode('|', $value);
                        } elseif ($clear) {
                            $value = null;
                        }
                        if (str_contains($column, '.')) {
                            [$name, $locale] = explode('.', $column, 2);
                            $input['translations'][$locale][$name] = $value;
                        } elseif (in_array($column, ProductImportFields::SCALARS, true) || in_array($column, ['tax_ids', 'customer_groups'], true)) {
                            $input[$column] = $value;
                        }
                    }
                    foreach (['type', 'status', 'price', 'category_id'] as $required) {
                        if (! array_key_exists($required, $input)) {
                            $input[$required] = $existing?->getAttribute($required);
                            if ($required === 'price' && $input[$required] !== null) {
                                $input[$required] = Decimal::trim((string) $input[$required]);
                            }
                        }
                    }
                    if ($existing !== null && $input['status'] !== $existing->status) {
                        Gate::forUser($actor)->authorize('products.status');
                    }
                    if ($creating && $input['status'] !== 'disabled') {
                        Gate::forUser($actor)->authorize('products.status');
                    }
                    $input['version'] = $existing->lock_version ?? 1;
                    $validated = app(ProductInput::class)->validate($input);
                    $candidate = $existing === null ? new ProductRecord : clone $existing;
                    $candidate->fill(array_intersect_key($validated, array_flip(ProductImportFields::SCALARS)));
                    app(ProductInput::class)->assertPackaging($candidate);
                    foreach ([['min_qty', 'max_qty'], ['box_min_items', 'box_max_items']] as [$min, $max]) {
                        if (! (new ProductRules)->limitsConsistent($candidate->$min, $candidate->$max)) {
                            throw ValidationException::withMessages([$max => __('products.'.($max === 'max_qty' ? 'max_qty_below_min' : 'box_max_below_min'))]);
                        }
                    }

                    if (! app(ProductState::class)->validCategory((string) $validated['category_id'])) {
                        throw ValidationException::withMessages(['category_id' => __('products.leaf_required')]);
                    }
                    $currentTranslations = $existing?->translations()->get()->keyBy('locale_id')->toArray() ?? [];
                    $name = array_key_exists('name', $validated['translations'][$default] ?? []) ? $validated['translations'][$default]['name'] : ($currentTranslations[$locales[$default]]['name'] ?? '');
                    if (trim((string) $name) === '') {
                        throw ValidationException::withMessages(['name.'.$default => __('products.name_required')]);
                    }
                    $url = trim($row['image_url'] ?? '');
                    $remove = strtolower(trim($row['remove_image'] ?? 'false'));
                    if (! in_array($remove, ['', '0', '1', 'true', 'false'], true) || ($url !== '' && in_array($remove, ['1', 'true'], true)) || ($url !== '' && ! RemoteImageDownload::validUrl($url))) {
                        throw ValidationException::withMessages(['image_url' => __('product_import.image_invalid')]);
                    }
                    if ($url !== '' && ++$report['images'] > config('product-import.max_image_urls')) {
                        throw new RuntimeException(__('product_import.image_batch_limit'));
                    }
                    hash_update($hash, json_encode([$key, $existing?->getAttributes(), $currentTranslations, $validated, DB::table('categories')->where('id', $validated['category_id'])->first(), $existing === null ? [] : DB::table('product_images')->where('product_id', $existing->id)->orderBy('position')->get()->all()], JSON_THROW_ON_ERROR));
                    $action = 'updated';
                    if ($creating) {
                        $action = 'created';
                    } elseif (count(array_filter($row, fn ($v, $k): bool => $k !== 'sku' && trim($v) !== '', ARRAY_FILTER_USE_BOTH)) === 0) {
                        $action = 'skipped';
                    }
                    $report[$action]++;
                    $operations[] = ['row' => $number, 'id' => $existing === null ? null : (string) $existing->id, 'version' => $validated['version'], 'fields' => $validated, 'image_url' => $url, 'remove_image' => in_array($remove, ['1', 'true'], true), 'action' => $action];
                    if (count($report['sample']) < 50) {
                        $report['sample'][] = ['row' => $number, 'action' => $action, 'sku' => $sku, 'name' => (string) $name, 'status' => $validated['status']];
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $column => $messages) {
                        $this->issue($report, $number, $sku, $column, implode(' ', $messages));
                    }
                } catch (AuthorizationException) {
                    $this->issue($report, $number, $sku, 'sku', __('product_import.denied'));
                } catch (RuntimeException $e) {
                    $this->issue($report, $number, $sku, 'sku', $e->getMessage());
                }
            }
            if ($headers === [] || $report['rows'] === 0) {
                throw new RuntimeException(__('product_import.empty'));
            }
        } catch (RuntimeException $e) {
            $this->issue($report, $number, '', 'file', $e->getMessage());
        }
        $report['fingerprint'] = hash_final($hash);

        return ['report' => $report, 'operations' => $operations];
    }

    /** @param array<string,mixed> $report */
    private function issue(array &$report, int $row, string $sku, string $column, string $reason): void
    {
        $report['errors']++;
        if (count($report['issues']) < 500) {
            $report['issues'][] = ['row' => $row, 'identifier' => $sku, 'column' => $column, 'reason' => $reason, 'severity' => 'error'];
        }
    }
}
