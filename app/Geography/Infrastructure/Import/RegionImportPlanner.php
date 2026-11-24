<?php

namespace App\Geography\Infrastructure\Import;

use App\Geography\Infrastructure\RegionInput;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Geography\Countries;
use App\Shared\Infrastructure\Import\CsvReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class RegionImportPlanner
{
    public function __construct(private CsvReader $reader, private RegionImportFields $fields, private RegionInput $input) {}

    /** @return array{report: array<string, mixed>, operations: list<array<string, mixed>>} */
    public function plan(string $file, string $delimiter): array
    {
        $locales = app(LocaleCatalog::class);
        $active = $locales->active()->pluck('id', 'code')->all();
        $shipping = DB::table('shipping_countries')->orderBy('country_code')->pluck('country_code')->all();
        $records = DB::table('regions')->orderBy('id')->get(['id', 'country_code', 'code', 'status', 'lock_version']);
        $translations = DB::table('region_translations')->join('locales', 'locales.id', '=', 'region_translations.locale_id')->orderBy('region_id')->orderBy('locale_id')->get(['region_id', 'locale_id', 'locales.code', 'region_translations.name']);
        $fingerprint = hash('sha256', json_encode([$locales->defaultCode(), $active, $shipping, $records, $translations], JSON_THROW_ON_ERROR));
        $nodes = [];
        $keys = [];
        foreach ($records as $record) {
            $id = (string) $record->id;
            $nodes[$id] = ['id' => $id, 'country_code' => $record->country_code, 'code' => $record->code, 'status' => $record->status, 'version' => (int) $record->lock_version, 'names' => []];
            $keys[$record->country_code.':'.$record->code] = $id;
        }
        foreach ($translations as $translation) {
            if (array_key_exists($translation->code, $active)) {
                $nodes[(string) $translation->region_id]['names'][$translation->code] = $translation->name;
            }
        }
        $report = ['status' => 'preview', 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'issues' => [], 'sample' => [], 'fingerprint' => $fingerprint, 'fileHash' => hash_file('sha256', $file)];
        $operations = [];
        $seen = [];
        $headers = [];
        try {
            foreach ($this->reader->rows($file, $delimiter, 'region-import', 'region_import') as $number => $values) {
                if ($number === 1) {
                    $headers = array_map(trim(...), $values);
                    if ($headers === [''] || count(array_unique($headers)) !== count($headers) || array_diff($headers, $this->fields->columns()) !== []) {
                        $this->issue($report, 1, [], 'file', __('region_import.headers'));

                        return ['report' => $report, 'operations' => []];
                    }

                    continue;
                }
                if ($values === ['']) {
                    continue;
                }
                $report['rows']++;
                $row = count($values) === count($headers) ? array_combine($headers, $values) : [];
                try {
                    if ($row === []) {
                        throw ValidationException::withMessages(['file' => __('region_import.width')]);
                    }
                    $requestedId = trim($row['region_id'] ?? '');
                    $country = strtoupper(trim($row['country_code'] ?? ''));
                    $code = strtoupper(trim($row['code'] ?? ''));
                    if ($requestedId !== '') {
                        if (preg_match('/^[1-9][0-9]{0,18}$/D', $requestedId) !== 1 || ! isset($nodes[$requestedId]) || str_starts_with($requestedId, 'new:')) {
                            throw ValidationException::withMessages(['region_id' => __('region_import.invalid_id')]);
                        }
                        $old = $nodes[$requestedId];
                        if ($country !== '' && $country !== $old['country_code']) {
                            throw ValidationException::withMessages(['country_code' => __('region_import.country_immutable')]);
                        }
                        $country = $old['country_code'];
                        $code = $code === '' ? $old['code'] : $code;
                    } else {
                        $old = isset($keys[$country.':'.$code]) ? $nodes[$keys[$country.':'.$code]] : null;
                    }
                    if (! app(Countries::class)->valid($country) || ! in_array($country, $shipping, true)) {
                        throw ValidationException::withMessages(['country_code' => __('region_import.shipping_required')]);
                    }
                    $creating = $old === null;
                    $id = $old['id'] ?? 'new:'.$number;
                    if (isset($seen[$id]) || isset($seen['key:'.$country.':'.$code])) {
                        throw ValidationException::withMessages(['region_id' => __('region_import.duplicate')]);
                    }
                    if (isset($keys[$country.':'.$code]) && $keys[$country.':'.$code] !== $id) {
                        throw ValidationException::withMessages(['code' => __('geography.code_taken')]);
                    }
                    $names = $old['names'] ?? [];
                    foreach ($active as $locale => $unused) {
                        if (array_key_exists('name.'.$locale, $row)) {
                            $names[$locale] = $row['name.'.$locale];
                        }
                    }
                    $status = trim($row['status'] ?? '') ?: ($old['status'] ?? 'disabled');
                    [$code, $names] = $this->input->validate($code, $names, $status);
                    $seen[$id] = true;
                    $seen['key:'.$country.':'.$code] = true;
                    $oldNames = $old['names'] ?? [];
                    ksort($names);
                    ksort($oldNames);
                    $changed = $creating || $code !== $old['code'] || $status !== $old['status'] || $names !== $oldNames;
                    if (! $creating) {
                        unset($keys[$country.':'.$old['code']]);
                    }
                    $node = ['id' => $id, 'country_code' => $country, 'code' => $code, 'status' => $status, 'version' => $old['version'] ?? null, 'names' => $names];
                    $nodes[$id] = $node;
                    $keys[$country.':'.$code] = $id;
                    $action = $creating ? 'created' : ($changed ? 'updated' : 'skipped');
                    $report[$action]++;
                    if (count($report['sample']) < 30) {
                        $report['sample'][] = ['row' => $number, 'action' => $action, 'name' => $names[$locales->defaultCode()], 'country' => $country, 'code' => $code, 'status' => $status];
                    }
                    if ($changed) {
                        $operations[] = [...$node, 'row' => $number, 'creating' => $creating];
                    }
                } catch (ValidationException $failure) {
                    foreach ($failure->errors() as $field => $messages) {
                        $this->issue($report, $number, $row, str_replace('names.', 'name.', $field), $messages[0]);
                    }
                }
            }
            if ($report['rows'] === 0 && $report['errors'] === 0) {
                $this->issue($report, 1, [], 'file', __('region_import.empty'));
            }
        } catch (RuntimeException $failure) {
            $this->issue($report, 0, [], 'file', $failure->getMessage());
        }

        return ['report' => $report, 'operations' => $operations];
    }

    /** @param array<string, mixed> $report
     * @param  array<string, string>  $row
     */
    private function issue(array &$report, int $number, array $row, string $column, string $reason): void
    {
        $report['errors']++;
        if (count($report['issues']) < 200) {
            $identifier = trim($row['region_id'] ?? '') ?: trim(($row['country_code'] ?? '').':'.($row['code'] ?? ''));
            $report['issues'][] = ['row' => $number, 'identifier' => mb_substr($identifier, 0, 200), 'column' => $column, 'reason' => $reason, 'severity' => 'error'];
        }
    }
}
