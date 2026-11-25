import { Link, usePage } from '@inertiajs/react';
import BarList from '../../../components/admin/reports/BarList';
import LineChart from '../../../components/admin/reports/LineChart';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useReportUrl } from '../../../lib/reportQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  BrandRow,
  CategoryRow,
  ProductRow,
  ReportCommon,
  SalesContext,
  SalesSummary,
  SeriesPoint,
  TimingPoint,
} from '../../../types/reports';

type Props = AdminShell &
  ReportCommon & {
    summary: SalesSummary;
    previous_summary: SalesSummary | null;
    previous_has_data: boolean;
    context: SalesContext;
    series: SeriesPoint[];
    previous_series: SeriesPoint[] | null;
    top_products: ProductRow[];
    top_categories: CategoryRow[];
    top_brands: (BrandRow & { net_formatted: string })[];
    timing: { weekdays: TimingPoint[]; hours: TimingPoint[] };
    returning: {
      orders: number;
      returning_orders: number;
      rate: string | null;
      returning_total_formatted: string | null;
    };
    recent_orders: {
      id: string;
      number: string;
      customer: string;
      total: string;
      created_at: string;
      status: { name: string; color: string } | null;
    }[];
    stock: {
      out_of_stock: number;
      low_stock: number;
      low: { id: string; name: string; sku: string | null; quantity: number }[];
    };
    new_customers: number | null;
  };

/** 24 columns of paid orders per hour of the day; the busiest hour is highlighted. */
function HourChart({ hours }: { hours: TimingPoint[] }) {
  const t = useTranslation();
  const max = Math.max(1, ...hours.map((h) => h.orders));
  return (
    <div>
      <ol
        className="flex h-28 items-end gap-0.5"
        aria-label={t('reports.sales_by_hour')}
      >
        {hours.map((h) => (
          <li
            key={h.key}
            className="flex h-full flex-1 items-end"
            title={
              String(h.key).padStart(2, '0') +
              ':00 · ' +
              t('reports.orders_count', { count: h.orders }) +
              ' · ' +
              h.net_formatted
            }
          >
            <span className="sr-only">
              {String(h.key).padStart(2, '0') +
                ':00 · ' +
                t('reports.orders_count', { count: h.orders })}
            </span>
            <span
              aria-hidden="true"
              className={
                'block w-full rounded-t ' +
                (h.orders === max ? 'bg-emerald-600' : 'bg-emerald-200')
              }
              style={{ height: (h.orders / max) * 100 + '%' }}
            />
          </li>
        ))}
      </ol>
      <div
        aria-hidden="true"
        className="mt-1 flex justify-between text-[10px] text-slate-400 tabular-nums"
      >
        <span>00</span>
        <span>06</span>
        <span>12</span>
        <span>18</span>
        <span>23</span>
      </div>
    </div>
  );
}

export default function ReportsOverview(props: Props) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const { date, time } = useDateDisplay();
  const { search } = useReportUrl();
  const { navigation, breadcrumbs, summary: s, previous_summary: p } = props;
  const prior = props.previous_has_data ? p : null;
  const num = (v: string | null | undefined) =>
    v === null || v === undefined ? undefined : Number(v);
  return (
    <ReportShell
      common={props}
      title={t('reports.overview_title')}
      description={t('reports.overview_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label={t('reports.net_sales')}
          value={s.net_formatted}
          hint={t('reports.net_sales_hint')}
          current={num(s.net)}
          previous={num(prior?.net)}
        />
        <MetricCard
          label={t('reports.paid_orders')}
          value={s.orders}
          hint={t('reports.paid_orders_hint')}
          current={num(s.orders)}
          previous={num(prior?.orders)}
        />
        <MetricCard
          label={t('reports.aov')}
          value={s.aov_formatted}
          hint={t('reports.aov_hint')}
          current={num(s.aov)}
          previous={num(prior?.aov)}
        />
        <MetricCard
          label={t('reports.units_sold')}
          value={s.units}
          current={num(s.units)}
          previous={num(prior?.units)}
        />
        {props.new_customers !== null && (
          <MetricCard
            label={t('reports.new_customers')}
            value={String(props.new_customers)}
          />
        )}
        <MetricCard
          label={t('reports.items_per_order')}
          value={
            Number(s.orders) > 0
              ? (Number(s.units) / Number(s.orders)).toLocaleString(
                  i18n.locale,
                  { maximumFractionDigits: 1 },
                )
              : null
          }
          hint={t('reports.items_per_order_hint')}
        />
        <MetricCard
          label={t('reports.returning_rate')}
          value={
            props.returning.rate === null ? null : props.returning.rate + '%'
          }
          hint={t('reports.returning_rate_hint', {
            count: props.returning.returning_orders,
            value: props.returning.returning_total_formatted ?? '',
          })}
        />
        <MetricCard
          label={t('reports.refunds')}
          value={null}
          hint={t('reports.refunds_unavailable')}
        />
      </div>
      <div className="mb-6 grid gap-6 lg:grid-cols-2">
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <LineChart
            title={t('reports.chart_sales')}
            points={props.series}
            previous={props.previous_series}
            metric="net"
            currency={props.currency}
            locale={i18n.locale}
            granularity={props.period.granularity}
          />
        </section>
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <LineChart
            title={t('reports.chart_orders')}
            points={props.series}
            previous={props.previous_series}
            metric="orders"
            currency={props.currency}
            locale={i18n.locale}
            granularity={props.period.granularity}
          />
        </section>
      </div>
      <p className="mb-6 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        {t('reports.overview_context', {
          placed: props.context.placed,
          unpaid: props.context.unpaid_orders,
          value: props.context.unpaid_value_formatted,
        })}
      </p>
      <div className="mb-6 grid gap-6 lg:grid-cols-2">
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.top_products')}
            empty={t('reports.no_sales')}
            bars={props.top_products.map((row) => ({
              key: row.product_id ?? row.name,
              label:
                row.exists && props.links.can_products ? (
                  <Link
                    className="hover:underline"
                    href={'/admin/products/' + row.product_id + '/edit'}
                  >
                    {row.name}
                  </Link>
                ) : (
                  row.name
                ),
              value: Number(row.net_raw ?? 0),
              text: row.net ?? '',
            }))}
          />
          <Link
            className="text-link mt-4 inline-block text-sm"
            href={'/admin/reports/products' + search}
          >
            {t('reports.view_report')}
          </Link>
        </section>
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.top_categories')}
            empty={t('reports.no_sales')}
            bars={props.top_categories.map((row) => ({
              key: row.id,
              label: row.path,
              value: Number(row.net),
              text: row.net_formatted,
            }))}
          />
          <Link
            className="text-link mt-4 inline-block text-sm"
            href={'/admin/reports/categories' + search}
          >
            {t('reports.view_report')}
          </Link>
        </section>
      </div>
      <div className="mb-6 grid gap-6 lg:grid-cols-3">
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.top_brands')}
            empty={t('reports.no_sales')}
            bars={props.top_brands.map((row) => ({
              key: row.id,
              label: row.name,
              value: Number(row.net),
              text: row.net_formatted + ' · ' + row.share + '%',
            }))}
          />
          <Link
            className="text-link mt-4 inline-block text-sm"
            href={'/admin/reports/brands' + search}
          >
            {t('reports.view_report')}
          </Link>
        </section>
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <BarList
            title={t('reports.sales_by_weekday')}
            empty={t('reports.no_sales')}
            bars={
              props.timing.weekdays.some((d) => d.orders > 0)
                ? props.timing.weekdays.map((d) => ({
                    key: String(d.key),
                    // 2024-01-01 was a Monday: day 1 … 7 of that week.
                    label: new Date(2024, 0, d.key).toLocaleDateString(
                      i18n.locale,
                      { weekday: 'long' },
                    ),
                    value: Number(d.net),
                    text:
                      d.net_formatted +
                      ' · ' +
                      t('reports.orders_count', { count: d.orders }),
                  }))
                : []
            }
          />
        </section>
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h3 className="mb-3 text-sm font-semibold">
            {t('reports.sales_by_hour')}
          </h3>
          {props.timing.hours.every((h) => h.orders === 0) ? (
            <p className="text-sm text-slate-500">{t('reports.no_sales')}</p>
          ) : (
            <HourChart hours={props.timing.hours} />
          )}
          <p className="mt-2 text-xs text-slate-500">
            {t('reports.store_time', { zone: props.period.timezone })}
          </p>
        </section>
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        {props.links.can_orders && (
          <section className="rounded-lg border border-slate-200 bg-white p-4">
            <h3 className="mb-3 text-sm font-semibold">
              {t('reports.recent_orders')}
            </h3>
            {props.recent_orders.length === 0 ? (
              <p className="text-sm text-slate-500">{t('reports.no_orders')}</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {props.recent_orders.map((o) => (
                  <li
                    key={o.id}
                    className="flex items-center justify-between gap-3 py-2"
                  >
                    <span className="min-w-0">
                      <Link
                        className="font-medium hover:underline"
                        href={'/admin/orders/' + o.id}
                      >
                        {o.number}
                      </Link>
                      <span className="block text-xs text-slate-500">
                        {date.format(new Date(o.created_at))}{' '}
                        {time.format(new Date(o.created_at))}
                        {o.status ? ' · ' + o.status.name : ''}
                      </span>
                    </span>
                    <span className="tabular-nums">{o.total}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        )}
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h3 className="mb-3 text-sm font-semibold">
            {t('reports.stock_alerts')}
          </h3>
          <p className="mb-3 text-sm text-slate-600">
            {t('reports.stock_counts', {
              out: props.stock.out_of_stock,
              low: props.stock.low_stock,
            })}
          </p>
          {props.stock.low.length === 0 ? (
            <p className="text-sm text-slate-500">
              {t('reports.no_low_stock')}
            </p>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {props.stock.low.map((row) => (
                <li key={row.id} className="flex justify-between gap-3 py-2">
                  <span className="truncate">
                    {props.links.can_products ? (
                      <Link
                        className="hover:underline"
                        href={'/admin/products/' + row.id + '/edit'}
                      >
                        {row.name}
                      </Link>
                    ) : (
                      row.name
                    )}
                  </span>
                  <span className="tabular-nums">{row.quantity}</span>
                </li>
              ))}
            </ul>
          )}
          <Link
            className="text-link mt-4 inline-block text-sm"
            href="/admin/reports/inventory"
          >
            {t('reports.view_report')}
          </Link>
        </section>
      </div>
      <p className="mt-6 text-sm">
        <Link className="text-link" href={'/admin/reports/sales' + search}>
          {t('reports.open_sales')}
        </Link>
      </p>
    </ReportShell>
  );
}
