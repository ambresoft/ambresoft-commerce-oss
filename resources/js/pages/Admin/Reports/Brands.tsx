import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../../components/admin/Pagination';
import BarList from '../../../components/admin/reports/BarList';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import SortHeader from '../../../components/admin/reports/SortHeader';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { BrandRow, ReportCommon } from '../../../types/reports';

type Total = {
  units: number;
  orders: number;
  products: number;
  gross_formatted: string;
  discounts_formatted: string;
  net_formatted: string;
};
type Props = AdminShell &
  ReportCommon & {
    search: string;
    brands: Page<BrandRow>;
    total: Total;
    unbranded: Total;
    brand_count: number;
  };

function Change({ value }: { value: string | null }) {
  if (value === null) return <span className="text-slate-400">—</span>;
  const n = Number(value);
  return (
    <span className={n >= 0 ? 'text-emerald-700' : 'text-red-700'}>
      {(n >= 0 ? '▲ ' : '▼ ') + Math.abs(n).toFixed(1) + '%'}
    </span>
  );
}

export default function ReportsBrands(props: Props) {
  const t = useTranslation();
  const { params, href } = useReportUrl();
  const [search, setSearch] = useState(props.search);
  const { navigation, breadcrumbs } = props;
  const sort = params.get('sort') ?? 'net';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  const compare = props.filters.compare;
  const leader = props.brands.data[0];
  const columns = compare ? 9 : 8;
  const name = (r: BrandRow) =>
    props.links.can_brands && r.exists ? (
      <Link
        className="hover:underline"
        href={'/admin/brands/' + r.id + '/edit'}
      >
        {r.name}
      </Link>
    ) : (
      <>
        {r.name}
        {!r.exists && (
          <span className="ml-1 text-xs text-slate-400">
            ({t('reports.brand_deleted')})
          </span>
        )}
      </>
    );
  return (
    <ReportShell
      common={props}
      title={t('reports.brands_title')}
      description={t('reports.brands_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label={t('reports.brands_selling')}
          value={String(props.brand_count)}
        />
        <MetricCard
          label={t('reports.net_sales')}
          value={props.total.net_formatted}
        />
        <MetricCard
          label={t('reports.unbranded_net')}
          value={props.unbranded.net_formatted}
          hint={t('reports.unbranded_hint')}
        />
        <MetricCard
          label={t('reports.top_brand')}
          value={
            leader && sort === 'net' && direction === 'desc'
              ? leader.name + ' · ' + leader.share + '%'
              : null
          }
        />
      </div>
      {props.brands.data.length > 0 && (
        <section className="mb-6 rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.top_brands')}
            empty={t('reports.no_sales')}
            bars={props.brands.data.slice(0, 10).map((r) => ({
              key: r.id,
              label: r.name,
              value: Number(r.net),
              text: r.net_formatted + ' · ' + r.share + '%',
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
          aria-label={t('reports.search_brands')}
          placeholder={t('reports.search_brands')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="button-secondary">{t('reports.search')}</button>
      </form>
      <section className="list-card" aria-label={t('reports.brands_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <SortHeader
                  label={t('reports.brand')}
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
                  label={t('reports.products_sold')}
                  field="products"
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
                <SortHeader
                  label={t('reports.share')}
                  field="share"
                  sort={sort}
                  direction={direction}
                  align="right"
                />
                {compare && (
                  <SortHeader
                    label={t('reports.change')}
                    field="change"
                    sort={sort}
                    direction={direction}
                    align="right"
                  />
                )}
              </tr>
            </thead>
            <tbody>
              {props.brands.data.length === 0 && (
                <tr>
                  <td
                    colSpan={columns}
                    className="py-10 text-center text-slate-500"
                  >
                    {t('reports.no_sales')}
                  </td>
                </tr>
              )}
              {props.brands.data.map((r) => (
                <tr key={r.id}>
                  <td className="font-medium">{name(r)}</td>
                  <td className="text-right tabular-nums">{r.units}</td>
                  <td className="text-right tabular-nums">{r.orders}</td>
                  <td className="text-right tabular-nums">{r.products}</td>
                  <td className="text-right tabular-nums">
                    {r.gross_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {r.discounts_formatted}
                  </td>
                  <td className="text-right tabular-nums">{r.net_formatted}</td>
                  <td className="text-right tabular-nums">{r.share}%</td>
                  {compare && (
                    <td
                      className="text-right tabular-nums"
                      title={
                        r.previous_net_formatted
                          ? t('reports.previous_value', {
                              value: r.previous_net_formatted,
                            })
                          : undefined
                      }
                    >
                      <Change value={r.change} />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
            <tfoot>
              {props.unbranded.units > 0 && (
                <tr>
                  <td className="text-slate-500">{t('reports.no_brand')}</td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.units}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.orders}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.products}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.gross_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.discounts_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {props.unbranded.net_formatted}
                  </td>
                  <td colSpan={columns - 7} />
                </tr>
              )}
              <tr className="font-semibold">
                <td>{t('reports.all_sales')}</td>
                <td className="text-right tabular-nums">{props.total.units}</td>
                <td className="text-right tabular-nums">
                  {props.total.orders}
                </td>
                <td className="text-right tabular-nums">
                  {props.total.products}
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
                <td colSpan={columns - 7} />
              </tr>
            </tfoot>
          </table>
        </div>
        <Pagination
          page={props.brands}
          perPage={{
            value: params.get('per_page') ?? '25',
            options: ['25', '50', '100'],
            onChange: (v) =>
              router.get(href({ per_page: v }), {}, { preserveScroll: true }),
          }}
        />
      </section>
      <p className="mt-4 text-xs text-slate-500">{t('reports.brands_note')}</p>
    </ReportShell>
  );
}
