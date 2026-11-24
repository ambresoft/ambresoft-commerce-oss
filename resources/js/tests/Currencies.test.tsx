import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Currencies from '../pages/Admin/Settings/Currencies';
import CurrencyForm from '../pages/Admin/Settings/CurrencyForm';
import type { CurrencySettings } from '../types/currencies';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const strings = {
  'currencies.title': 'Currencies',
  'currencies.primary_help': 'Prices are stored in :name (:code).',
  'currencies.create': 'Add currency',
  'currencies.edit': 'Edit currency',
  'currencies.primary': 'Primary',
  'currencies.code': 'Code',
  'currencies.name': 'Name',
  'currencies.symbol': 'Symbol',
  'currencies.rate': 'Exchange rate',
  'currencies.rate_label': 'Exchange rate: :code per 1 :primary',
  'currencies.rate_hint': 'Converted = amount × rate (:primary → :code)',
  'currencies.rate_primary': 'Always exactly 1',
  'currencies.rate_value': '1 :primary = :rate :code',
  'currencies.sample': 'Format preview',
  'currencies.status': 'Status',
  'currencies.active': 'Active',
  'currencies.disabled': 'Disabled',
  'currencies.decimals': 'Decimal places',
  'currencies.make_primary': 'Make primary currency',
  'currencies.acknowledge': 'I understand',
  'currencies.confirm_label': 'Type :code to confirm',
  'currencies.consequence_prices':
    ':count prices converted from :from to :to × :rate',
  'currencies.consequence_example': ':from becomes :to',
  'currencies.delete': 'Delete currency',
};
const shell = { navigation: [], breadcrumbs: [] };
const primary = { code: 'EUR', name: 'Euro' };
const usd: CurrencySettings = {
  id: '2',
  version: 3,
  code: 'USD',
  name: 'US Dollar',
  symbol: '$',
  symbol_position: 'before',
  thousands_separator: 'comma',
  decimal_separator: 'period',
  decimals: 2,
  rate: '1.1',
  status: 'active',
  primary: false,
};
const preview = {
  products: 4,
  from: '€100.00',
  to: '$110.00',
  rates: [
    { code: 'EUR', from: '1', to: '0.9090909091' },
    { code: 'USD', from: '1.1', to: '1' },
  ],
  error: null,
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'admin@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'currencies.view': true,
      'currencies.create': true,
      'currencies.update': true,
      'currencies.rates': true,
      'currencies.status': true,
      'currencies.primary': true,
      'currencies.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('lists server-formatted samples and rates relative to the primary currency', () => {
  render(
    <Currencies
      {...shell}
      primary={primary}
      currencies={[
        {
          id: '1',
          code: 'EUR',
          name: 'Euro',
          symbol: '€',
          rate: '1',
          primary: true,
          active: true,
          sample: '€1,234.50',
        },
        {
          id: '2',
          code: 'USD',
          name: 'US Dollar',
          symbol: '$',
          rate: '1.1',
          primary: false,
          active: false,
          sample: '$1,234.50',
        },
      ]}
    />,
  );
  expect(screen.getByText('Prices are stored in Euro (EUR).')).toBeVisible();
  expect(screen.getByText('1 EUR = 1.1 USD')).toBeVisible();
  expect(screen.getByText('€1,234.50')).toBeVisible();
  expect(screen.getByText('Primary')).toBeVisible();
  expect(screen.getByText('Disabled')).toBeVisible();
});

it('labels the rate direction and updates without resubmitting the immutable code', () => {
  const put = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CurrencyForm
      {...shell}
      currency={usd}
      primary={primary}
      primaryChange={preview}
    />,
  );
  const rate = screen.getByLabelText('Exchange rate: USD per 1 EUR');
  expect(rate).toHaveValue('1.1');
  fireEvent.change(rate, { target: { value: '1.25' } });
  fireEvent.click(screen.getByRole('button', { name: english['common.save'] }));
  expect(put.mock.calls[0][0]).toBe('/admin/settings/currencies/2');
  const data = put.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({ version: 3, rate: '1.25', status: 'active' });
  expect(data).not.toHaveProperty('code');
});

it('requires acknowledgement and the exact code before changing the primary currency', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CurrencyForm
      {...shell}
      currency={usd}
      primary={primary}
      primaryChange={preview}
    />,
  );
  expect(
    screen.getByText('4 prices converted from EUR to USD × 1.1'),
  ).toBeVisible();
  expect(screen.getByText('€100.00 becomes $110.00')).toBeVisible();
  const submit = screen.getByRole('button', { name: 'Make primary currency' });
  expect(submit).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'I understand' }));
  fireEvent.change(screen.getByLabelText('Type USD to confirm'), {
    target: { value: 'usd' },
  });
  expect(submit).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Type USD to confirm'), {
    target: { value: 'USD' },
  });
  expect(submit).toBeEnabled();
  fireEvent.click(submit);
  expect(post.mock.calls[0][0]).toBe('/admin/settings/currencies/2/primary');
  expect(post.mock.calls[0][1]?.data).toEqual({
    version: 3,
    confirmation: 'USD',
  });
});

it('locks the primary currency rate and status and offers no primary change or deletion', () => {
  render(
    <CurrencyForm
      {...shell}
      currency={{
        ...usd,
        id: '1',
        code: 'EUR',
        name: 'Euro',
        rate: '1',
        primary: true,
      }}
      primary={primary}
      primaryChange={null}
    />,
  );
  expect(screen.getByLabelText('Exchange rate: EUR per 1 EUR')).toHaveAttribute(
    'readonly',
  );
  expect(screen.getByLabelText('Status')).toBeDisabled();
  expect(
    screen.queryByRole('button', { name: 'Make primary currency' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: 'Delete currency' }),
  ).not.toBeInTheDocument();
});

it('creates a currency with an uppercase code', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CurrencyForm
      {...shell}
      currency={null}
      primary={primary}
      primaryChange={null}
    />,
  );
  fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'gbp' } });
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Pound sterling' },
  });
  fireEvent.click(screen.getByRole('button', { name: english['common.save'] }));
  expect(post.mock.calls[0][0]).toBe('/admin/settings/currencies');
  expect(post.mock.calls[0][1]?.data).toMatchObject({
    code: 'GBP',
    name: 'Pound sterling',
  });
});
