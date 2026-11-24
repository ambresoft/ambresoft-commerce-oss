import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import DefaultTheme from '../storefront/themes/Default';
import type {
  AccountPageData,
  PublicBlock,
  StorefrontPage,
} from '../storefront/types';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props }),
  Head: () => null,
}));

const words: Record<string, string> = {
  'account.menu_title': 'My account',
  'account.nav_profile': 'My details',
  'account.nav_orders': 'Orders',
  'account.nav_addresses': 'Addresses',
  'account.nav_reviews': 'Reviews',
  'account.nav_password': 'Password',
  'account.profile': 'Your details',
  'account.profile_hint': 'Signed in as :email · customer since :date.',
  'account.email_hint': 'To change your e-mail address, contact the store.',
  'account.delete_account': 'Delete my account',
  'account.delete_account_hint': 'Your account will be closed.',
  'account.delete_confirmation_label': 'Type :email to confirm',
  'account.delete_confirm': 'Delete account',
  'account.orders_title': 'Order history',
  'account.orders_empty': 'No orders yet',
  'account.orders_page': 'Page :current of :last',
  'account.orders_previous': 'Previous',
  'account.orders_next': 'Next',
  'account.order_number': 'Order',
  'account.order_date': 'Date',
  'account.order_status': 'Status',
  'account.order_items': 'Items',
  'account.order_total': 'Total',
  'account.address_add': 'Add address',
  'account.address_edit': 'Edit address',
  'account.address_save': 'Save address',
  'account.address_delete': 'Delete',
  'account.address_delete_title': 'Delete this address?',
  'account.no_addresses': 'No addresses',
  'account.default_billing': 'Billing',
  'account.default_shipping': 'Shipping',
  'account.make_billing': 'Use for billing',
  'account.make_shipping': 'Use for shipping',
  'account.to_review': 'Products to review',
  'account.review_write': 'Write a review',
  'account.review_submit': 'Submit review',
  'account.review_moderation': 'Reviews are checked first.',
  'account.my_reviews': 'Your reviews',
  'account.nothing_to_review': 'Nothing to review',
  'account.no_reviews': 'No reviews yet',
  'account.review_published': 'Published',
  'customers.postal_code': 'Postal code',
  'customers.country': 'Country',
  'customers.country_choose': 'Choose a country',
  'customers.region': 'Region',
  'customers.region_choose': 'Choose a region',
  'customers.region_choose_country': 'Choose a country first',
  'customers.first_name': 'First name',
  'customers.last_name': 'Last name',
  'customers.line1': 'Address',
  'customers.street_number': 'Number',
  'customers.city': 'City',
  'customers.floor': 'Floor',
  'customers.bell_name': 'Name on the doorbell',
  'customers.address_notes': 'Delivery notes',
  'customers.address_notes_hint': 'For the courier',
  'storefront.wishlist_title': 'Favourites',
  'storefront.review_rating': 'Your rating',
  'storefront.review_body': 'Your review',
  'storefront.wishlist_cancel': 'Cancel',
  'navigation.sign_out': 'Sign out',
  'auth.first_name': 'First name',
  'auth.last_name': 'Last name',
  'auth.email': 'Email address',
  'common.save': 'Save',
};
const profile = {
  first_name: 'Maria',
  last_name: 'Papadopoulou',
  email: 'maria@example.test',
  phone: '',
  since: '2026-10-19T10:00:00Z',
  version: 2,
};
const address = {
  id: '5',
  label: 'Home',
  first_name: 'Maria',
  last_name: 'Papadopoulou',
  company: null,
  line1: 'Ermou',
  street_number: '1',
  line2: null,
  city: 'Athens',
  postal_code: '10563',
  country_code: 'GR',
  country: 'Greece',
  region_code: null,
  region: null,
  phone: null,
  floor: '3rd',
  bell_name: 'Papadopoulou',
  notes: 'Ring twice',
};
const view = (data: Partial<AccountPageData>) => {
  const block: PublicBlock = {
    id: 'b',
    template: 'main_content',
    title: '',
    css_class: '',
    mobile: true,
    tablet: true,
    desktop: true,
    content: {
      account: {
        section: 'profile',
        wishlist_enabled: true,
        name: 'Maria',
        ...data,
      },
    },
  };
  const page: StorefrontPage = {
    theme: 'default',
    signed_in: true,
    regions: {
      content: [{ id: 'g', width: 16, row: 1, children: [], blocks: [block] }],
    },
  };
  return render(<DefaultTheme page={page} />);
};

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'Shop' },
    auth: { user: null, canAccessAdmin: false },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: {},
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: { ...english, ...words },
    },
  } as SharedProps;
});

it('shows the account menu with the open section marked and signs out', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  view({ profile });
  const nav = screen.getByRole('navigation', { name: 'My account' });
  expect(within(nav).getByRole('link', { name: 'My details' })).toHaveAttribute(
    'aria-current',
    'page',
  );
  expect(within(nav).getByRole('link', { name: 'Orders' })).toHaveAttribute(
    'href',
    '/account/orders',
  );
  expect(within(nav).getByRole('link', { name: 'Favourites' })).toHaveAttribute(
    'href',
    '/wishlist',
  );
  expect(screen.getByDisplayValue('maria@example.test')).toHaveAttribute(
    'readonly',
  );
  fireEvent.click(within(nav).getByRole('button', { name: 'Sign out' }));
  expect(visit.mock.calls[0]![0]).toBe('/logout');
});

it('hides the favourites link when wishlists are off', () => {
  view({ profile, wishlist_enabled: false });
  expect(screen.queryByRole('link', { name: 'Favourites' })).toBeNull();
});

it('asks for the e-mail address before deleting the account', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  view({ profile });
  fireEvent.click(screen.getByRole('button', { name: 'Delete my account' }));
  const dialog = screen.getByRole('dialog', { name: 'Delete my account' });
  const confirm = within(dialog).getByRole('button', {
    name: 'Delete account',
  });
  expect(confirm).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText(/Type maria@example.test/), {
    target: { value: 'maria@example.test' },
  });
  fireEvent.click(confirm);
  expect(visit.mock.calls[0]![0]).toBe('/account');
  expect(visit.mock.calls[0]![1]).toMatchObject({
    method: 'delete',
    data: { version: 2, confirmation: 'maria@example.test' },
  });
});

it('lists the order history in a table with page links', () => {
  view({
    section: 'orders',
    orders: {
      page: 2,
      last_page: 3,
      total: 25,
      rows: [
        {
          id: '9',
          number: '100009',
          created_at: '2026-10-20T10:00:00Z',
          status: { name: 'Shipped', color: '#0a7' },
          units: 3,
          total: '€45,00',
        },
      ],
    },
  });
  const table = screen.getByRole('table');
  expect(
    within(table)
      .getAllByRole('columnheader')
      .map((c) => c.textContent),
  ).toEqual(['Order', 'Date', 'Status', 'Items', 'Total']);
  const row = within(table).getByRole('row', { name: /100009/ });
  expect(within(row).getByText('Shipped')).toBeInTheDocument();
  expect(within(row).getByText('€45,00')).toBeInTheDocument();
  expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Previous/ })).toHaveAttribute(
    'href',
    '/account/orders?page=1',
  );
  expect(screen.getByRole('link', { name: /Next/ })).toHaveAttribute(
    'href',
    '/account/orders?page=3',
  );
});

it('says so when there are no orders yet', () => {
  view({
    section: 'orders',
    orders: { page: 1, last_page: 1, total: 0, rows: [] },
  });
  expect(screen.getByText('No orders yet')).toBeInTheDocument();
  expect(screen.queryByRole('table')).toBeNull();
});

it('shows delivery details on an address and saves a new one with a postal code, floor, bell name and note', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  view({
    section: 'addresses',
    version: 4,
    profile,
    defaults: { billing: '5', shipping: null },
    addresses: [address],
    countries: [
      { code: 'GR', name: 'Greece' },
      { code: 'CY', name: 'Cyprus' },
    ],
    regions: { GR: [{ code: 'ATT', name: 'Attica' }] },
  });
  const card = screen.getByText(/Ermou 1/).closest('li')!;
  expect(within(card).getByText('3rd')).toBeInTheDocument();
  expect(within(card).getByText('Ring twice')).toBeInTheDocument();
  expect(within(card).getByText('Billing')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  const dialog = screen.getByRole('dialog', { name: 'Add address' });
  const postal = within(dialog).getByLabelText(/Postal code/);
  expect(postal).toBeRequired();
  // The region is mandatory and is chosen from the country's regions, so it waits for the country.
  expect(within(dialog).getByLabelText(/Region/)).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText(/Country/), {
    target: { value: 'GR' },
  });
  const region = within(dialog).getByLabelText(/Region/);
  expect(region).toBeEnabled();
  expect(region).toBeRequired();
  fireEvent.change(region, { target: { value: 'ATT' } });
  for (const [label, value] of [
    [/^Address/, 'Kifisias'],
    [/^Number/, '10'],
    [/City/, 'Athens'],
    [/Postal code/, '11526'],
    [/^Floor/, '2'],
    [/Name on the doorbell/, 'Maria P.'],
    [/Delivery notes/, 'Leave with the neighbour'],
  ] as const)
    fireEvent.change(within(dialog).getByLabelText(label), {
      target: { value },
    });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Save address' }));
  expect(visit.mock.calls[0]![0]).toBe('/account/addresses');
  expect(visit.mock.calls[0]![1]).toMatchObject({
    method: 'post',
    data: {
      version: 4,
      line1: 'Kifisias',
      street_number: '10',
      postal_code: '11526',
      country_code: 'GR',
      region_code: 'ATT',
      floor: '2',
      bell_name: 'Maria P.',
      notes: 'Leave with the neighbour',
    },
  });
});

it('sends a review for a purchased product back to the account reviews', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  view({
    section: 'reviews',
    reviews: [],
    reviewable: [{ id: '42', name: 'Lamp' }],
  });
  fireEvent.click(screen.getByRole('button', { name: 'Write a review' }));
  fireEvent.change(screen.getByLabelText('Your review'), {
    target: { value: 'Lights the whole room.' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Submit review' }));
  expect(visit.mock.calls[0]![0]).toBe('/products/42/reviews');
  expect(visit.mock.calls[0]![1]).toMatchObject({
    method: 'post',
    data: {
      rating: 5,
      body: 'Lights the whole room.',
      return_to: '/account/reviews',
    },
  });
});

it('keeps address editing and confirmed deletion in the three-dot menu', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  view({
    section: 'addresses',
    version: 4,
    profile,
    addresses: [address],
    countries: [{ code: 'GR', name: 'Greece' }],
  });
  expect(screen.queryByRole('button', { name: 'Edit address' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
  const trigger = screen.getByRole('button', { name: 'Actions · Home' });
  fireEvent.click(trigger);
  fireEvent.click(screen.getByRole('button', { name: 'Edit address' }));
  const edit = screen.getByRole('dialog', { name: 'Edit address' });
  expect(within(edit).getByLabelText(/First name/)).toHaveValue('Maria');
  expect(within(edit).getByLabelText(/^Address/)).toHaveValue('Ermou');
  fireEvent.click(within(edit).getByRole('button', { name: 'Cancel' }));
  fireEvent.click(trigger);
  const menu = document.getElementById(trigger.getAttribute('aria-controls')!)!;
  fireEvent.click(within(menu).getByRole('button', { name: 'Delete' }));
  expect(remove).not.toHaveBeenCalled();
  const confirmation = screen.getByRole('dialog', {
    name: 'Delete this address?',
  });
  expect(within(confirmation).getByText('Ermou 1, Athens')).toBeVisible();
  fireEvent.click(within(confirmation).getByRole('button', { name: 'Delete' }));
  expect(remove).toHaveBeenCalledWith(
    '/account/addresses/5',
    expect.objectContaining({ data: { version: 4 }, preserveScroll: true }),
  );
});
