<?php

namespace App\Catalog\Infrastructure\Import;

use App\Catalog\Infrastructure\Brands;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\RemoteDownload;
use App\Shared\Infrastructure\Import\CsvReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class BrandImportPlanner
{
    /** @return array{report:array<string,mixed>,operations:list<array<string,mixed>>} */
    public function plan(UserRecord $actor, string $file, string $delimiter): array
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
        $report = ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'images' => 0, 'issues' => [], 'sample' => [], 'status' => 'preview', 'fileHash' => hash_file('sha256', $file)];
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($locales, JSON_THROW_ON_ERROR));
        $headers = $seen = $operations = [];
        $number = 1;
        try {
            foreach ((new CsvReader)->rows($file, $delimiter, 'brand-import', 'brand_import') as $number => $cells) {
                if ($number === 1) {
                    $headers = array_map(trim(...), $cells);
                    if (! in_array('code', $headers, true) || count($headers) !== count(array_unique($headers)) || array_diff($headers, app(BrandImportFields::class)->columns())) {
                        throw new RuntimeException(__('brand_import.headers'));
                    }

                    continue;
                }
                if ($cells === ['']) {
                    continue;
                }
                $report['rows']++;
                $code = '';
                try {
                    if (count($cells) !== count($headers)) {
                        throw new RuntimeException(__('brand_import.width'));
                    }
                    $csv = array_combine($headers, array_map(trim(...), $cells));
                    $code = strtolower($csv['code']);
                    if ($code === '' || isset($seen[$code])) {
                        throw new RuntimeException(__('brand_import.duplicate_code'));
                    }
                    $seen[$code] = true;
                    Gate::forUser($actor)->authorize('brands.manage');
                    $row = DB::table('brands')->where('code', $code)->first();
                    $texts = $row === null ? [] : DB::table('brand_translations')->where('brand_id', $row->id)->get()->keyBy('locale_id')->all();
                    $files = $row === null ? [] : DB::table('brand_files')->where('brand_id', $row->id)->orderBy('position')->get()->all();
                    $input = ['code' => $code, 'status' => $row->status ?? null, 'position' => $row->position ?? null, 'translations' => [], 'version' => $row->lock_version ?? 1];
                    foreach ($locales as $locale => $id) {
                        $input['translations'][$locale] = array_intersect_key((array) ($texts[$id] ?? []), array_flip(BrandImportFields::TEXT));
                        $input['translations'][$locale] += ['name' => '', 'description' => '', 'image_alt' => '', 'banner_alt' => ''];
                    }
                    foreach ($csv as $column => $value) {
                        if ($column === 'code' || $value === '') {
                            continue;
                        }
                        if (str_contains($column, '.')) {
                            [$field, $locale] = explode('.', $column, 2);
                            $input['translations'][$locale][$field] = $value === '__CLEAR__' ? '' : $value;
                        } elseif (in_array($column, ['status', 'position', 'banner_link'], true)) {
                            $input[$column] = $value === '__CLEAR__' ? null : $value;
                        }
                    }
                    $valid = Validator::make($input, app(Brands::class)->rules($row === null ? null : (string) $row->id))->validate();
                    $valid['version'] = $input['version'];
                    $downloads = [];
                    foreach (['image', 'banner_image', 'attachment'] as $kind) {
                        $url = $csv[$kind.'_url'] ?? '';
                        $flag = $csv[$kind === 'attachment' ? 'remove_attachments' : 'remove_'.$kind] ?? '';
                        if (! in_array(strtolower($flag), ['', '0', '1', 'true', 'false'], true)) {
                            throw ValidationException::withMessages([$kind.'_url' => __('brand_import.boolean')]);
                        }
                        $remove = in_array(strtolower($flag), ['1', 'true'], true);
                        if ($url !== '' && (! RemoteDownload::validUrl($url) || ($remove && $kind !== 'attachment'))) {
                            throw ValidationException::withMessages([$kind.'_url' => __('brand_import.url_invalid')]);
                        }
                        $valid[$kind === 'attachment' ? 'remove_attachments' : 'remove_'.$kind] = $remove;
                        if ($url !== '') {
                            if (++$report['images'] > config('brand-import.max_downloads')) {
                                throw new RuntimeException(__('brand_import.download_limit'));
                            }
                            $downloads[$kind] = $url;
                        }
                    }
                    $name = $csv['attachment_name'] ?? '';
                    if (isset($downloads['attachment'])) {
                        $name = $name !== '' ? $name : rawurldecode(basename((string) parse_url($downloads['attachment'], PHP_URL_PATH)));
                        if (strlen($name) > 255 || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $name) || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), config('products.private_files.extensions'), true)) {
                            throw ValidationException::withMessages(['attachment_name' => __('brand_import.attachment_name_invalid')]);
                        }
                        if (! $valid['remove_attachments'] && count($files) >= 50) {
                            throw ValidationException::withMessages(['attachment_url' => __('brand_import.attachment_limit')]);
                        }
                    } elseif ($name !== '') {
                        throw ValidationException::withMessages(['attachment_name' => __('brand_import.attachment_name_invalid')]);
                    }
                    hash_update($hash, json_encode([$row, $texts, $files, $valid], JSON_THROW_ON_ERROR));
                    $action = $row === null ? 'created' : (count(array_filter($csv, fn ($v, $k): bool => $k !== 'code' && $v !== '', ARRAY_FILTER_USE_BOTH)) ? 'updated' : 'skipped');
                    $report[$action]++;
                    $operations[] = ['row' => $number, 'id' => $row === null ? null : (string) $row->id, 'fields' => $valid, 'downloads' => $downloads, 'attachment_name' => $name, 'action' => $action];
                    if (count($report['sample']) < 50) {
                        $report['sample'][] = ['row' => $number, 'action' => $action, 'code' => $code, 'name' => $valid['translations'][app(LocaleCatalog::class)->defaultCode()]['name'], 'status' => $valid['status']];
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $column => $messages) {
                        $this->issue($report, $number, $code, $column, implode(' ', $messages));
                    }
                } catch (AuthorizationException) {
                    $this->issue($report, $number, $code, 'code', __('brand_import.denied'));
                } catch (RuntimeException $e) {
                    $this->issue($report, $number, $code, 'code', $e->getMessage());
                }
            }
            if ($report['rows'] === 0) {
                throw new RuntimeException(__('brand_import.empty'));
            }
        } catch (RuntimeException $e) {
            $this->issue($report, $number, '', 'file', $e->getMessage());
        }
        $report['fingerprint'] = hash_final($hash);

        return ['report' => $report, 'operations' => $operations];
    }

    /** @param array<string,mixed> $report */
    private function issue(array &$report, int $row, string $code, string $column, string $reason): void
    {
        $report['errors']++;
        if (count($report['issues']) < 500) {
            $report['issues'][] = ['row' => $row, 'identifier' => $code, 'column' => $column, 'reason' => $reason, 'severity' => 'error'];
        }
    }
}
