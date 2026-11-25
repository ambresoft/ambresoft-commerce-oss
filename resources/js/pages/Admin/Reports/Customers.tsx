import { Link, router } from '@inertiajs/react';
import Pagination from '../../../components/admin/Pagination';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import ReportTabs from '../../../components/admin/reports/ReportTabs';
import SortHeader from '../../../components/admin/reports/SortHeader';
import { useReportUrl } from '../../../lib/reportQuery';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { ReportCommon } from '../../../types/reports';

type Row = {
  customer_id: string | null;
  guest?: boolean;
  name: string;
  email: string;
  orders?: number;
  net?: string;
  total?: string;
  aov?: string;
  last_order_at?: string;
  created_at?: string;
  status?: string;
};
type Props = AdminShell &
  ReportCommon & {
    view: 'ranking' | 'no_orders';
    summary: {
      new_customers: number;
      registered_buyers: number;
      guest_buyers: number;
      repeat_customers: number;
      returning_customers: number;
      without_orders: number;
    };
    customers: Page<Row>;
  };

export default function ReportsCustomers(props: Props) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const { params, href } = useReportUrl();
  const { navigation, breadcrumbs, summary: s } = props;
  const sort = params.get('sort') ?? 'spend';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  const ranking = props.view === 'ranking';
  const at = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  return (
    <ReportShell
      common={props}
      title={t('reports.customers_title')}
      description={t('reports.customers_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <MetricCard
          label={t('reports.new_customers')}
          value={String(s.new_customers)}
          hint={t('reports.new_customers_hint')}
        />
        <MetricCard
          label={t('reports.registered_buyers')}
          value={String(s.registered_buyers)}
        />
        <MetricCard
          label={t('reports.guest_buyers')}
          value={String(s.guest_buyers)}
          hint={t('reports.guest_buyers_hint')}
        />
        <MetricCard
          label={t('reports.repeat_customers')}
          value={String(s.repeat_customers)}
          hint={t('reports.repeat_hint')}
        />
        <MetricCard
          label={t('reports.returning_customers')}
          value={String(s.returning_customers)}
          hint={t('reports.returning_hint')}
        />
        <MetricCard
          label={t('reports.without_orders')}
          value={String(s.without_orders)}
          hint={t('reports.without_orders_hint')}
        />
      </div>
      <ReportTabs
        label={t('reports.views')}
        current={props.view}
        items={[
          { value: 'ranking', label: t('reports.view_top_customers') },
          { value: 'no_orders', label: t('reports.view_no_orders') },
        ]}
      />
      <section className="list-card" aria-label={t('reports.customers_title')}>
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              {ranking ? (
                <tr>
                  <SortHeader
                    label={t('reports.customer')}
                    field="name"
                    sort={sort}
                    direction={direction}
                  />
                  <SortHeader
                    label={t('reports.orders')}
                    field="orders"
                    sort={sort}
                    direction={direction}
                    align="right"
                  />
                  <SortHeader
                    label={t('reports.net_sales')}
                    field="spend"
                    sort={sort}
                    direction={direction}
                    align="right"
                  />
                  <SortHeader
                    label={t('reports.total_charged')}
                    field="total"
                    sort={sort}
                    direction={direction}
                    align="right"
                  />
                  <th className="text-right">{t('reports.aov')}</th>
                  <SortHeader
                    label={t('reports.last_order')}
                    field="last"
                    sort={sort}
                    direction={direction}
                  />
                </tr>
              ) : (
                <tr>
                  <th>{t('reports.customer')}</th>
                  <th>{t('reports.status')}</th>
                  <th>{t('reports.created')}</th>
                </tr>
              )}
            </thead>
            <tbody>
              {props.customers.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t(
                      ranking ? 'reports.no_sales' : 'reports.nothing_to_show',
                    )}
                  </td>
                </tr>
              )}
              {props.customers.data.map((r, index) => (
                <tr key={(r.customer_id ?? r.email) + ':' + index}>
                  <td>
                    {r.customer_id ? (
                      <Link
                        className="font-medium hover:underline"
                        href={'/admin/customers/' + r.customer_id + '/edit'}
                      >
                        {r.name}
                      </Link>
                    ) : (
                      <span className="font-medium">{r.name}</span>
                    )}
                    {r.guest && (
                      <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                        {t('reports.guest')}
                      </span>
                    )}
                    <span className="block text-xs text-slate-500">
                      {r.email}
                    </span>
                  </td>
                  {ranking ? (
                    <>
                      <td className="text-right tabular-nums">{r.orders}</td>
                      <td className="text-right tabular-nums">{r.net}</td>
                      <td className="text-right tabular-nums">{r.total}</td>
                      <td className="text-right tabular-nums">{r.aov}</td>
                      <td className="whitespace-nowrap">
                        {r.last_order_at ? at(r.last_order_at) : '—'}
                      </td>
                    </>
                  ) : (
                    <>
                      <td>{r.status}</td>
                      <td>{r.created_at ? at(r.created_at) : '—'}</td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={props.customers}
          perPage={{
            value: params.get('per_page') ?? '25',
            options: ['25', '50', '100'],
            onChange: (v) =>
              router.get(href({ per_page: v }), {}, { preserveScroll: true }),
          }}
        />
      </section>
      <p className="mt-4 text-xs text-slate-500">
        {t('reports.customers_note')}
      </p>
    </ReportShell>
  );
}
