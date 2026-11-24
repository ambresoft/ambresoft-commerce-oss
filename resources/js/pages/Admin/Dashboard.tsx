import Card from '../../components/admin/AdminCard';
import { LocalTime } from '../../components/admin/AccountSecuritySummary';
import {
  securityKindLabels,
  type SecurityEvent,
} from '../../types/accountSecurity';
import { useTranslation } from '../../lib/useTranslation';
import { Link, usePage } from '@inertiajs/react';
import { Fragment, useState, type ReactNode } from 'react';
import AdminLayout from '../../layouts/admin/AdminLayout';
import SalesChart from '../../components/admin/dashboard/SalesChart';
import Select from '../../components/admin/Select';
import type { Breadcrumb, NavigationItem, SharedProps } from '../../types/page';

type SalesRange = 'day' | 'week' | 'month';

type RangeData = {
  starts: string[];
  current: number[];
  previous: number[];
  count: number;
  previous_count: number;
};

export type DashboardOverview = {
  currency: { code: string; decimals: number };
  orders: {
    ranges: Record<SalesRange, RangeData>;
    open: number;
    recent: {
      id: string;
      number: string;
      customer: string;
      status: string;
      color: string;
      total: string;
      currency: string;
      decimals: number;
      created_at: string;
    }[];
  } | null;
  totals?: {
    categories: number | null;
    brands: number | null;
    pages: number | null;
  };
  catalog: { active: number; out_of_stock: number } | null;
  customers: { total: number; new: Record<SalesRange, number> } | null;
  activity: {
    kind: 'customer' | 'product' | 'status' | 'order';
    subject: string;
    at: string;
  }[];
};

type Props = {
  securityEvents?:
    | (Pick<
        SecurityEvent,
        | 'id'
        | 'user_id'
        | 'kind'
        | 'ip'
        | 'timezone'
        | 'new_device'
        | 'new_ip'
        | 'occurred_at'
      > & { name: string })[]
    | null;
  navigation: NavigationItem[];
  breadcrumbs: Breadcrumb[];
  overview: DashboardOverview;
};

// Literal keys per range (translation keys are never built at runtime).
const rangeText = {
  day: {
    label: 'admin.dashboard.range_day',
    previous: 'admin.dashboard.previous_period_day',
    vs: 'admin.dashboard.vs_previous_day',
    chart: 'admin.dashboard.chart_day',
    orders: 'admin.dashboard.orders_hint_day',
    customers: 'admin.dashboard.customers_hint_day',
  },
  week: {
    label: 'admin.dashboard.range_week',
    previous: 'admin.dashboard.previous_period_week',
    vs: 'admin.dashboard.vs_previous_week',
    chart: 'admin.dashboard.chart_week',
    orders: 'admin.dashboard.orders_hint_week',
    customers: 'admin.dashboard.customers_hint_week',
  },
  month: {
    label: 'admin.dashboard.range_month',
    previous: 'admin.dashboard.previous_period_month',
    vs: 'admin.dashboard.vs_previous_month',
    chart: 'admin.dashboard.chart_month',
    orders: 'admin.dashboard.orders_hint_month',
    customers: 'admin.dashboard.customers_hint_month',
  },
} as const;
const ranges: SalesRange[] = ['day', 'week', 'month'];

// Label spacing and axis gridline step that suit each range's bucket count.
const labelEvery: Record<SalesRange, number> = { day: 6, week: 1, month: 7 };
/** A 1/2/5 × 10ⁿ gridline step giving at most about four gridlines for the highest value. */
function niceStep(highest: number) {
  if (highest <= 0) return 100;
  const raw = highest / 4;
  const power = 10 ** Math.floor(Math.log10(raw));
  const unit = raw / power;
  return (unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10) * power;
}
const sum = (values: number[]) => values.reduce((a, b) => a + b, 0);
const change = (now: number, before: number) =>
  before > 0 ? (now - before) / before : null;

// Renders a translated sentence with its :placeholders emphasised.
function emphasise(template: string, values: Record<string, ReactNode>) {
  return template.split(/:(\w+)/).map((part, index) =>
    index % 2 ? (
      <strong key={index} className="font-semibold text-slate-900">
        {values[part]}
      </strong>
    ) : (
      <Fragment key={index}>{part}</Fragment>
    ),
  );
}

function Change({
  value,
  format,
}: {
  value: number | null;
  format: (value: number) => string;
}) {
  if (value === null) return null;
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-semibold ${value < 0 ? 'text-red-800' : 'text-emerald-900'}`}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 10 10"
        className={`size-2 ${value < 0 ? 'rotate-180' : ''}`}
      >
        <path d="M5 1 9 8H1Z" fill="currentColor" />
      </svg>
      {format(value)}
    </span>
  );
}

export default function Dashboard({
  securityEvents,
  navigation,
  breadcrumbs,
  overview,
}: Props) {
  const t = useTranslation();
  const { auth, i18n, capabilities } = usePage<SharedProps>().props;
  const [range, setRange] = useState<SalesRange>('month');
  const locale = i18n.locale;
  const { orders, catalog, customers, activity } = overview;
  const currency = overview.currency.code;
  const money = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  });
  const compactMoney = new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    notation: 'compact',
  });
  const percent = new Intl.NumberFormat(locale, {
    style: 'percent',
    maximumFractionDigits: 1,
    signDisplay: 'exceptZero',
  });
  const day = new Intl.DateTimeFormat(locale, {
    month: 'short',
    day: 'numeric',
  });
  const hour = new Intl.DateTimeFormat(locale, { hour: '2-digit' });
  const dateTime = new Intl.DateTimeFormat(locale, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
  const text = rangeText[range];
  const data = orders?.ranges[range] ?? null;
  const sales = data ? sum(data.current) : 0;
  const previousSales = data ? sum(data.previous) : 0;
  const highest = data ? Math.max(...data.current, ...data.previous) : 0;
  const labels = data
    ? data.starts.map((start) =>
        (range === 'day' ? hour : day).format(new Date(start)),
      )
    : [];

  const kpis: {
    label: string;
    value: number;
    change?: number | null;
    hint: ReactNode;
  }[] = [];
  if (orders && data) {
    kpis.push(
      {
        label: t('admin.dashboard.orders'),
        value: data.count,
        change: change(data.count, data.previous_count),
        hint: t(text.orders),
      },
      {
        label: t('admin.dashboard.open'),
        value: orders.open,
        hint: t('admin.dashboard.open_hint'),
      },
    );
  }
  if (catalog) {
    kpis.push({
      label: t('admin.dashboard.products'),
      value: catalog.active,
      hint: emphasise(t('admin.dashboard.out_of_stock'), {
        count: catalog.out_of_stock,
      }),
    });
  }
  if (customers) {
    kpis.push({
      label: t('admin.dashboard.customers'),
      value: customers.total,
      hint: (
        <>
          <strong className="font-semibold">{customers.new[range]}</strong>
          {' · '}
          {t(text.customers)}
        </>
      ),
    });
  }
  const sections = orders !== null || catalog !== null || customers !== null;
  const summaryCards = [
    {
      value: overview.totals?.categories,
      label: t('categories.title'),
      icon: 'categories',
      href: '/admin/categories',
    },
    {
      value: overview.totals?.brands,
      label: t('brands.title'),
      icon: 'brand',
      href: '/admin/brands',
    },
    {
      value: overview.totals?.pages,
      label: t('admin.dashboard.content_pages'),
      icon: 'content',
      href: '/admin/pages',
    },
  ].filter((item) => item.value !== null && item.value !== undefined);

  return (
    <AdminLayout
      title={t('admin.dashboard.title')}
      navigation={navigation}
      breadcrumbs={breadcrumbs}
    >
      <div className="mb-7 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
            {t('admin.dashboard.welcome', { name: auth.user?.name ?? '' })}
          </h1>
          {orders && catalog && (
            <p className="mt-2 text-slate-500">
              {emphasise(t('admin.dashboard.summary'), {
                open: orders.open,
                out: catalog.out_of_stock,
              })}
            </p>
          )}
        </div>
        {sections && (
          <div className="flex flex-wrap gap-2">
            <div className="w-44">
              <Select
                aria-label={t('admin.dashboard.range_label')}
                value={range}
                options={ranges.map((value) => ({
                  value,
                  label: t(rangeText[value].label),
                }))}
                onChange={(value) => setRange(value as SalesRange)}
              />
            </div>
          </div>
        )}
      </div>

      {sections && (
        <section
          aria-labelledby="sales-heading"
          className="overflow-hidden hero-card rounded-2xl text-on-primary"
        >
          {data && (
            <div className="px-5 pt-6 pb-5 sm:px-7">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <h2 id="sales-heading" className="text-[15px]">
                    {t('admin.dashboard.sales')}
                  </h2>
                  <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <p className="text-4xl font-semibold tracking-tight sm:text-6xl">
                      {money.format(sales)}
                    </p>
                    <Change
                      value={change(sales, previousSales)}
                      format={percent.format}
                    />
                    <span className="text-sm text-on-primary/85">
                      {t(text.vs)}
                    </span>
                  </div>
                  <p className="mt-2 text-sm text-on-primary/85">
                    {t('admin.dashboard.sales_hint')}
                    {capabilities['reports.view'] && (
                      <>
                        {' · '}
                        <Link className="underline" href="/admin/reports">
                          {t('admin.dashboard.reports_link')}
                        </Link>
                      </>
                    )}
                  </p>
                </div>
                <ul className="flex flex-wrap gap-5 text-sm text-on-primary/90">
                  <li className="flex items-center gap-2">
                    <span
                      aria-hidden="true"
                      className="h-0.5 w-4 bg-on-primary"
                    />
                    {t('admin.dashboard.this_period')}
                  </li>
                  <li className="flex items-center gap-2">
                    <span
                      aria-hidden="true"
                      className="w-4 border-t-2 border-dashed border-on-primary"
                    />
                    {t(text.previous)}
                  </li>
                </ul>
              </div>
              <div className="mt-8">
                <SalesChart
                  current={data.current}
                  previous={data.previous}
                  days={labels}
                  step={niceStep(highest)}
                  every={labelEvery[range]}
                  label={t(text.chart)}
                  formatAxis={(value) =>
                    value ? compactMoney.format(value) : '0'
                  }
                />
              </div>
            </div>
          )}
          <dl
            className={`grid grid-cols-2 lg:grid-cols-4 ${data ? 'border-t border-on-primary/20' : ''}`}
          >
            {kpis.map((kpi, index) => (
              <div
                key={kpi.label}
                className={`px-5 py-5 sm:px-7 ${index % 2 ? 'border-l border-on-primary/20' : ''} ${index > 1 ? 'border-t border-on-primary/20 lg:border-t-0' : ''} ${index > 0 ? 'lg:border-l' : ''}`}
              >
                <dt className="text-sm text-on-primary/90">{kpi.label}</dt>
                <dd className="mt-1.5 flex flex-wrap items-center gap-2.5">
                  <span className="text-3xl font-semibold">{kpi.value}</span>
                  {kpi.change !== undefined && (
                    <Change value={kpi.change} format={percent.format} />
                  )}
                </dd>
                <dd className="mt-1 text-sm text-on-primary/85 [&_strong]:text-on-primary">
                  {kpi.hint}
                </dd>
              </div>
            ))}
          </dl>
        </section>
      )}

      {summaryCards.length > 0 && (
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          {summaryCards.map((item) => (
            <Card key={item.href} title={item.label} iconKey={item.icon}>
              <Link
                href={item.href}
                aria-label={item.label}
                className="text-3xl font-semibold tabular-nums text-primary hover:underline"
              >
                {new Intl.NumberFormat(locale).format(item.value!)}
              </Link>
            </Card>
          ))}
        </div>
      )}

      {sections && (
        <div
          className={`mt-6 grid gap-4 ${orders ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : ''}`}
        >
          {orders && (
            <div className="min-w-0 self-start">
              <Card
                id="orders-heading"
                title={t('admin.dashboard.recent_orders')}
                iconKey="orders"
                action={
                  <Link href="/admin/orders" className="text-link text-sm">
                    {t('admin.dashboard.view_orders')}
                  </Link>
                }
              >
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[34rem] text-left text-sm">
                    <thead className="text-slate-500">
                      <tr className="border-b border-slate-200">
                        <th className="py-2.5 font-normal">
                          {t('admin.dashboard.order')}
                        </th>
                        <th className="py-2.5 font-normal">
                          {t('admin.dashboard.customer')}
                        </th>
                        <th className="py-2.5 font-normal">
                          {t('admin.dashboard.status')}
                        </th>
                        <th className="py-2.5 font-normal">
                          {t('admin.dashboard.date')}
                        </th>
                        <th className="py-2.5 text-right font-normal">
                          {t('admin.dashboard.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {orders.recent.map((order) => (
                        <tr key={order.id}>
                          <td className="py-3.5 font-semibold">
                            <Link
                              href={'/admin/orders/' + order.id}
                              className="hover:underline"
                            >
                              <span className="font-normal text-slate-400">
                                #
                              </span>
                              {order.number}
                            </Link>
                          </td>
                          <td className="py-3.5">{order.customer}</td>
                          <td className="py-3.5">
                            <span className="inline-flex items-center gap-2">
                              <span
                                aria-hidden="true"
                                className="size-2 rounded-full"
                                style={{ backgroundColor: order.color }}
                              />
                              {order.status}
                            </span>
                          </td>
                          <td className="py-3.5 text-slate-500">
                            {day.format(new Date(order.created_at))}
                          </td>
                          <td className="py-3.5 text-right font-semibold tabular-nums">
                            {new Intl.NumberFormat(locale, {
                              style: 'currency',
                              currency: order.currency,
                              minimumFractionDigits: order.decimals,
                              maximumFractionDigits: order.decimals,
                            }).format(Number(order.total))}
                          </td>
                        </tr>
                      ))}
                      {orders.recent.length === 0 && (
                        <tr>
                          <td
                            colSpan={5}
                            className="py-6 text-center text-slate-500"
                          >
                            {t('admin.dashboard.no_orders')}
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </Card>
            </div>
          )}

          <div className="min-w-0 self-start">
            <Card
              id="activity-heading"
              title={t('admin.dashboard.activity')}
              iconKey="history"
              bodyClassName="max-h-96 overflow-y-auto p-5"
            >
              {activity.length === 0 ? (
                <p className="text-sm text-slate-500">
                  {t('admin.dashboard.no_activity')}
                </p>
              ) : (
                <ol>
                  {activity.map((entry, index) => (
                    <li
                      key={entry.kind + entry.subject + entry.at}
                      className="relative pb-5 pl-8 last:pb-0"
                    >
                      {index < activity.length - 1 && (
                        <span
                          aria-hidden="true"
                          className="absolute top-5 bottom-0 left-[7px] w-px bg-slate-200"
                        />
                      )}
                      <span
                        aria-hidden="true"
                        className={`absolute top-1 left-0 size-4 rounded-full border-2 border-primary ${index ? 'bg-white' : 'bg-primary'}`}
                      />
                      <p className="font-semibold">
                        {t(`admin.dashboard.activity_${entry.kind}`)}
                      </p>
                      <p className="text-sm">
                        {entry.kind === 'status' || entry.kind === 'order'
                          ? t('admin.dashboard.order_number', {
                              number: entry.subject,
                            })
                          : entry.subject}
                      </p>
                      <p className="mt-0.5 text-xs text-slate-500">
                        <time dateTime={entry.at}>
                          {dateTime.format(new Date(entry.at))}
                        </time>
                      </p>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          </div>
        </div>
      )}
      {securityEvents && capabilities['account-security.view'] && (
        <div className="mt-6">
          <Card
            id="dashboard-account-security"
            icon="shield"
            title={t('account_security.title')}
          >
            <div className="overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('account_security.identity')}</th>
                    <th>{t('account_security.when')}</th>
                    <th>{t('account_security.event')}</th>
                    <th>{t('account_security.ip')}</th>
                    <th>{t('account_security.local_time')}</th>
                    <th>{t('account_security.alerts')}</th>
                  </tr>
                </thead>
                <tbody>
                  {securityEvents.map((event) => (
                    <tr key={event.id}>
                      <td>
                        <Link
                          className="text-link"
                          href={'/admin/account-security/' + event.user_id}
                        >
                          {event.name}
                        </Link>
                      </td>
                      <td>{dateTime.format(new Date(event.occurred_at))}</td>
                      <td>{t(securityKindLabels[event.kind])}</td>
                      <td className="font-mono">{event.ip ?? '—'}</td>
                      <td>
                        <LocalTime timezone={event.timezone} />
                      </td>
                      <td>
                        {event.new_device && (
                          <span className="block text-amber-700">
                            {t('account_security.new_device')}
                          </span>
                        )}
                        {event.new_ip && (
                          <span className="block text-amber-700">
                            {t('account_security.new_ip')}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}
    </AdminLayout>
  );
}
