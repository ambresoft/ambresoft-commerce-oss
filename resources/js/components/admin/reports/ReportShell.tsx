import EditorHeader from '../EditorHeader';
import { router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import DateInput from '../DateInput';
import FormErrors from '../FormErrors';
import Select from '../Select';
import { useTranslation } from '../../../lib/useTranslation';
import { useReportUrl } from '../../../lib/reportQuery';
import type { AdminShell } from '../../../types/admin';
import type { ReportCommon } from '../../../types/reports';

const presets = [
  'today',
  'yesterday',
  'last_7_days',
  'last_30_days',
  'this_month',
  'previous_month',
  'this_year',
  'custom',
] as const;
// Literal keys: translation keys are never built at runtime.
const presetLabel: Record<(typeof presets)[number], string> = {
  today: 'reports.period_today',
  yesterday: 'reports.period_yesterday',
  last_7_days: 'reports.period_last_7_days',
  last_30_days: 'reports.period_last_30_days',
  this_month: 'reports.period_this_month',
  previous_month: 'reports.period_previous_month',
  this_year: 'reports.period_this_year',
  custom: 'reports.period_custom',
};

/**
 * Title, shared filters and the explanatory strip every report screen has. Filters apply through the URL, so a report
 * can be bookmarked and reloaded; each report shows only the filters that make sense for it.
 */
export default function ReportShell({
  common,
  title,
  description,
  shell,
  children,
}: {
  common: ReportCommon;
  title: string;
  description: string;
  shell: AdminShell;
  children: ReactNode;
}) {
  const t = useTranslation();
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const { path, params, search } = useReportUrl();
  const applicable = (key: string) => common.applicable.includes(key);
  const [from, setFrom] = useState(common.period.from);
  const [to, setTo] = useState(common.period.to);

  // Everything except paging and per-report view state starts over when a filter changes.
  const keep = ['view', 'mode', 'search', 'sort', 'direction', 'per_page'];
  const apply = (changes: Record<string, string | null>) => {
    const next: Record<string, string> = {};
    for (const key of [
      'period',
      'from',
      'to',
      'compare',
      'currency',
      'date',
      'status_id',
      'payment_method_id',
      'shipping_method_id',
      'category_id',
      'product_id',
      'customer_id',
      ...keep,
    ]) {
      const value = params.get(key);
      if (value) next[key] = value;
    }
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') delete next[key];
      else next[key] = value;
    }
    router.get(path, next, { preserveScroll: true, preserveState: true });
  };
  const choose = (
    id: string,
    label: string,
    key: string,
    value: number | null,
    options: { value: string; label: string }[],
  ) => (
    <div className="space-y-1">
      <label className="block text-xs font-medium text-slate-600" htmlFor={id}>
        {label}
      </label>
      <Select
        id={id}
        value={value === null ? '' : String(value)}
        onChange={(v) => apply({ [key]: v })}
        options={[{ value: '', label: t('reports.all') }, ...options]}
      />
    </div>
  );
  const custom = common.period.preset === 'custom';

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        description={description}
        iconKey="reports"
        actions={
          common.links.can_export &&
          common.report !== 'overview' && (
            <a className="button-secondary" href={path + '/export' + search}>
              {t('reports.export_csv')}
            </a>
          )
        }
      />
      <FormErrors
        errors={Object.fromEntries(
          Object.entries(errors ?? {}).filter(([key]) =>
            ['period', 'from', 'to', 'currency'].includes(key),
          ),
        )}
      />
      {common.applicable.length > 0 && (
        <section
          aria-label={t('reports.filters')}
          className="mb-5 grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
        >
          {applicable('period') && (
            <div className="space-y-1">
              <label
                className="block text-xs font-medium text-slate-600"
                htmlFor="report-period"
              >
                {t('reports.period')}
              </label>
              <Select
                id="report-period"
                value={common.period.preset}
                onChange={(v) =>
                  v === 'custom'
                    ? apply({ period: 'custom', from, to })
                    : apply({ period: v, from: null, to: null })
                }
                options={presets.map((p) => ({
                  value: p,
                  label: t(presetLabel[p]),
                }))}
              />
            </div>
          )}
          {applicable('period') && custom && (
            <div className="grid grid-cols-2 gap-2 sm:col-span-2">
              <div className="space-y-1">
                <label
                  className="block text-xs font-medium text-slate-600"
                  htmlFor="report-from"
                >
                  {t('reports.from')}
                </label>
                <DateInput id="report-from" value={from} onChange={setFrom} />
              </div>
              <div className="space-y-1">
                <label
                  className="block text-xs font-medium text-slate-600"
                  htmlFor="report-to"
                >
                  {t('reports.to')}
                </label>
                <div className="flex gap-2">
                  <DateInput id="report-to" value={to} onChange={setTo} />
                  <button
                    type="button"
                    className="button-primary"
                    onClick={() => apply({ period: 'custom', from, to })}
                  >
                    {t('reports.apply')}
                  </button>
                </div>
              </div>
            </div>
          )}
          {applicable('currency') && common.currencies.length > 1 && (
            <div className="space-y-1">
              <label
                className="block text-xs font-medium text-slate-600"
                htmlFor="report-currency"
              >
                {t('reports.currency')}
              </label>
              <Select
                id="report-currency"
                value={common.filters.currency}
                onChange={(v) => apply({ currency: v })}
                options={common.currencies.map((c) => ({ value: c, label: c }))}
              />
            </div>
          )}
          {applicable('date') && (
            <div className="space-y-1">
              <label
                className="block text-xs font-medium text-slate-600"
                htmlFor="report-date"
              >
                {t('reports.date_basis')}
              </label>
              <Select
                id="report-date"
                value={common.filters.date}
                onChange={(v) => apply({ date: v === 'paid' ? null : v })}
                options={[
                  { value: 'paid', label: t('reports.date_paid') },
                  { value: 'created', label: t('reports.date_created') },
                ]}
              />
            </div>
          )}
          {applicable('status') &&
            choose(
              'report-status',
              t('reports.status'),
              'status_id',
              common.filters.status_id,
              common.options.statuses,
            )}
          {applicable('payment') &&
            choose(
              'report-payment',
              t('reports.payment_method'),
              'payment_method_id',
              common.filters.payment_method_id,
              common.options.payment_methods,
            )}
          {applicable('shipping') &&
            choose(
              'report-shipping',
              t('reports.shipping_method'),
              'shipping_method_id',
              common.filters.shipping_method_id,
              common.options.shipping_methods,
            )}
          {applicable('category') &&
            choose(
              'report-category',
              t('reports.category'),
              'category_id',
              common.filters.category_id,
              common.options.categories,
            )}
          {applicable('compare') && (
            <label className="flex items-center gap-2 self-end pb-2 text-sm">
              <input
                type="checkbox"
                checked={common.filters.compare}
                onChange={(e) =>
                  apply({ compare: e.target.checked ? '1' : null })
                }
              />
              {t('reports.compare')}
            </label>
          )}
        </section>
      )}
      <p className="mb-5 text-xs text-slate-500">
        {common.applicable.includes('period') &&
          t('reports.period_note', {
            from: common.period.from,
            to: common.period.to,
            zone: common.period.timezone,
          })}
        {common.filters.compare &&
          ' · ' +
            t('reports.compared_with', {
              from: common.previous.from,
              to: common.previous.to,
            })}
        {common.applicable.includes('currency') &&
          ' · ' + t('reports.amounts_in', { currency: common.currency.code })}
      </p>
      {common.other_currencies.length > 0 && (
        <p
          role="note"
          className="mb-5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
          {t('reports.other_currencies', {
            list: common.other_currencies
              .map((c) => c.currency + ' (' + c.orders + ')')
              .join(', '),
          })}
        </p>
      )}
      {children}
    </AdminLayout>
  );
}
