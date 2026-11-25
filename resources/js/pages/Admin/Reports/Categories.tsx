import { router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../../components/admin/Pagination';
import BarList from '../../../components/admin/reports/BarList';
import ReportShell from '../../../components/admin/reports/ReportShell';
import ReportTabs from '../../../components/admin/reports/ReportTabs';
import SortHeader from '../../../components/admin/reports/SortHeader';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { CategoryRow, ReportCommon } from '../../../types/reports';

type Total = {
  units: number;
  orders: number;
  gross_formatted: string;
  discounts_formatted: string;
  net_formatted: string;
};
type Props = AdminShell &
  ReportCommon & {
    mode: 'direct' | 'tree';
    search: string;
    categories: Page<CategoryRow>;
    total: Total;
    unclassified: Total;
  };

export default function ReportsCategories(props: Props) {
  const t = useTranslation();
  const { params, href } = useReportUrl();
  const [search, setSearch] = useState(props.search);
  const { navigation, breadcrumbs } = props;
  const sort = params.get('sort') ?? 'net';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  return (
    <ReportShell
      common={props}
      title={t('reports.categories_title')}
      description={t('reports.categories_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <ReportTabs
        label={t('reports.aggregation')}
        param="mode"
        current={props.mode}
        items={[
          { value: 'direct', label: t('reports.mode_direct') },
          { value: 'tree', label: t('reports.mode_tree') },
        ]}
      />
      <p className="mb-4 text-xs text-slate-500">
        {t(
          props.mode === 'tree'
            ? 'reports.mode_tree_note'
            : 'reports.mode_direct_note',
        )}
      </p>
      {props.categories.data.length > 0 && (
        <section className="mb-6 rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.top_categories')}
            empty={t('reports.no_sales')}
            bars={props.categories.data.slice(0, 10).map((r) => ({
              key: r.id,
              label: r.path,
              value: Number(r.net),
              text: r.net_formatted,
            }))}
          />
        </section>
      )}
      <form
        className="mb-4 flex max-w-md gap-2"
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          router.get(href({ search }), {}, { preserveScroll: true });
        }}
      >
        <input
          className="field"
          aria-label={t('reports.search_categories')}
          placeholder={t('reports.search_categories')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="button-secondary">{t('reports.search')}</button>
      </form>
      <section className="list-card" aria-label={t('reports.categories_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <SortHeader
                  label={t('reports.category')}
                  field="name"
                  sort={sort}
                  direction={direction}
                />
                <SortHeader
                  label={t('reports.units_sold')}
                  field="units"
                  sort={sort}
                  direction={direction}
                  align="right"
                />
                <SortHeader
                  label={t('reports.orders')}
                  field="orders"
                  sort={sort}
                  direction={direction}
                  align="right"
                />
                <SortHeader
                  label={t('reports.gross_sales')}
                  field="gross"
                  sort={sort}
                  direction={direction}
                  align="right"
                />
                <th className="text-right">{t('reports.discounts')}</th>
                <SortHeader
                  label={t('reports.net_sales')}
                  field="net"
                  sort={sort}
                  direction={direction}
                  align="right"
                />
              </tr>
            </thead>
            <tbody>
              {props.categories.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t('reports.no_sales')}
                  </td>
                </tr>
              )}
              {props.categories.data.map((r) => (
                <tr key={r.id}>
                  <td className="font-medium">
                    {props.links.can_categories && r.exists ? (
                      <a
                        className="hover:underline"
                        href={'/admin/categories/' + r.id + '/edit'}
                      >
                        {r.path}
                      </a>
                    ) : (
                      r.path
                    )}
                  </td>
                  <td className="text-right tabular-nums">{r.units}</td>
                  <td className="text-right tabular-nums">{r.orders}</td>
                  <td className="text-right tabular-nums">
                    {r.gross_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {r.discounts_formatted}
                  </td>
                  <td className="text-right tabular-nums">{r.net_formatted}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              {props.unclassified.units > 0 && (
                <tr>
                  <td className="text-slate-500">
                    {t('reports.unclassified')}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unclassified.units}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unclassified.orders}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unclassified.gross_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unclassified.discounts_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unclassified.net_formatted}
                  </td>
                </tr>
              )}
              <tr className="font-semibold">
                <td>{t('reports.all_categories')}</td>
                <td className="text-right tabular-nums">{props.total.units}</td>
                <td className="text-right tabular-nums">
                  {props.total.orders}
                </td>
                <td className="text-right tabular-nums">
                  {props.total.gross_formatted}
                </td>
                <td className="text-right tabular-nums">
                  {props.total.discounts_formatted}
                </td>
                <td className="text-right tabular-nums">
                  {props.total.net_formatted}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <Pagination
          page={props.categories}
          perPage={{
            value: params.get('per_page') ?? '25',
            options: ['25', '50', '100'],
            onChange: (v) =>
              router.get(href({ per_page: v }), {}, { preserveScroll: true }),
          }}
        />
      </section>
      <p className="mt-4 text-xs text-slate-500">
        {t('reports.categories_note')}
      </p>
    </ReportShell>
  );
}
