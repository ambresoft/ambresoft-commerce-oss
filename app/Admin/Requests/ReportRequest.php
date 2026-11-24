<?php

namespace App\Admin\Requests;

use App\Pricing\Application\Service\PriceDisplay;
use App\Reporting\Application\Command\ReportFilters;
use App\Reporting\Domain\Exception\InvalidReportPeriod;
use App\Reporting\Domain\Model\ReportPeriod;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validated parameters of every report screen and export (all GET, all optional): period (preset or custom dates),
 * comparison, currency, record filters, sorting, paging and per-report view switches. Identifiers must exist and sort
 * columns are allow-listed by each report; nothing a client sends reaches a query as SQL.
 */
final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = (string) $this->route()?->defaults['report'];
        $export = str_ends_with((string) $this->route()?->getName(), '.export');

        // Reports that show who bought also need the right to see orders / customers, and so does narrowing by a customer.
        return Gate::allows($report === 'customers' ? 'reports.customers' : 'reports.view')
            && ($report !== 'customers' || Gate::allows('customers.view'))
            && ($report !== 'orders' || Gate::allows('orders.view'))
            && ($this->query('customer_id') === null || (Gate::allows('reports.customers') && Gate::allows('customers.view')))
            && (! $export || Gate::allows('reports.export'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(ReportPeriod::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'boolean'],
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/D'],
            'date' => ['nullable', Rule::in(['paid', 'created'])],
            'status_id' => ['nullable', 'integer', 'min:1', Rule::exists('order_statuses', 'id')],
            'payment_method_id' => ['nullable', 'integer', 'min:1', Rule::exists('payment_methods', 'id')],
            'shipping_method_id' => ['nullable', 'integer', 'min:1', Rule::exists('shipping_methods', 'id')],
            'product_id' => ['nullable', 'integer', 'min:1', Rule::exists('products', 'id')],
            'category_id' => ['nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::exists('customers', 'id')],
            'sort' => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+$/D'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'search' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+$/D'],
            'mode' => ['nullable', Rule::in(['direct', 'tree'])],
            'threshold' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'product_status' => ['nullable', Rule::in(['active', 'disabled', 'hidden'])],
        ];
    }

    /** The filters of this request, with the store timezone and primary currency as defaults. */
    public function filters(): ReportFilters
    {
        $zone = new DateTimeZone((string) app(GeneralSettings::class)->all()['timezone']);
        $preset = (string) ($this->validated('period') ?? 'last_30_days');
        try {
            if ($preset === 'custom') {
                $from = $this->validated('from');
                $to = $this->validated('to');
                if ($from === null || $to === null) {
                    throw new InvalidReportPeriod(InvalidReportPeriod::DATE);
                }
                $period = ReportPeriod::custom($from, $to, $zone);
            } else {
                $period = ReportPeriod::preset($preset, CarbonImmutable::now($zone));
            }
        } catch (InvalidReportPeriod $e) {
            throw ValidationException::withMessages([in_array($e->reason, [InvalidReportPeriod::ORDER, InvalidReportPeriod::TOO_LONG], true) ? 'to' : 'from' => __('reports.period_'.$e->reason)]);
        }
        $id = fn (string $key): ?int => $this->validated($key) === null ? null : (int) $this->validated($key);

        return new ReportFilters(
            $period, (bool) $this->validated('compare'), (string) ($this->validated('currency') ?? app(PriceDisplay::class)->primary()->code), (string) ($this->validated('date') ?? 'paid'),
            $id('status_id'), $id('payment_method_id'), $id('shipping_method_id'), $id('product_id'), $id('category_id'), $id('customer_id'),
        );
    }

    /** @param list<string> $allowed */
    public function sort(array $allowed, string $default): string
    {
        $sort = (string) ($this->validated('sort') ?? $default);

        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    public function direction(string $default = 'desc'): string
    {
        return (string) ($this->validated('direction') ?? $default);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 25);
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function text(string $key): string
    {
        return trim((string) ($this->validated($key) ?? ''));
    }

    /** @param list<string> $allowed */
    public function choice(string $key, array $allowed, string $default): string
    {
        $value = (string) ($this->validated($key) ?? $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
