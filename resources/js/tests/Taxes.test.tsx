import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import TaxesIndex from '../pages/Admin/Taxes/Index';
import TaxForm from '../pages/Admin/Taxes/Form';
import RateAreasIndex from '../pages/Admin/RateAreas/Index';
import RateAreaForm from '../pages/Admin/RateAreas/Form';
import type { SharedProps } from '../types/page';
import type { TaxRow } from '../types/taxes';
import { english } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const labels: Record<string, string> = {
  'taxes.area_regions_tip_label': 'Help for region codes of :country',
  'taxes.area_regions_tip':
    'Use the part after the dash: GR-K is K, US-CA is CA (:code).',
  'taxes.area_regions_reference': 'ISO 3166-2 code list (:code)',
  'taxes.area_regions_placeholder_gr': 'e.g. K, L',
  'taxes.title': 'Taxes',
  'taxes.description': 'Taxes description',
  'taxes.create': 'Create tax',
  'taxes.edit': 'Edit tax',
  'taxes.search_placeholder': 'Search taxes',
  'taxes.sort_priority': 'Priority',
  'taxes.sort_name': 'Name',
  'taxes.sort_status': 'Status',
  'taxes.active': 'Active',
  'taxes.disabled': 'Disabled',
  'taxes.priority': 'Priority',
  'taxes.price_mode': 'Price',
  'taxes.price_included': 'Included in price',
  'taxes.price_added': 'Added to price',
  'taxes.address_basis': 'Rates depend on',
  'taxes.basis_shipping': 'Shipping address',
  'taxes.basis_billing': 'Billing address',
  'taxes.rates': 'Rates',
  'taxes.products': 'Products',
  'taxes.no_rates': 'No rates configured.',
  'taxes.empty': 'No taxes yet',
  'taxes.no_matches': 'No matches',
  'taxes.delete': 'Delete tax',
  'taxes.delete_confirm': 'Delete :name?',
  'taxes.delete_assigned_hint': 'Assigned to products',
  'taxes.assign_title': 'Assign taxes to all products',
  'taxes.assign_help': 'Assign help',
  'taxes.assign_select': 'Taxes to assign',
  'taxes.assign_confirm': 'I understand this changes :count products.',
  'taxes.assign_action': 'Assign to all products',
  'taxes.section_general': 'General',
  'taxes.registration_number': 'Registration number',
  'taxes.registration_number_hint': 'hint',
  'taxes.priority_hint': 'priority hint',
  'taxes.status_hint': 'status hint',
  'taxes.address_basis_hint': 'basis hint',
  'taxes.price_includes_tax': 'Price includes this tax',
  'taxes.price_includes_tax_hint': 'includes hint',
  'taxes.rates_help': 'rates help',
  'taxes.add_rate': 'Add rate',
  'taxes.remove_rate': 'Remove rate',
  'taxes.rate_area': 'Rate area',
  'taxes.rate_type': 'Rate type',
  'taxes.rate_percent': 'Percentage',
  'taxes.rate_fixed': 'Fixed amount',
  'taxes.rate_value_percent': 'Rate (%)',
  'taxes.rate_value_fixed': 'Amount (:code)',
  'taxes.no_areas': 'Create a rate area first.',
  'taxes.area_default': 'Default area',
  'taxes.areas_title': 'Rate areas',
  'taxes.areas_description': 'Areas description',
  'taxes.areas_empty': 'No rate areas',
  'taxes.area_create': 'Create rate area',
  'taxes.area_edit': 'Edit rate area',
  'taxes.area_countries': 'Countries',
  'taxes.area_regions': 'Regions',
  'taxes.area_taxes': 'Taxes',
  'taxes.area_everywhere': 'All countries',
  'taxes.area_status_hint': 'status',
  'taxes.area_default_help': 'default help',
  'taxes.area_countries_help': 'countries help',
  'taxes.area_search_countries': 'Search countries',
  'taxes.area_selected': ':count countries selected',
  'taxes.area_regions_help': 'regions help',
  'taxes.area_regions_placeholder': 'e.g. CA',
  'taxes.area_delete_confirm': 'Delete area :name?',
  'taxes.area_in_use_hint': 'Used by tax rates',
  'common.name': 'Name',
  'common.save': 'Save changes',
  'common.cancel': 'Cancel',
  'common.edit': 'Edit',
  'common.status': 'Status',
  'common.all': 'All',
  'common.actions': 'Actions',
  'common.wait': 'Please wait…',
};
const shell = { navigation: [], breadcrumbs: [] };
const pagination = (data: TaxRow[]) => ({
  data,
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
  total: data.length,
});
const vat: TaxRow = {
  id: '1',
  version: 3,
  name: 'VAT',
  registration_number: 'EL123',
  priority: 1,
  status: 'active',
  price_includes_tax: true,
  address_basis: 'shipping',
  rates: [
    { area: 'Greece', type: 'percent', label: '24%' },
    { area: 'France', type: 'fixed', label: '€5.00' },
  ],
  products: 0,
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
      'taxes.view': true,
      'taxes.create': true,
      'taxes.update': true,
      'taxes.delete': true,
      'taxes.assign': true,
      'rate-areas.manage': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('lists taxes with rate summaries and blocks deleting assigned taxes', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <TaxesIndex
      {...shell}
      taxes={pagination([vat, { ...vat, id: '2', name: 'Fee', products: 4 }])}
      filters={{}}
      assignable={[]}
      productCount={0}
    />,
  );
  const row = screen.getByRole('link', { name: 'VAT' }).closest('tr')!;
  expect(within(row).getByText('24%')).toBeVisible();
  expect(within(row).getByText('€5.00')).toBeVisible();
  expect(within(row).getByText('Included in price')).toBeVisible();
  expect(
    screen.getByRole('button', { name: /Assigned to products: Fee/ }),
  ).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Delete tax: VAT' }));
  expect(request.mock.calls[0][0]).toBe('/admin/taxes/1');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'delete',
    data: { version: 3 },
  });
});

it('assigns selected taxes to all products only after an explicit confirmation', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <TaxesIndex
      {...shell}
      taxes={pagination([vat])}
      filters={{}}
      assignable={[
        {
          id: '1',
          name: 'VAT',
          active: true,
          price_includes_tax: true,
          priority: 1,
        },
        {
          id: '2',
          name: 'Fee',
          active: true,
          price_includes_tax: false,
          priority: 2,
        },
      ]}
      productCount={42}
    />,
  );
  const button = screen.getByRole('button', {
    name: 'Assign to all products',
  });
  expect(button).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Fee' }));
  expect(button).toBeDisabled();
  fireEvent.click(
    screen.getByLabelText('I understand this changes 42 products.'),
  );
  expect(button).toBeEnabled();
  fireEvent.click(button);
  expect(request.mock.calls[0][0]).toBe('/admin/taxes/assign-all');
  expect(request.mock.calls[0][1]?.data).toEqual({
    tax_ids: ['2'],
    confirmed: true,
  });
});

it('creates a tax with percentage and fixed rates and never offers an area twice', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <TaxForm
      {...shell}
      tax={null}
      defaultLocale="en"
      currency={{ code: 'EUR', symbol: '€', decimals: 2 }}
      areas={[
        { id: '1', name: 'Greece', default: false, active: true },
        { id: '2', name: 'Elsewhere', default: true, active: true },
      ]}
    />,
  );
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'VAT' },
  });
  fireEvent.change(screen.getByLabelText('Priority'), {
    target: { value: '2' },
  });
  fireEvent.click(screen.getByLabelText(/Price includes this tax/));
  fireEvent.click(screen.getByRole('button', { name: 'Add rate' }));
  fireEvent.change(screen.getByLabelText('Rate (%)'), {
    target: { value: '24' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Add rate' }));
  // The second row starts on the remaining area and switches to a fixed amount.
  const types = screen.getAllByRole('combobox', { name: 'Rate type' });
  choose(types[1], 'Fixed amount');
  fireEvent.change(screen.getByLabelText('Amount (EUR)'), {
    target: { value: '1.50' },
  });
  expect(screen.getByRole('button', { name: 'Add rate' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/taxes');
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    names: { en: 'VAT', el: '' },
    priority: '2',
    status: 'active',
    price_includes_tax: true,
    address_basis: 'shipping',
    rates: [
      { area_id: '1', type: 'percent', value: '24' },
      { area_id: '2', type: 'fixed', value: '1.50' },
    ],
  });
});

it('edits an existing tax with its version and removes a rate', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <TaxForm
      {...shell}
      tax={{
        id: '9',
        version: 4,
        names: { en: 'VAT', el: 'ΦΠΑ' },
        registration_number: 'EL1',
        priority: 1,
        status: 'disabled',
        price_includes_tax: false,
        address_basis: 'billing',
        rates: [{ area_id: '1', type: 'percent', value: '13' }],
        products: 2,
      }}
      defaultLocale="en"
      currency={{ code: 'EUR', symbol: '€', decimals: 2 }}
      areas={[{ id: '1', name: 'Greece', default: false, active: true }]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Name · el')).toHaveValue('ΦΠΑ');
  expect(screen.getByLabelText('Rate (%)')).toHaveValue('13');
  fireEvent.click(screen.getByRole('button', { name: 'Remove rate' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/taxes/9');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'put',
    data: {
      version: 4,
      status: 'disabled',
      address_basis: 'billing',
      rates: [],
    },
  });
});

it('shows rate areas with their membership and creates one with regions', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const { unmount } = render(
    <RateAreasIndex
      {...shell}
      areas={[
        {
          id: '1',
          version: 1,
          name: 'Nordics',
          status: 'active',
          default: false,
          countries: ['Denmark', 'Finland'],
          country_count: 5,
          regions: 0,
          taxes: 1,
        },
        {
          id: '2',
          version: 1,
          name: 'Elsewhere',
          status: 'active',
          default: true,
          countries: [],
          country_count: 0,
          regions: 0,
          taxes: 0,
        },
      ]}
    />,
  );
  expect(screen.getByText('Denmark, Finland')).toBeVisible();
  expect(screen.getByText('+3')).toBeVisible();
  expect(screen.getByText('All countries')).toBeVisible();
  expect(
    screen.getByRole('button', { name: /Used by tax rates: Nordics/ }),
  ).toBeDisabled();
  unmount();

  render(
    <RateAreaForm
      {...shell}
      area={null}
      defaultLocale="en"
      countries={[
        { code: 'US', name: 'United States' },
        { code: 'GR', name: 'Greece' },
        { code: 'FR', name: 'France' },
      ]}
    />,
  );
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'West' },
  });
  fireEvent.change(screen.getByLabelText('Search countries'), {
    target: { value: 'united' },
  });
  expect(screen.queryByRole('checkbox', { name: /Greece/ })).toBeNull();
  fireEvent.click(screen.getByRole('checkbox', { name: /United States/ }));
  fireEvent.change(screen.getByLabelText('United States (US)'), {
    target: { value: 'ca, or  wa' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/rate-areas');
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    names: { en: 'West', el: '' },
    is_default: false,
    countries: ['US'],
    regions: { US: ['CA', 'OR', 'WA'] },
  });
});

it('explains region codes with a tooltip and an ISO 3166-2 reference per country', () => {
  render(
    <RateAreaForm
      {...shell}
      area={{
        id: '1',
        version: 1,
        names: { en: 'Greek islands', el: '' },
        status: 'active',
        is_default: false,
        countries: ['GR'],
        regions: { GR: ['K', 'L'] },
        taxes: 0,
      }}
      defaultLocale="en"
      countries={[{ code: 'GR', name: 'Greece' }]}
    />,
  );
  const link = screen.getByRole('link', { name: /ISO 3166-2/ });
  expect(link).toHaveAttribute(
    'href',
    'https://en.wikipedia.org/wiki/ISO_3166-2:GR',
  );
  expect(link).toHaveAttribute('target', '_blank');
  expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  const help = screen.getByRole('button', {
    name: /Help for region codes of Greece/,
  });
  expect(help).toHaveAttribute('aria-describedby', 'regions-tip-GR');
  expect(screen.getByRole('tooltip')).toHaveTextContent(/GR-K is K/);
  expect(screen.getByLabelText('Greece (GR)')).toHaveValue('K, L');
});

it('submits the default area without memberships', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <RateAreaForm
      {...shell}
      area={null}
      defaultLocale="en"
      countries={[{ code: 'GR', name: 'Greece' }]}
    />,
  );
  fireEvent.click(screen.getByRole('checkbox', { name: /Greece/ }));
  fireEvent.click(screen.getByLabelText(/Default area/));
  expect(screen.queryByRole('checkbox', { name: /Greece/ })).toBeNull();
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'Rest of world' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    is_default: true,
    countries: [],
    regions: {},
  });
});
