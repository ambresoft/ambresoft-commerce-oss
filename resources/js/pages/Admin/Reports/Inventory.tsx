import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportTabs from '../../../components/admin/reports/ReportTabs';
import Select from '../../../components/admin/Select';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SelectOption } from '../../../types/reports';

type Row = {
  id: string;
  name: string;
  sku: string | null;
  status: string;
  quantity: number;
  category: string | null;
};
type Props = AdminShell & {
  summary: {
    physical: number;
    digital: number;
    out_of_stock: number;
    low_stock: number;
    units: number;
    threshold: number;
    tracking: boolean;
  };
  view: string;
  threshold: number;
  status: string | null;
  category_id: number | null;
  search: string;
  products: Page<Row>;
  options: { categories: SelectOption[] };
  links: { can_export: boolean; can_products: boolean };
};

// Literal keys: translation keys are never built at runtime.
const statusLabel: Record<string, string> = {
  active: 'reports.status_active',
  hidden: 'reports.status_hidden',
  disabled: 'reports.status_disabled',
};

export default function ReportsInventory(props: Props) {
  const t = useTranslation();
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const { path, params, href, search: query } = useReportUrl();
  const [search, setSearch] = useState(props.search);
  const [threshold, setThreshold] = useState(String(props.threshold));
  const { navigation, breadcrumbs, summary: s } = props;
  const go = (updates: Record<string, string | null>) =>
    router.get(href(updates), {}, { preserveScroll: true });
  void errors;
  return (
    <AdminLayout
      title={t('reports.inventory_title')}
      navigation={navigation}
      breadcrumbs={breadcrumbs}
    >
      <EditorHeader
        heading={t('reports.inventory_title')}
        iconKey="reports"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('reports.inventory_description')}
            </p>
          </>
        }
        actions={
          <>
            {props.links.can_export && (
              <a className="button-secondary" href={path + '/export' + query}>
                {t('reports.export_csv')}
              </a>
            )}
          </>
        }
      />
      {!s.tracking && (
        <p
          role="note"
          className="mb-5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
          {t('reports.tracking_off')}
        </p>
      )}
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <MetricCard
          label={t('reports.stock_products')}
          value={String(s.physical)}
          hint={t('reports.stock_products_hint', { digital: s.digital })}
        />
        <MetricCard
          label={t('reports.stock_units')}
          value={String(s.units)}
          hint={t('reports.stock_units_hint')}
        />
        <MetricCard
          label={t('reports.stock_out')}
          value={String(s.out_of_stock)}
        />
        <MetricCard
          label={t('reports.stock_low')}
          value={String(s.low_stock)}
          hint={t('reports.stock_low_hint', { threshold: s.threshold })}
        />
        <MetricCard
          label={t('reports.inventory_value')}
          value={null}
          hint={t('reports.inventory_value_unavailable')}
        />
      </div>
      <ReportTabs
        label={t('reports.views')}
        current={props.view}
        items={[
          { value: 'out_of_stock', label: t('reports.stock_view_out') },
          { value: 'low', label: t('reports.stock_view_low') },
          { value: 'lowest', label: t('reports.stock_view_lowest') },
          { value: 'highest', label: t('reports.stock_view_highest') },
          { value: 'above', label: t('reports.stock_view_above') },
        ]}
      />
      <section
        aria-label={t('reports.filters')}
        className="mb-4 grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <form
          className="space-y-1"
          role="search"
          onSubmit={(e) => {
            e.preventDefault();
            go({ search });
          }}
        >
          <label
            className="block text-xs font-medium text-slate-600"
            htmlFor="inventory-search"
          >
            {t('reports.search_products')}
          </label>
          <input
            id="inventory-search"
            className="field"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </form>
        <div className="space-y-1">
          <label
            className="block text-xs font-medium text-slate-600"
            htmlFor="inventory-status"
          >
            {t('reports.product_status')}
          </label>
          <Select
            id="inventory-status"
            value={props.status ?? ''}
            onChange={(v) => go({ product_status: v })}
            options={[
              { value: '', label: t('reports.all') },
              { value: 'active', label: t('reports.status_active') },
              { value: 'disabled', label: t('reports.status_disabled') },
              { value: 'hidden', label: t('reports.status_hidden') },
            ]}
          />
        </div>
        <div className="space-y-1">
          <label
            className="block text-xs font-medium text-slate-600"
            htmlFor="inventory-category"
          >
            {t('reports.category')}
          </label>
          <Select
            id="inventory-category"
            value={props.category_id === null ? '' : String(props.category_id)}
            onChange={(v) => go({ category_id: v })}
            options={[
              { value: '', label: t('reports.all') },
              ...props.options.categories,
            ]}
          />
        </div>
        {(props.view === 'above' || props.view === 'low') && (
          <form
            className="space-y-1"
            onSubmit={(e) => {
              e.preventDefault();
              go({ threshold });
            }}
          >
            <label
              className="block text-xs font-medium text-slate-600"
              htmlFor="inventory-threshold"
            >
              {t('reports.threshold')}
            </label>
            <input
              id="inventory-threshold"
              className="field"
              inputMode="numeric"
              value={threshold}
              onChange={(e) => setThreshold(e.target.value)}
            />
          </form>
        )}
      </section>
      <section className="list-card" aria-label={t('reports.inventory_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reports.product')}</th>
                <th>{t('reports.category')}</th>
                <th>{t('reports.status')}</th>
                <th className="text-right">{t('reports.stock_quantity')}</th>
              </tr>
            </thead>
            <tbody>
              {props.products.data.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-10 text-center text-slate-500">
                    {t('reports.nothing_to_show')}
                  </td>
                </tr>
              )}
              {props.products.data.map((r) => (
                <tr key={r.id}>
                  <td>
                    {props.links.can_products ? (
                      <Link
                        className="font-medium hover:underline"
                        href={'/admin/products/' + r.id + '/edit'}
                      >
                        {r.name}
                      </Link>
                    ) : (
                      <span className="font-medium">{r.name}</span>
                    )}
                    {r.sku && (
                      <span className="block text-xs text-slate-500">
                        {r.sku}
                      </span>
                    )}
                  </td>
                  <td className="text-slate-600">{r.category ?? '—'}</td>
                  <td>
                    <StatusBadge
                      tone={r.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(statusLabel[r.status] ?? 'reports.status_disabled')}
                    </StatusBadge>
                  </td>
                  <td className="text-right tabular-nums">{r.quantity}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={props.products}
          perPage={{
            value: params.get('per_page') ?? '25',
            options: ['25', '50', '100'],
            onChange: (v) => go({ per_page: v }),
          }}
        />
      </section>
      <p className="mt-4 text-xs text-slate-500">
        {t('reports.inventory_note')}
      </p>
    </AdminLayout>
  );
}
