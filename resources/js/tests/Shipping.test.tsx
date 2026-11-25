import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ShippingMethodForm from '../pages/Admin/ShippingMethods/Form';
import ShippingCarrierShow from '../pages/Admin/ShippingCarriers/Show';
import ShippingCarrierManifests from '../pages/Admin/ShippingCarriers/Manifests';
import ShippingCarriersIndex from '../pages/Admin/ShippingCarriers/Index';
import type { CarrierView, SettingFieldView } from '../types/shipping';
import { choose } from './select';

const labels: Record<string, string> = {
  'common.save': 'Save',
  'common.name': 'Name',
  'common.edit': 'Edit',
  'shipping.calculation': 'Calculation',
  'shipping.calculation_carrier': 'Carrier rates',
  'shipping.provider': 'Carrier',
  'shipping.account': 'Account',
  'shipping.add_rate': 'Add rate',
  'shipping.image': 'Image',
  'shipping.rate_area': 'Area',
  'shipping.rate_field_base': 'Base',
  'shipping.rate_field_per_unit': 'Per unit',
  'shipping.tab_accounts': 'Accounts',
  'shipping.account_create': 'Add account',
  'shipping.account_name': 'Account name',
  'shipping.secret_set': 'Stored',
  'shipping.secret_clear': 'Remove stored value',
  'shipping.verify': 'Test connection',
  'shipping.manifest_issue': 'Issue pickup list',
  'shipping.manifest_unprinted': ':count unprinted',
  'shipping.manifest_print': 'Print',
  'shipping.carriers_title': 'Carriers',
  'shipping.carriers_available': 'Available carriers',
  'shipping.carrier_configure': 'Settings and accounts',
  'shipping.manifests_title': 'Pickup lists',
  'shipping.carriers_addons': 'Open Add-ons',
  'shipping.carriers_empty': 'No carrier add-on is enabled.',
  'shipping.accounts_count': ':count accounts',
};
const shared = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    i18n: {
      locale: 'en',
      strings: labels,
      locales: [{ code: 'en', name: 'English' }],
    },
    capabilities: {
      'orders.shipments': true,
      'shipping-carriers.manage': true,
    },
    errors: {},
  };
});

const shell = { navigation: [], breadcrumbs: [] };

it('keeps carrier setup and pickup links scoped to staff capabilities and provider support', () => {
  const carriers = [
    { id: 'swift', name: 'Swift Courier', accounts: 0, manifests: true },
    { id: 'other', name: 'Other courier', accounts: 2, manifests: false },
  ];
  const view = render(<ShippingCarriersIndex {...shell} carriers={carriers} />);
  const cards = screen.getAllByRole('listitem');
  expect(
    within(cards[0]).getByRole('link', { name: 'Settings and accounts' }),
  ).toHaveAttribute('href', '/admin/shipping-carriers/swift');
  expect(
    within(cards[0]).getByRole('link', { name: 'Pickup lists' }),
  ).toHaveAttribute('href', '/admin/shipping-carriers/swift/manifests');
  expect(
    within(cards[1]).queryByRole('link', { name: 'Pickup lists' }),
  ).not.toBeInTheDocument();
  expect(within(cards[1]).getByText('2 accounts')).toBeInTheDocument();
  view.unmount();
  shared.props.capabilities = { 'orders.shipments': true };
  render(<ShippingCarriersIndex {...shell} carriers={carriers} />);
  expect(
    screen.queryByRole('link', { name: 'Settings and accounts' }),
  ).not.toBeInTheDocument();
  expect(
    screen.getByRole('link', { name: 'Pickup lists' }),
  ).toBeInTheDocument();
});

it('offers add-on management in the carrier empty state only with permission', () => {
  const view = render(<ShippingCarriersIndex {...shell} carriers={[]} />);
  expect(screen.getByText('No carrier add-on is enabled.')).toBeInTheDocument();
  expect(
    screen.queryByRole('link', { name: 'Open Add-ons' }),
  ).not.toBeInTheDocument();
  shared.props.capabilities = { 'addons.manage': true };
  view.rerender(<ShippingCarriersIndex {...shell} carriers={[]} />);
  expect(screen.getByRole('link', { name: 'Open Add-ons' })).toHaveAttribute(
    'href',
    '/admin/addons',
  );
});

const field = (
  key: string,
  type: SettingFieldView['type'],
  set = false,
): SettingFieldView => ({
  key,
  type,
  label: key,
  hint: '',
  required: true,
  options: [],
  max_length: 100,
  min: null,
  max: null,
  value: type === 'secret' ? '' : 'CO-1',
  set,
});

it('edits manual rates and only offers carriers that can price for carrier methods', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ShippingMethodForm
      {...shell}
      method={null}
      providers={[
        {
          id: 'swift',
          name: 'Swift Courier',
          quotes: true,
          shipments: true,
          accounts: [{ id: '4', name: 'Athens', usable: true }],
        },
        {
          id: 'labels',
          name: 'Labels only',
          quotes: false,
          shipments: true,
          accounts: [],
        },
      ]}
      areas={[{ id: '1', name: 'Greece' }]}
      taxes={[]}
      paymentMethods={[]}
      customerGroups={[]}
      weightUnit="kg"
      currency={{ code: 'EUR', decimals: 2 }}
      defaultLocale="en"
    />,
  );
  fireEvent.change(screen.getByLabelText(/^Name/), {
    target: { value: 'Courier' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Add rate' }));
  choose(screen.getByRole('combobox', { name: 'Area' }), 'Greece');
  fireEvent.change(screen.getByLabelText('Base'), {
    target: { value: '3.50' },
  });
  fireEvent.change(screen.getByLabelText('Per unit'), {
    target: { value: '1.20' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  const data = visit.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(visit.mock.calls[0][0]).toBe('/admin/shipping-methods');
  expect(data.rates).toEqual([
    {
      rate_area_id: '1',
      weight_from: '0',
      weight_to: '',
      base: '3.50',
      per_unit: '1.20',
    },
  ]);
  expect(visit.mock.calls[0][1]?.forceFormData).toBeFalsy();
  expect(data.translations).toEqual({
    en: { name: 'Courier', description: '', delivery_time: '' },
  });

  // A chosen image is sent as multipart form data.
  const logo = new File(['png'], 'swift.png', { type: 'image/png' });
  fireEvent.change(screen.getByLabelText('Image', { selector: 'input' }), {
    target: { files: [logo] },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(visit.mock.calls[1][1]?.forceFormData).toBe(true);
  expect((visit.mock.calls[1][1]?.data as { image: File }).image.name).toBe(
    logo.name,
  );

  // Carrier calculation: rates disappear and only quoting carriers are offered.
  choose(
    screen.getByRole('combobox', { name: 'Calculation' }),
    'Carrier rates',
  );
  expect(screen.queryByRole('button', { name: 'Add rate' })).toBeNull();
  const carriers = screen.getByRole('combobox', { name: 'Carrier' });
  fireEvent.click(carriers);
  expect(screen.queryByRole('option', { name: 'Labels only' })).toBeNull();
  choose(carriers, 'Swift Courier');
  choose(screen.getByRole('combobox', { name: 'Account' }), 'Athens');
});

const carrier: CarrierView = {
  id: 'swift',
  name: 'Swift Courier',
  owner: 'acme/swift-courier',
  owner_title: 'Swift Courier',
  version: 2,
  settings: [],
  settings_issues: [],
  capabilities: {
    quotes: true,
    shipments: true,
    labels: true,
    tracking: true,
    cancel: true,
    verify: true,
    manifests: true,
  },
  manifest_label: 'Pickup lists',
  account_fields: [field('company_id', 'text'), field('api_key', 'secret')],
  accounts: [
    {
      id: '4',
      version: 3,
      name: 'Athens',
      issues: [],
      verified: false,
      verified_at: null,
      fields: [field('company_id', 'text'), field('api_key', 'secret', true)],
      methods: 1,
    },
  ],
};

it('keeps stored carrier secrets write-only and tests a connection', () => {
  window.history.replaceState(
    {},
    '',
    '/admin/shipping-carriers/swift?tab=accounts',
  );
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<ShippingCarrierShow {...shell} carrier={carrier} />);
  fireEvent.click(screen.getByRole('button', { name: 'Test connection' }));
  expect(visit.mock.calls[0][0]).toBe(
    '/admin/shipping-carriers/swift/accounts/4/verify',
  );

  fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
  // The stored key is announced, never shown; an untouched field is sent empty (keeps the value).
  expect(screen.getByLabelText(/api_key/)).toHaveValue('');
  expect(screen.getByText('Stored')).toBeVisible();
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'Remove stored value' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  const [url, options] = visit.mock.calls[1];
  expect(url).toBe('/admin/shipping-carriers/swift/accounts/4');
  expect(options?.data).toEqual({
    version: 3,
    name: 'Athens',
    settings: { company_id: 'CO-1', api_key: '' },
    clear: { api_key: true },
  });
});

it('warns about unprinted vouchers and issues a pickup list with its request key', () => {
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <ShippingCarrierManifests
      {...shell}
      carrier={{
        id: 'swift',
        name: 'Swift Courier',
        manifest_label: 'Pickup lists',
      }}
      accounts={[{ id: '4', name: 'Athens' }]}
      account="4"
      date="2026-10-14"
      day={{
        lists: [
          { number: '2310140001', issued_at: null, count: 3, local: true },
        ],
        shipments: [
          {
            id: '9',
            order_id: '5',
            order_number: '100005',
            tracking_number: '720000001',
            printed: false,
            manifest_number: null,
          },
        ],
        request_key: '0b6f1f8e-2c51-4c1e-9d3e-5f0f3b0c9a11',
      }}
      list={null}
      contents={null}
      error={null}
    />,
  );
  expect(screen.getByRole('status')).toHaveTextContent('1 unprinted');
  expect(
    within(screen.getByText('2310140001').closest('li')!).getByRole('link', {
      name: 'Print',
    }),
  ).toHaveAttribute(
    'href',
    '/admin/shipping-carriers/swift/manifests/2310140001/print?account=4&date=2026-10-14',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Issue pickup list' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/shipping-carriers/swift/manifests',
    {
      account: '4',
      date: '2026-10-14',
      request_key: '0b6f1f8e-2c51-4c1e-9d3e-5f0f3b0c9a11',
    },
    expect.anything(),
  );
});
