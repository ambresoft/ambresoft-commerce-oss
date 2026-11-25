import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import BarList from '../components/admin/reports/BarList';
import LineChart from '../components/admin/reports/LineChart';
import MetricCard from '../components/admin/reports/MetricCard';
import ReportShell from '../components/admin/reports/ReportShell';
import Brands from '../pages/Admin/Reports/Brands';
import type { ReportCommon, SeriesPoint } from '../types/reports';

const labels: Record<string, string> = {
  'reports.not_available': 'Not available',
  'reports.vs_previous': 'vs previous period',
  'reports.previous_period': 'Previous period',
  'reports.chart_empty': 'No sales in this period.',
  'reports.unit_orders': 'orders',
  'reports.period': 'Period',
  'reports.period_this_month': 'This month',
  'reports.period_last_30_days': 'Last 30 days',
  'reports.period_custom': 'Custom range',
  'reports.period_today': 'Today',
  'reports.period_yesterday': 'Yesterday',
  'reports.period_last_7_days': 'Last 7 days',
  'reports.period_previous_month': 'Previous month',
  'reports.period_this_year': 'This year',
  'reports.currency': 'Currency',
  'reports.payment_method': 'Payment method',
  'reports.all': 'All',
  'reports.compare': 'Compare with the previous period',
  'reports.filters': 'Filters',
  'reports.export_csv': 'Export CSV',
  'reports.other_currencies': 'Other currencies: :list',
  'reports.period_note': 'Period :from – :to',
  'reports.amounts_in': 'amounts in :currency',
  'reports.compared_with': 'compared with :from – :to',
  'reports.no_brand': 'No brand',
};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({
    url: '/admin/reports/sales?period=this_month&sort=net',
    props: {
      i18n: { locale: 'en', strings: labels, locales: [] },
      errors: {},
      capabilities: {},
    },
  }),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const point = (day: number, orders: number, net: string): SeriesPoint => ({
  at: '2026-10-' + String(day).padStart(2, '0') + 'T00:00:00+03:00',
  orders,
  net,
});

it('shows a change only when the previous period has figures and says when a value is unavailable', () => {
  const { rerender } = render(
    <MetricCard label="Net" value="€480.00" current={480} previous={400} />,
  );
  expect(screen.getByText('▲ 20.0%')).toBeInTheDocument();
  rerender(
    <MetricCard label="Net" value="€480.00" current={480} previous={0} />,
  );
  expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  rerender(
    <MetricCard label="Net" value="€480.00" current={480} previous={null} />,
  );
  expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  rerender(<MetricCard label="Refunds" value={null} />);
  expect(screen.getByText('Not available')).toBeInTheDocument();
  rerender(
    <MetricCard label="Net" value="€300.00" current={300} previous={400} />,
  );
  expect(screen.getByText('▼ 25.0%')).toBeInTheDocument();
});

it('draws a chart with the previous period and an empty state for no sales', () => {
  const points = [point(1, 1, '10'), point(2, 3, '30')];
  const { rerender } = render(
    <LineChart
      title="Sales"
      points={points}
      previous={[point(1, 2, '20'), point(2, 0, '0')]}
      metric="net"
      currency={{ code: 'EUR', decimals: 2 }}
      locale="en"
      granularity="day"
    />,
  );
  expect(screen.getByRole('img', { name: 'Sales' })).toBeInTheDocument();
  expect(screen.getByText('EUR')).toBeInTheDocument();
  expect(screen.getByText('Previous period')).toBeInTheDocument();
  expect(
    screen.queryByText('No sales in this period.'),
  ).not.toBeInTheDocument();
  rerender(
    <LineChart
      title="Sales"
      points={[point(1, 0, '0')]}
      previous={null}
      metric="orders"
      currency={{ code: 'EUR', decimals: 2 }}
      locale="en"
      granularity="day"
    />,
  );
  expect(screen.getByText('No sales in this period.')).toBeInTheDocument();
});

it('ranks bars by value and shows the exact figure', () => {
  render(
    <BarList
      title="Top"
      empty="Nothing"
      bars={[
        { key: 'a', label: 'A', value: 10, text: '€10.00' },
        { key: 'b', label: 'B', value: 5, text: '€5.00' },
      ]}
    />,
  );
  const items = within(
    screen.getByRole('region', { name: 'Top' }),
  ).getAllByRole('listitem');
  expect(items).toHaveLength(2);
  expect(items[0]).toHaveTextContent('€10.00');
  render(<BarList title="Empty" empty="Nothing" bars={[]} />);
  expect(screen.getByText('Nothing')).toBeInTheDocument();
});

const common: ReportCommon = {
  report: 'sales',
  applicable: ['period', 'currency', 'payment', 'compare'],
  period: {
    preset: 'this_month',
    from: '2026-10-01',
    to: '2026-10-17',
    days: 17,
    granularity: 'day',
    timezone: 'Europe/Athens',
  },
  previous: { from: '2026-09-01', to: '2026-09-17' },
  filters: {
    compare: false,
    currency: 'EUR',
    date: 'paid',
    status_id: null,
    payment_method_id: null,
    shipping_method_id: null,
    product_id: null,
    category_id: null,
    customer_id: null,
  },
  currency: { code: 'EUR', decimals: 2 },
  currencies: ['EUR', 'USD'],
  other_currencies: [{ currency: 'USD', orders: 1 }],
  options: {
    statuses: [],
    payment_methods: [{ value: '4', label: 'Cash on delivery' }],
    shipping_methods: [],
    categories: [],
  },
  links: {
    can_customers: false,
    can_export: true,
    can_orders: true,
    can_products: true,
    can_categories: true,
  },
};

it('applies a changed filter through the URL and warns about other currencies', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
  render(
    <ReportShell
      common={common}
      title="Sales"
      description="Money"
      shell={{ navigation: [], breadcrumbs: [] }}
    >
      <p>content</p>
    </ReportShell>,
  );
  expect(screen.getByRole('note')).toHaveTextContent('USD (1)');
  expect(screen.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
    'href',
    '/admin/reports/sales/export?period=this_month&sort=net',
  );
  fireEvent.click(screen.getByLabelText('Compare with the previous period'));
  expect(get).toHaveBeenCalledWith(
    '/admin/reports/sales',
    expect.objectContaining({
      period: 'this_month',
      compare: '1',
      sort: 'net',
    }),
    expect.objectContaining({ preserveScroll: true }),
  );
});

it('ranks brands with their share, growth and deleted brands, and keeps unbranded sales apart', () => {
  const brand = (
    id: string,
    name: string,
    net: string,
    change: string | null,
    exists = true,
  ) => ({
    id,
    name,
    code: null,
    exists,
    units: 1,
    orders: 1,
    products: 1,
    net,
    share: '50',
    change,
    gross_formatted: '€' + net,
    discounts_formatted: '€0',
    net_formatted: '€' + net,
    previous_net_formatted: '€100',
  });
  const total = {
    units: 3,
    orders: 2,
    products: 3,
    gross_formatted: '€300',
    discounts_formatted: '€0',
    net_formatted: '€300',
  };
  render(
    <Brands
      navigation={[]}
      breadcrumbs={[]}
      {...common}
      filters={{ ...common.filters, compare: true }}
      links={{ ...common.links, can_brands: true }}
      search=""
      brand_count={2}
      brands={
        {
          data: [
            brand('7', 'Acme', '150', '-5'),
            brand('deleted:Zeta', 'Zeta', '100', null, false),
          ],
          current_page: 1,
          last_page: 1,
          per_page: 25,
          total: 2,
          from: 1,
          to: 2,
          links: [],
        } as never
      }
      total={total}
      unbranded={{ ...total, units: 1, net_formatted: '€50' }}
    />,
  );
  const table = within(screen.getByRole('table'));
  expect(table.getByRole('link', { name: 'Acme' })).toHaveAttribute(
    'href',
    '/admin/brands/7/edit',
  );
  expect(table.getByText('▼ 5.0%')).toBeVisible();
  // A deleted brand is named but never linked, and a brand without previous sales shows no percentage.
  expect(table.queryByRole('link', { name: 'Zeta' })).not.toBeInTheDocument();
  expect(table.getByText('—')).toBeVisible();
  expect(table.getByText('No brand')).toBeVisible();
});
