import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import CountriesIndex from '../pages/Admin/Countries/Index';
import RegionsIndex from '../pages/Admin/Regions/Index';
import type { Pagination as Page } from '../types/admin';
import type { RegionRow } from '../types/geography';
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
const keys = [
  'countries_title',
  'countries_description',
  'countries_search',
  'country',
  'country_code',
  'country_shipping',
  'shipping_filter',
  'shipping_yes',
  'shipping_no',
  'regions_count',
  'manage_regions',
  'countries_empty',
  'regions_title',
  'regions_description',
  'region_add',
  'region_edit',
  'region_code',
  'region_code_hint',
  'region_name',
  'region_status',
  'region_active',
  'region_disabled',
  'region_search',
  'region_country',
  'regions_empty',
  'regions_no_countries',
  'region_delete',
  'region_close',
  'bulk_activate',
  'bulk_disable',
  'select_all',
];
const labels: Record<string, string> = {
  ...Object.fromEntries(keys.map((k) => ['geography.' + k, k])),
  'geography.shipping_toggle': 'Ship to :country',
  'geography.shipping_summary': ':count shipping',
  'geography.select_row': 'Select :name',
  'geography.region_delete_confirm': 'Delete :name?',
  'common.cancel': 'Cancel',
  'common.save': 'Save',
  'common.all': 'All',
  'common.actions': 'Actions',
  'common.selected': ':count selected',
};
const shell = { navigation: [], breadcrumbs: [] };
const paging = {
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
};

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'a@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'countries.manage': true,
      'regions.view': true,
      'regions.manage': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Greek' },
      ],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
});

it('shows countries with a shipping switch and a link to their regions', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CountriesIndex
      {...shell}
      filters={{}}
      shippingTotal={1}
      countries={{
        ...paging,
        total: 2,
        data: [
          { code: 'DE', name: 'Germany', shipping: false, regions: 0 },
          { code: 'GR', name: 'Greece', shipping: true, regions: 51 },
        ],
      }}
    />,
  );
  expect(screen.getByText('1 shipping')).toBeVisible();
  const greece = screen.getByText('Greece').closest('tr')!;
  expect(
    within(greece).getByRole('link', { name: /manage_regions: Greece/ }),
  ).toHaveAttribute('href', '/admin/regions?country=GR');
  fireEvent.click(screen.getByRole('switch', { name: 'Ship to Germany' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/countries/DE');
  expect(visit.mock.calls[0][1]).toMatchObject({ method: 'put' });
});

const regions: Page<RegionRow> = {
  ...paging,
  total: 2,
  data: [
    {
      id: '1',
      code: 'ATT',
      name: 'Attica',
      status: 'active' as const,
      version: 3,
      names: { en: 'Attica', el: 'Αττική' },
    },
    {
      id: '2',
      code: 'EVV',
      name: 'Euboea',
      status: 'disabled' as const,
      version: 1,
      names: { en: 'Euboea' },
    },
  ],
};
const countries = [{ code: 'GR', name: 'Greece' }];

it('edits a region in the drawer with every active language and its version', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <RegionsIndex
      {...shell}
      regions={regions}
      filters={{ country: 'GR' }}
      countries={countries}
      defaultLocale="en"
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: /region_edit: Attica/ }));
  const drawer = screen.getByRole('dialog');
  fireEvent.click(within(drawer).getByRole('button', { name: 'Greek' }));
  expect(within(drawer).getByLabelText(/region_name · el/)).toHaveValue(
    'Αττική',
  );
  fireEvent.click(within(drawer).getByRole('button', { name: 'English' }));
  fireEvent.change(within(drawer).getByLabelText(/region_name · en/), {
    target: { value: 'Attica region' },
  });
  fireEvent.click(within(drawer).getByRole('button', { name: 'Save' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/regions/1');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'put',
    data: { version: 3, code: 'ATT', names: { en: 'Attica region' } },
  });
});

it('adds a region for the selected country and bulk changes status', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <RegionsIndex
      {...shell}
      regions={regions}
      filters={{ country: 'GR' }}
      countries={countries}
      defaultLocale="en"
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'region_add' }));
  const drawer = screen.getByRole('dialog');
  fireEvent.change(within(drawer).getByLabelText('region_code'), {
    target: { value: 'dra' },
  });
  fireEvent.click(within(drawer).getByRole('button', { name: 'English' }));
  fireEvent.change(within(drawer).getByLabelText(/region_name · en/), {
    target: { value: 'Drama' },
  });
  fireEvent.click(within(drawer).getByRole('button', { name: 'Save' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/regions');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'post',
    data: { country_code: 'GR', code: 'DRA', status: 'active' },
  });
});

it('bulk-disables selected regions', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <RegionsIndex
      {...shell}
      regions={regions}
      filters={{ country: 'GR' }}
      countries={countries}
      defaultLocale="en"
    />,
  );
  fireEvent.click(screen.getByLabelText('Select Attica'));
  fireEvent.click(screen.getByRole('button', { name: 'bulk_disable' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/regions/status');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'post',
    data: { ids: ['1'], status: 'disabled' },
  });
});

it('explains when no country ships yet', () => {
  render(
    <RegionsIndex
      {...shell}
      regions={{ ...paging, total: 0, data: [] }}
      filters={{}}
      countries={[]}
      defaultLocale="en"
    />,
  );
  expect(screen.getByText('regions_no_countries')).toBeVisible();
});
