<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Infrastructure\Import\BrandCsvImporter;
use App\Catalog\Infrastructure\Import\BrandImportFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandImportController
{
    public function index(Request $request, AdminNavigation $navigation, BrandImportFields $fields): Response
    {
        Gate::authorize('brands.import');
        $data = $request->validate(['token' => ['nullable', 'uuid']]);
        $entry = isset($data['token']) ? $this->entry($request, $data['token']) : null;

        return Inertia::render('Admin/Brands/Import', [
            'navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [
                ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
                ['label' => __('navigation.data_transfer'), 'href' => route('admin.data-transfer.index', [], false)],
                ['label' => __('brand_import.title'), 'href' => null],
            ],
            'columns' => $fields->columns(), 'delimiters' => config('brand-import.delimiters'),
            'limits' => ['fileBytes' => (int) config('brand-import.max_kilobytes') * 1024, 'imageUrls' => (int) config('brand-import.max_downloads'), 'imageBytes' => (int) config('brand-import.max_download_bytes')],
            'preview' => $entry === null ? null : ['token' => $data['token'], 'filename' => $entry['filename'], ...array_diff_key($entry['report'], array_flip(['fingerprint', 'fileHash']))],
        ]);
    }

    public function preview(Request $request, BrandCsvImporter $handler): RedirectResponse
    {
        Gate::authorize('brands.import');
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:'.config('brand-import.max_kilobytes')],
            'delimiter' => ['required', Rule::in(config('brand-import.delimiters'))],
        ]);
        $token = (string) Str::uuid();
        $path = $request->file('file')->storeAs('brand-imports', $token.'.csv', 'local');
        $entry = ['actor' => (string) $request->user()->getAuthIdentifier(), 'filename' => mb_substr(basename($request->file('file')->getClientOriginalName()), 0, 200), 'path' => $path, 'delimiter' => $data['delimiter']];
        try {
            $entry['report'] = $handler->preview($entry['actor'], Storage::disk('local')->path($path), $entry['delimiter']);
        } catch (\Throwable $failure) {
            Storage::disk('local')->delete($path);
            throw $failure;
        }
        Cache::put('brand-import:'.$token, $entry, config('brand-import.ttl_seconds'));

        return to_route('admin.brands.import.index', ['token' => $token]);
    }

    public function confirm(Request $request, BrandCsvImporter $handler, string $token): RedirectResponse
    {
        Gate::authorize('brands.import');
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $lock = Cache::lock('brand-import-lock:'.$token, 600);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['file' => __('brand_import.busy')]);
        }
        try {
            $entry = $this->entry($request, $token);
            if ($entry['report']['status'] !== 'preview' || $entry['report']['errors'] > 0) {
                throw ValidationException::withMessages(['file' => __('brand_import.repreview')]);
            }
            $entry['report']['status'] = 'processing';
            Cache::put('brand-import:'.$token, $entry, config('brand-import.ttl_seconds'));
            try {
                $entry['report']['status'] = 'preview';
                $entry['report'] = $handler->confirm($entry['actor'], Storage::disk('local')->path($entry['path']), $entry['delimiter'], $entry['report']);
                Log::info('brand_csv_import_file', ['actor_id' => $entry['actor'], 'filename' => $entry['filename'], 'rows' => $entry['report']['rows']]);
            } catch (ValidationException $failure) {
                $field = (string) array_key_first($failure->errors());
                $imageRow = preg_match('/^(image_url|banner_image_url|attachment_url)\.([0-9]+)$/D', $field, $matches) === 1;
                $entry['report'] = [...$entry['report'], 'status' => 'failed', 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'issues' => [['row' => $imageRow ? (int) $matches[2] : 0, 'column' => $imageRow ? $matches[1] : 'file', 'identifier' => '', 'severity' => 'error', 'reason' => $failure->getMessage()]]];
            } catch (\Throwable $failure) {
                // Consume an unexpectedly failed confirmation rather than offer a blind retry.
                $entry['report']['status'] = 'failed';
                throw $failure;
            } finally {
                Cache::put('brand-import:'.$token, $entry, config('brand-import.ttl_seconds'));
            }

            return to_route('admin.brands.import.index', ['token' => $token]);
        } finally {
            $lock->release();
        }
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('brands.import');
        $lock = Cache::lock('brand-import-lock:'.$token, 600);
        abort_unless($lock->get(), 409);
        try {
            $entry = $this->entry($request, $token);
            abort_if($entry['report']['status'] === 'processing', 409);
            Storage::disk('local')->delete($entry['path']);
            Cache::forget('brand-import:'.$token);
            app(AuditLogger::class)->record(new AuditEvent('brand.import_cancelled', 'brand'));
        } finally {
            $lock->release();
        }

        return to_route('admin.brands.import.index');
    }

    public function template(Request $request, BrandImportFields $fields): StreamedResponse
    {
        Gate::authorize('brands.import');
        $data = $request->validate(['delimiter' => ['nullable', Rule::in(config('brand-import.delimiters'))]]);

        app(AuditLogger::class)->record(new AuditEvent('brand.template_downloaded', 'brand'));

        return response()->streamDownload(function () use ($fields, $data): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $fields->columns(), $data['delimiter'] ?? ',', '"', '');
            fputcsv($output, array_values($fields->example()), $data['delimiter'] ?? ',', '"', '');
            fclose($output);
        }, 'brand-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function errors(Request $request, string $token): StreamedResponse
    {
        Gate::authorize('brands.import');
        $entry = $this->entry($request, $token);

        app(AuditLogger::class)->record(new AuditEvent('brand.import_errors_read', 'brand'));

        return response()->streamDownload(function () use ($entry): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['row', 'identifier', 'column', 'reason', 'severity'], ',', '"', '');
            foreach ($entry['report']['issues'] as $issue) {
                $cells = array_map(fn ($value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, [$issue['row'], $issue['identifier'], $issue['column'], $issue['reason'], $issue['severity']]);
                fputcsv($output, $cells, ',', '"', '');
            }
            fclose($output);
        }, 'brand-import-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function entry(Request $request, string $token): array
    {
        abort_unless(Str::isUuid($token), 404);
        $entry = Cache::get('brand-import:'.$token);
        abort_unless(is_array($entry) && $entry['actor'] === (string) $request->user()->getAuthIdentifier(), 404);

        return $entry;
    }
}
