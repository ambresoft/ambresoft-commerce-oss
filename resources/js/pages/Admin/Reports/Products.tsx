import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../../components/admin/Pagination';
import BarList from '../../../components/admin/reports/BarList';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import ReportTabs from '../../../components/admin/reports/ReportTabs';
import SortHeader from '../../../components/admin/reports/SortHeader';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type {
  ProductRow,
  ReportCommon,
  SalesSummary,
} from '../../../types/reports';

type Props = AdminShell &
  ReportCommon & {
    view: string;
    search: string;
    summary: SalesSummary;
    products: Page<ProductRow>;
  };

export default function ReportsProducts(props: Props) {
  const t = useTranslation();
  const { params, href } = useReportUrl();
  const [search, setSearch] = useState(props.search);
  const { navigation, breadcrumbs } = props;
  const sort = params.get('sort') ?? 'net';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  const ranking = props.view === 'ranking';
  const name = (row: ProductRow) => {
    const label =
      row.exists && props.links.can_products ? (
        <Link
          className="font-medium hover:underline"
          href={'/admin/products/' + row.product_id + '/edit'}
        >
          {row.name}
        </Link>
      ) : (
        <span className="font-medium">{row.name}</span>
      );
    return (
      <>
        {label}
        {row.trashed && (
          <span className="ml-2 text-xs text-slate-500">
            {t('reports.in_trash')}
          </span>
        )}
        {!row.exists && !row.trashed && row.product_id === null && (
          <span className="ml-2 text-xs text-slate-500">
            {t('reports.product_deleted')}
          </span>
        )}
        {row.sku && (
          <span className="block text-xs text-slate-500">{row.sku}</span>
        )}
      </>
    );
  };
  return (
    <ReportShell
      common={props}
      title={t('reports.products_title')}
      description={t('reports.products_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <ReportTabs
        label={t('reports.views')}
        current={props.view}
        items={[
          { value: 'ranking', label: t('reports.view_ranking') },
          { value: 'declining', label: t('reports.view_declining') },
          { value: 'no_sales', label: t('reports.view_no_sales') },
          { value: 'never_sold', label: t('reports.view_never_sold') },
        ]}
      />
      {ranking && (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <MetricCard
              label={t('reports.units_sold')}
              value={props.summary.units}
            />
            <MetricCard
              label={t('reports.gross_sales')}
              value={props.summary.gross_formatted}
              hint={t('reports.gross_sales_hint')}
            />
            <MetricCard
              label={t('reports.net_sales')}
              value={props.summary.net_formatted}
              hint={t('reports.net_sales_hint')}
            />
          </div>
          {props.products.data.length > 0 && (
            <section className="mb-6 rounded-lg border border-slate-200 bg-white p-4">
              <BarList
                title={t('reports.top_by_net')}
                empty={t('reports.no_sales')}
                bars={props.products.data.slice(0, 10).map((row) => ({
                  key: row.product_id ?? row.name,
                  label: row.name,
                  value: Number(row.net_raw ?? 0),
                  text: row.net ?? '',
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
              aria-label={t('reports.search_products')}
              placeholder={t('reports.search_products')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <button className="button-secondary">{t('reports.search')}</button>
          </form>
        </>
      )}
      <section className="list-card" aria-label={t('reports.products_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              {ranking ? (
                <tr>
                  <SortHeader
                    label={t('reports.product')}
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
              ) : props.view === 'declining' ? (
                <tr>
                  <th>{t('reports.product')}</th>
                  <th className="text-right">{t('reports.units_now')}</th>
                  <th className="text-right">{t('reports.units_before')}</th>
                  <th className="text-right">{t('reports.change')}</th>
                </tr>
              ) : (
                <tr>
                  <th>{t('reports.product')}</th>
                  <th>{t('reports.status')}</th>
                  <th>{t('reports.created')}</th>
                </tr>
              )}
            </thead>
            <tbody>
              {props.products.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t(
                      ranking ? 'reports.no_sales' : 'reports.nothing_to_show',
                    )}
                  </td>
                </tr>
              )}
              {props.products.data.map((row, index) => (
                <tr key={(row.product_id ?? 'x') + ':' + index}>
                  <td>{name(row)}</td>
                  {ranking ? (
                    <>
                      <td className="text-right tabular-nums">{row.units}</td>
                      <td className="text-right tabular-nums">{row.orders}</td>
                      <td className="text-right tabular-nums">{row.gross}</td>
                      <td className="text-right tabular-nums">
                        {row.discounts}
                      </td>
                      <td className="text-right tabular-nums">{row.net}</td>
                    </>
                  ) : props.view === 'declining' ? (
                    <>
                      <td className="text-right tabular-nums">{row.units}</td>
                      <td className="text-right tabular-nums">
                        {row.previous_units}
                      </td>
                      <td className="text-right tabular-nums text-red-700">
                        {row.change}
                      </td>
                    </>
                  ) : (
                    <>
                      <td>{row.status}</td>
                      <td>{row.created_at?.slice(0, 10)}</td>
                    </>
                  )}
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
            onChange: (v) =>
              router.get(href({ per_page: v }), {}, { preserveScroll: true }),
          }}
        />
      </section>
      <p className="mt-4 text-xs text-slate-500">
        {t('reports.products_note')}
      </p>
    </ReportShell>
  );
}
