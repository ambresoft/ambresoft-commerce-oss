import AdminCard from '../../../components/admin/AdminCard';
import { Link, router, usePage } from '@inertiajs/react';
import Pagination from '../../../components/admin/Pagination';
import LineChart from '../../../components/admin/reports/LineChart';
import MetricCard from '../../../components/admin/reports/MetricCard';
import ReportShell from '../../../components/admin/reports/ReportShell';
import SortHeader from '../../../components/admin/reports/SortHeader';
import { useReportUrl } from '../../../lib/reportQuery';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  ReportCommon,
  SalesContext,
  SalesSummary,
  SeriesPoint,
} from '../../../types/reports';

type Row = Record<string, string | number | null>;
type Props = AdminShell &
  ReportCommon & {
    summary: SalesSummary;
    previous_summary: SalesSummary | null;
    previous_has_data: boolean;
    line_scoped: boolean;
    context: SalesContext | null;
    series: SeriesPoint[];
    previous_series: SeriesPoint[] | null;
    by_payment: Row[];
    by_shipping: Row[];
    by_coupon: Row[];
    detail: Page<Row>;
  };

// Literal keys: translation keys are never built at runtime.
const granularityLabel = {
  hour: 'reports.granularity_hour',
  day: 'reports.granularity_day',
  week: 'reports.granularity_week',
  month: 'reports.granularity_month',
} as const;

export default function ReportsSales(props: Props) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const { date, time } = useDateDisplay();
  const { params, href } = useReportUrl();
  const { navigation, breadcrumbs, summary: s, previous_summary: p } = props;
  const prior = props.previous_has_data ? p : null;
  const num = (v: string | null | undefined) =>
    v === null || v === undefined ? undefined : Number(v);
  const sort = params.get('sort') ?? 'paid_at';
  const direction = params.get('direction') === 'asc' ? 'asc' : 'desc';
  const at = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const clear = (key: 'product_id' | 'category_id') => (
    <Link className="text-link text-xs" href={href({ [key]: null })}>
      {t('reports.clear')}
    </Link>
  );
  return (
    <ReportShell
      common={props}
      title={t('reports.sales_title')}
      description={t('reports.sales_description')}
      shell={{ navigation, breadcrumbs }}
    >
      {props.line_scoped && (
        <p
          role="note"
          className="mb-5 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
        >
          {t('reports.line_scoped')}{' '}
          {props.filters.product_id !== null && (
            <>
              #{props.filters.product_id} {clear('product_id')}
            </>
          )}{' '}
          {props.filters.category_id !== null && (
            <>
              {t('reports.category')} #{props.filters.category_id}{' '}
              {clear('category_id')}
            </>
          )}
        </p>
      )}
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label={t('reports.gross_sales')}
          value={s.gross_formatted}
          hint={t('reports.gross_sales_hint')}
          current={num(s.gross)}
          previous={num(prior?.gross)}
        />
        <MetricCard
          label={t('reports.discounts')}
          value={s.discounts_formatted}
          hint={t('reports.discounts_hint')}
          current={num(s.discounts)}
          previous={num(prior?.discounts)}
        />
        <MetricCard
          label={t('reports.refunds')}
          value={null}
          hint={t('reports.refunds_unavailable')}
        />
        <MetricCard
          label={t('reports.net_sales')}
          value={s.net_formatted}
          hint={t('reports.net_sales_hint')}
          current={num(s.net)}
          previous={num(prior?.net)}
        />
        <MetricCard
          label={t('reports.shipping_charged')}
          value={s.shipping_formatted}
          hint={t('reports.shipping_hint')}
          current={num(s.shipping)}
          previous={num(prior?.shipping)}
        />
        {(num(s.payment_fees) ?? 0) > 0 && (
          <MetricCard
            label={t('reports.payment_fees')}
            value={s.payment_fees_formatted ?? null}
            hint={t('reports.payment_fees_hint')}
            current={num(s.payment_fees)}
            previous={num(prior?.payment_fees)}
          />
        )}
        <MetricCard
          label={t('reports.tax_collected')}
          value={s.tax_added_formatted}
          hint={t('reports.tax_hint', {
            included: s.tax_included_formatted ?? '—',
          })}
          current={num(s.tax_added)}
          previous={num(prior?.tax_added)}
        />
        <MetricCard
          label={t('reports.total_charged')}
          value={s.total_formatted}
          hint={t('reports.total_hint')}
          current={num(s.total)}
          previous={num(prior?.total)}
        />
        <MetricCard
          label={t('reports.paid_orders')}
          value={s.orders}
          hint={t('reports.paid_orders_hint')}
          current={num(s.orders)}
          previous={num(prior?.orders)}
        />
        <MetricCard
          label={t('reports.units_sold')}
          value={s.units}
          current={num(s.units)}
          previous={num(prior?.units)}
        />
        <MetricCard
          label={t('reports.aov')}
          value={s.aov_formatted}
          hint={t('reports.aov_hint')}
          current={num(s.aov)}
          previous={num(prior?.aov)}
        />
      </div>
      {s.tax_included_formatted && (
        <p className="mb-6 text-xs text-slate-500">
          {t('reports.tax_included_note', { amount: s.tax_included_formatted })}
        </p>
      )}
      {props.context && (
        <section aria-label={t('reports.context_title')} className="mb-6">
          <AdminCard
            title={t('reports.context_title')}
            iconKey="context_title"
            bodyClassName="p-5 text-sm"
          >
            <ul className="grid gap-x-8 gap-y-1 sm:grid-cols-2">
              <li>
                {t('reports.context_placed', { count: props.context.placed })}
              </li>
              <li>
                {t('reports.context_unpaid', {
                  count: props.context.unpaid_orders,
                  value: props.context.unpaid_value_formatted,
                })}
              </li>
              <li>
                {t('reports.context_void', {
                  count: props.context.void_orders,
                  value: props.context.void_value_formatted,
                })}
              </li>
              <li>
                {t('reports.context_collected_void', {
                  count: props.context.collected_on_void_orders,
                  value: props.context.collected_on_void_formatted,
                })}
              </li>
            </ul>
          </AdminCard>
        </section>
      )}
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
          <p className="mt-2 text-xs text-slate-500">
            {t('reports.chart_sales_note', {
              currency: props.currency.code,
              unit: t(granularityLabel[props.period.granularity]),
            })}
          </p>
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
      {!props.line_scoped && (
        <div className="mb-6 grid gap-6 lg:grid-cols-2">
          <section aria-label={t('reports.by_payment')}>
            <AdminCard
              title={t('reports.by_payment')}
              iconKey="by_payment"
              bodyClassName="p-0 overflow-x-auto"
            >
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('reports.payment_method')}</th>
                    <th className="text-right">{t('reports.paid_orders')}</th>
                    <th className="text-right">{t('reports.total_charged')}</th>
                    <th className="text-right">{t('reports.unpaid')}</th>
                    <th className="text-right">
                      {t('reports.failed_attempts')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {props.by_payment.length === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="py-6 text-center text-slate-500"
                      >
                        {t('reports.no_sales')}
                      </td>
                    </tr>
                  )}
                  {props.by_payment.map((r) => (
                    <tr key={String(r.id ?? r.name)}>
                      <td>{r.name ?? t('reports.none')}</td>
                      <td className="text-right tabular-nums">{r.orders}</td>
                      <td className="text-right tabular-nums">
                        {r.total_formatted}
                      </td>
                      <td className="text-right tabular-nums">
                        {r.unpaid_orders} · {r.unpaid_value_formatted}
                      </td>
                      <td className="text-right tabular-nums">
                        {r.failed_attempts}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </AdminCard>
          </section>
          <section aria-label={t('reports.by_shipping')}>
            <AdminCard
              title={t('reports.by_shipping')}
              iconKey="by_shipping"
              bodyClassName="p-0 overflow-x-auto"
            >
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('reports.shipping_method')}</th>
                    <th className="text-right">{t('reports.paid_orders')}</th>
                    <th className="text-right">
                      {t('reports.shipping_charged')}
                    </th>
                    <th className="text-right">
                      {t('reports.shipping_discounts')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {props.by_shipping.length === 0 && (
                    <tr>
                      <td
                        colSpan={4}
                        className="py-6 text-center text-slate-500"
                      >
                        {t('reports.no_sales')}
                      </td>
                    </tr>
                  )}
                  {props.by_shipping.map((r) => (
                    <tr key={String(r.id ?? r.name)}>
                      <td>{r.name ?? t('reports.none')}</td>
                      <td className="text-right tabular-nums">{r.orders}</td>
                      <td className="text-right tabular-nums">
                        {r.shipping_formatted}
                      </td>
                      <td className="text-right tabular-nums">
                        {r.shipping_discount_formatted}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </AdminCard>
          </section>
          {props.by_coupon.length > 0 && (
            <section aria-label={t('reports.by_coupon')}>
              <AdminCard
                title={t('reports.by_coupon')}
                iconKey="by_coupon"
                bodyClassName="p-0 overflow-x-auto lg:col-span-2"
              >
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('reports.coupon')}</th>
                      <th className="text-right">{t('reports.paid_orders')}</th>
                      <th className="text-right">{t('reports.discounts')}</th>
                      <th className="text-right">{t('reports.net_sales')}</th>
                      <th className="text-right">
                        {t('reports.total_charged')}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {props.by_coupon.map((r) => (
                      <tr key={String(r.id)}>
                        <td>
                          <Link
                            className="hover:underline"
                            href={'/admin/coupons/' + r.id + '/edit'}
                          >
                            {r.code}
                          </Link>
                        </td>
                        <td className="text-right tabular-nums">{r.orders}</td>
                        <td className="text-right tabular-nums">
                          {r.discounts_formatted}
                        </td>
                        <td className="text-right tabular-nums">
                          {r.net_formatted}
                        </td>
                        <td className="text-right tabular-nums">
                          {r.total_formatted}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </AdminCard>
            </section>
          )}
        </div>
      )}
      <section aria-label={t('reports.sales_detail')}>
        <AdminCard
          title={t('reports.sales_detail')}
          iconKey="sales_detail"
          bodyClassName="p-0 "
        >
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                {props.line_scoped ? (
                  <tr>
                    <th>{t('reports.order')}</th>
                    <SortHeader
                      label={t('reports.paid_on')}
                      field="paid_at"
                      sort={sort}
                      direction={direction}
                    />
                    <th>{t('reports.product')}</th>
                    <SortHeader
                      label={t('reports.quantity')}
                      field="quantity"
                      sort={sort}
                      direction={direction}
                      align="right"
                    />
                    <th className="text-right">{t('reports.gross_sales')}</th>
                    <th className="text-right">{t('reports.discounts')}</th>
                    <SortHeader
                      label={t('reports.net_sales')}
                      field="net"
                      sort={sort}
                      direction={direction}
                      align="right"
                    />
                  </tr>
                ) : (
                  <tr>
                    <SortHeader
                      label={t('reports.order')}
                      field="number"
                      sort={sort}
                      direction={direction}
                    />
                    <SortHeader
                      label={t('reports.paid_on')}
                      field="paid_at"
                      sort={sort}
                      direction={direction}
                    />
                    <th>{t('reports.payment_method')}</th>
                    <th className="text-right">{t('reports.net_sales')}</th>
                    <th className="text-right">
                      {t('reports.shipping_charged')}
                    </th>
                    <th className="text-right">{t('reports.payment_fees')}</th>
                    <th className="text-right">{t('reports.tax_collected')}</th>
                    <SortHeader
                      label={t('reports.total_charged')}
                      field="total"
                      sort={sort}
                      direction={direction}
                      align="right"
                    />
                  </tr>
                )}
              </thead>
              <tbody>
                {props.detail.data.length === 0 && (
                  <tr>
                    <td
                      colSpan={7}
                      className="py-10 text-center text-slate-500"
                    >
                      {t('reports.no_sales')}
                    </td>
                  </tr>
                )}
                {props.detail.data.map((r) => (
                  <tr key={String(r.id)}>
                    <td>
                      {props.links.can_orders ? (
                        <Link
                          className="font-medium hover:underline"
                          href={'/admin/orders/' + (r.order_id ?? r.id)}
                        >
                          {r.order_number}
                        </Link>
                      ) : (
                        r.order_number
                      )}
                    </td>
                    <td className="whitespace-nowrap">
                      {at(String(r.paid_at))}
                    </td>
                    {props.line_scoped ? (
                      <>
                        <td>
                          {r.name}
                          {r.sku ? (
                            <span className="block text-xs text-slate-500">
                              {r.sku}
                            </span>
                          ) : null}
                        </td>
                        <td className="text-right tabular-nums">
                          {r.quantity}
                        </td>
                        <td className="text-right tabular-nums">{r.gross}</td>
                        <td className="text-right tabular-nums">
                          {r.discount}
                        </td>
                        <td className="text-right tabular-nums">{r.net}</td>
                      </>
                    ) : (
                      <>
                        <td>{r.payment ?? '—'}</td>
                        <td className="text-right tabular-nums">{r.net}</td>
                        <td className="text-right tabular-nums">
                          {r.shipping}
                        </td>
                        <td className="text-right tabular-nums">
                          {r.payment_fee}
                        </td>
                        <td className="text-right tabular-nums">{r.tax}</td>
                        <td className="text-right tabular-nums">{r.total}</td>
                      </>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={props.detail}
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
