import AdminCard from '../../../components/admin/AdminCard';
import { Link, router } from '@inertiajs/react';
import Pagination from '../../../components/admin/Pagination';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import SortHeader from '../../../components/admin/reports/SortHeader';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useReportUrl } from '../../../lib/reportQuery';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { ReportCommon } from '../../../types/reports';

type StatusRow = {
  id: string;
  code: string;
  name: string;
  color: string;
  initial: boolean;
  final: boolean;
  void: boolean;
  orders: number;
  value_formatted: string;
  collected_formatted: string;
  outstanding_formatted: string;
};
type OrderRow = {
  id: string;
  number: string;
  created_at: string;
  paid_at: string | null;
  customer: string | null;
  status: { name: string; color: string; void: boolean } | null;
  payment_state: 'paid' | 'partially_paid' | 'unpaid';
  payment: string | null;
  shipping_method: string | null;
  total: string;
  collected: string;
};
type Props = AdminShell &
  ReportCommon & {
    statuses: StatusRow[];
    payment_states: {
      paid: number;
      partially_paid: number;
      pending: number;
      unpaid: number;
    };
    orders: Page<OrderRow>;
  };

export default function ReportsOrders(props: Props) {
  const t = useTranslation();
  const { date, time } = useDateDisplay();
  const { params, href } = useReportUrl();
  const { navigation, breadcrumbs } = props;
  const sort = params.get('sort') ?? 'date';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  const at = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const total = props.statuses.reduce((sum, s) => sum + s.orders, 0);
  const stateLabel = {
    paid: 'reports.state_paid',
    partially_paid: 'reports.state_partially_paid',
    unpaid: 'reports.state_unpaid',
  } as const;
  const meaning = (s: StatusRow) =>
    s.void
      ? t('reports.status_void')
      : s.initial
        ? t('reports.status_initial')
        : s.final
          ? t('reports.status_final')
          : t('reports.status_open');
  return (
    <ReportShell
      common={props}
      title={t('reports.orders_title')}
      description={t('reports.orders_description')}
      shell={{ navigation, breadcrumbs }}
    >
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <MetricCard label={t('reports.total_orders')} value={String(total)} />
        <MetricCard
          label={t('reports.state_paid')}
          value={String(props.payment_states.paid)}
          hint={t('reports.state_paid_hint')}
        />
        <MetricCard
          label={t('reports.state_partially_paid')}
          value={String(props.payment_states.partially_paid)}
        />
        <MetricCard
          label={t('reports.state_pending')}
          value={String(props.payment_states.pending)}
          hint={t('reports.state_pending_hint')}
        />
        <MetricCard
          label={t('reports.state_unpaid')}
          value={String(props.payment_states.unpaid)}
        />
      </div>
      <section className="mb-6" aria-label={t('reports.by_status')}>
        <AdminCard
          title={t('reports.by_status')}
          iconKey="by_status"
          bodyClassName="p-0 overflow-x-auto"
        >
          <p className="px-4 pb-2 text-xs text-slate-500">
            {t('reports.by_status_note')}
          </p>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reports.status')}</th>
                <th>{t('reports.meaning')}</th>
                <th className="text-right">{t('reports.orders')}</th>
                <th className="text-right">{t('reports.order_value')}</th>
                <th className="text-right">{t('reports.collected')}</th>
                <th className="text-right">{t('reports.outstanding')}</th>
              </tr>
            </thead>
            <tbody>
              {props.statuses.map((s) => (
                <tr key={s.id}>
                  <td>
                    <span className="inline-flex items-center gap-2">
                      <span
                        aria-hidden="true"
                        className="size-2.5 rounded-full"
                        style={{ background: s.color }}
                      />
                      {s.name}
                    </span>
                  </td>
                  <td className="text-slate-500">{meaning(s)}</td>
                  <td className="text-right tabular-nums">{s.orders}</td>
                  <td className="text-right tabular-nums">
                    {s.value_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {s.collected_formatted}
                  </td>
                  <td className="text-right tabular-nums">
                    {s.outstanding_formatted}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </AdminCard>
      </section>
      <section aria-label={t('reports.orders_list')}>
        <AdminCard
          title={t('reports.orders_list')}
          iconKey="orders_list"
          bodyClassName="p-0 "
        >
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <SortHeader
                    label={t('reports.order')}
                    field="number"
                    sort={sort}
                    direction={direction}
                  />
                  <SortHeader
                    label={
                      props.filters.date === 'created'
                        ? t('reports.placed_on')
                        : t('reports.paid_on')
                    }
                    field="date"
                    sort={sort}
                    direction={direction}
                  />
                  {props.orders.data.some((o) => o.customer !== null) && (
                    <th>{t('reports.customer')}</th>
                  )}
                  <th>{t('reports.status')}</th>
                  <th>{t('reports.payment_state')}</th>
                  <th>{t('reports.payment_method')}</th>
                  <th>{t('reports.shipping_method')}</th>
                  <SortHeader
                    label={t('reports.order_value')}
                    field="total"
                    sort={sort}
                    direction={direction}
                    align="right"
                  />
                  <th className="text-right">{t('reports.collected')}</th>
                </tr>
              </thead>
              <tbody>
                {props.orders.data.length === 0 && (
                  <tr>
                    <td
                      colSpan={9}
                      className="py-10 text-center text-slate-500"
                    >
                      {t('reports.no_orders')}
                    </td>
                  </tr>
                )}
                {props.orders.data.map((o) => (
                  <tr key={o.id}>
                    <td>
                      <Link
                        className="font-medium hover:underline"
                        href={'/admin/orders/' + o.id}
                      >
                        {o.number}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap">
                      {at(
                        (props.filters.date === 'created'
                          ? o.created_at
                          : o.paid_at) ?? o.created_at,
                      )}
                    </td>
                    {props.orders.data.some((x) => x.customer !== null) && (
                      <td>{o.customer}</td>
                    )}
                    <td>
                      {o.status && (
                        <span className="inline-flex items-center gap-2">
                          <span
                            aria-hidden="true"
                            className="size-2.5 rounded-full"
                            style={{ background: o.status.color }}
                          />
                          {o.status.name}
                        </span>
                      )}
                    </td>
                    <td>
                      <StatusBadge
                        tone={
                          o.payment_state === 'paid'
                            ? 'success'
                            : o.payment_state === 'partially_paid'
                              ? 'warning'
                              : 'neutral'
                        }
                      >
                        {t(stateLabel[o.payment_state])}
                      </StatusBadge>
                    </td>
                    <td>{o.payment ?? '—'}</td>
                    <td>{o.shipping_method ?? '—'}</td>
                    <td className="text-right tabular-nums">{o.total}</td>
                    <td className="text-right tabular-nums">{o.collected}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={props.orders}
            perPage={{
              value: params.get('per_page') ?? '25',
              options: ['25', '50', '100'],
              onChange: (v) =>
                router.get(href({ per_page: v }), {}, { preserveScroll: true }),
            }}
          />
        </AdminCard>
      </section>
    </ReportShell>
  );
}
