<?php

namespace App\Admin\Controllers;

use App\Admin\DataTransfer\DataTransferExports;
use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Shared\Infrastructure\Export\CsvCell;
use App\Shared\Infrastructure\Export\ExportSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One field-picker screen and one CSV download for every entity registered in DataTransferExports. */
final class DataTransferExportController
{
    private const DELIMITERS = [',', ';'];

    public function show(Request $request, AdminNavigation $navigation, DataTransferExports $exports, string $entity): Response
    {
        $source = $exports->get($entity);
        Gate::authorize($source->ability());
        [$filters, $ids] = $this->scope($request, $source);

        return Inertia::render('Admin/DataTransfer/Export', [
            'navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [
                ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
                ['label' => __($source->title()), 'href' => route($source->indexRoute(), [], false)],
                ['label' => __('admin.data_transfer.export_title', ['entity' => __($source->title())]), 'href' => null],
            ],
            'entity' => $entity,
            'title' => __($source->title()),
            'backHref' => route($source->indexRoute(), [], false),
            'columns' => $source->columns(),
            'delimiters' => self::DELIMITERS,
            'scope' => ['filters' => (object) $filters, 'ids' => $ids],
        ]);
    }

    public function download(Request $request, DataTransferExports $exports, string $entity): StreamedResponse
    {
        $source = $exports->get($entity);
        Gate::authorize($source->ability());
        [$filters, $ids] = $this->scope($request, $source);
        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'], 'columns.*' => ['string', Rule::in($source->columns())],
            'delimiter' => ['required', Rule::in(self::DELIMITERS)],
        ]);
        $columns = array_values(array_intersect($source->columns(), $data['columns']));

        app(AuditLogger::class)->record(new AuditEvent(Str::singular($entity).'.exported', Str::singular($entity)));

        return response()->streamDownload(function () use ($source, $columns, $filters, $ids, $data): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_map(CsvCell::text(...), $columns), $data['delimiter'], '"', '');
            foreach ($source->rows($columns, $filters, $ids) as $row) {
                fputcsv($output, $row, $data['delimiter'], '"', '');
            }
            fclose($output);
        }, $entity.'-export.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} Non-empty list filters and explicitly selected IDs. */
    private function scope(Request $request, ExportSource $source): array
    {
        $validated = $request->validate([...$source->filterRules(), 'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer', 'min:1']]);

        return [array_filter(array_diff_key($validated, ['ids' => 1]), fn ($value): bool => $value !== null && $value !== ''), array_map(strval(...), $validated['ids'] ?? [])];
    }
}
