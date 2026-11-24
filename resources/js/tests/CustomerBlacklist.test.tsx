import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import CustomerBlacklistIndex from '../pages/Admin/CustomerBlacklist/Index';
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
  'blacklist_title',
  'blacklist_description',
  'blacklist_add',
  'blacklist_add_entry',
  'blacklist_kind',
  'blacklist_email',
  'blacklist_phone',
  'blacklist_value',
  'blacklist_reason',
  'blacklist_customer',
  'blacklist_when',
  'blacklist_state',
  'blacklist_state_active',
  'blacklist_state_released',
  'blacklist_release',
  'blacklist_search',
  'blacklist_close',
  'blacklist_drawer_subtitle',
  'blacklist_value_email_hint',
  'blacklist_value_phone_hint',
  'blacklist_reason_placeholder',
  'blacklist_required',
  'blacklist_staff_only',
  'blacklist_none',
  'blacklist_empty',
  'blacklist_no_matches',
  'blacklist_scope',
  'blacklist_scope_full',
  'blacklist_scope_full_help',
  'blacklist_scope_payment',
  'blacklist_scope_payment_help',
  'blacklist_methods',
  'blacklist_disabled_methods',
  'blacklist_scope_filter',
];
const paymentMethods = [
  { id: '3', name: 'Cash on delivery' },
  { id: '4', name: 'Card' },
];
const labels: Record<string, string> = {
  ...Object.fromEntries(keys.map((k) => ['customers.' + k, k])),
  'customers.blacklist_release_confirm': 'Remove :value?',
  'common.cancel': 'Cancel',
  'common.all': 'All',
  'common.actions': 'Actions',
};
const shell = { navigation: [], breadcrumbs: [] };
const entries = {
  data: [
    {
      id: '1',
      kind: 'email' as const,
      value: 'giannis.k@example.test',
      reason: 'Chargeback fraud',
      created_at: '2026-10-12T09:00:00+00:00',
      released: false,
      actor: 'Admin',
      customer: { id: '7', name: 'Giannis Karras' },
      scope: 'full' as const,
      payment_methods: [],
    },
    {
      id: '2',
      kind: 'phone' as const,
      value: '306900000142',
      reason: 'Fake orders',
      created_at: '2026-10-12T09:00:00+00:00',
      released: false,
      actor: null,
      customer: null,
      scope: 'payment_methods' as const,
      payment_methods: ['Cash on delivery'],
    },
  ],
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
  total: 2,
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
      'customer-blacklist.view': true,
      'customer-blacklist.manage': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
});

it('lists entries with type, reason and customer initials and removes one', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <CustomerBlacklistIndex
      {...shell}
      entries={entries}
      filters={{}}
      paymentMethods={paymentMethods}
    />,
  );
  const row = screen.getByText('giannis.k@example.test').closest('tr')!;
  expect(within(row).getByText('blacklist_email')).toBeVisible();
  expect(within(row).getByText('GK')).toBeVisible();
  expect(within(row).getByText('Chargeback fraud')).toBeVisible();
  fireEvent.click(
    within(row).getByRole('button', {
      name: /blacklist_release/,
    }),
  );
  expect(request.mock.calls[0][0]).toBe('/admin/customer-blacklist/1');
});

it('adds an entry from the drawer with a type, value and mandatory reason', async () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CustomerBlacklistIndex
      {...shell}
      entries={entries}
      filters={{}}
      paymentMethods={paymentMethods}
    />,
  );
  expect(screen.queryByRole('dialog')).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'blacklist_add' }));
  const drawer = screen.getByRole('dialog');
  expect(within(drawer).getByText('blacklist_value_email_hint')).toBeVisible();
  fireEvent.click(within(drawer).getByLabelText('blacklist_phone'));
  expect(within(drawer).getByText('blacklist_value_phone_hint')).toBeVisible();
  fireEvent.change(within(drawer).getByLabelText('blacklist_value'), {
    target: { value: '+30 690 000 0999' },
  });
  fireEvent.change(within(drawer).getByLabelText('blacklist_reason'), {
    target: { value: 'Bot orders' },
  });
  fireEvent.click(
    within(drawer).getByRole('button', { name: 'blacklist_add_entry' }),
  );
  expect(request.mock.calls[0][0]).toBe('/admin/customer-blacklist');
  expect(request.mock.calls[0][1]?.data).toEqual({
    kind: 'phone',
    value: '+30 690 000 0999',
    reason: 'Bot orders',
    scope: 'full',
    payment_method_ids: [],
  });
  // Escape closes without saving, after the slide-out.
  fireEvent.keyDown(document, { key: 'Escape' });
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
});

it('hides the add and remove actions from view-only staff', () => {
  shared.props.capabilities = { 'customer-blacklist.view': true };
  render(
    <CustomerBlacklistIndex
      {...shell}
      entries={entries}
      filters={{}}
      paymentMethods={paymentMethods}
    />,
  );
  expect(screen.queryByRole('button', { name: 'blacklist_add' })).toBeNull();
  expect(
    screen.queryByRole('button', { name: /blacklist_release/ }),
  ).toBeNull();
});

it('disables only chosen payment methods for an email or phone instead of blocking it', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CustomerBlacklistIndex
      {...shell}
      entries={entries}
      filters={{}}
      paymentMethods={paymentMethods}
    />,
  );
  // The existing restriction shows which methods it takes away.
  const row = screen.getByText('306900000142').closest('tr')!;
  expect(within(row).getByText(/Cash on delivery/)).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'blacklist_add' }));
  const drawer = screen.getByRole('dialog');
  expect(within(drawer).queryByText('Cash on delivery')).toBeNull();
  fireEvent.click(within(drawer).getByLabelText(/blacklist_scope_payment/));
  fireEvent.click(within(drawer).getByLabelText('Cash on delivery'));
  fireEvent.change(within(drawer).getByLabelText('blacklist_value'), {
    target: { value: 'buyer@example.test' },
  });
  fireEvent.change(within(drawer).getByLabelText('blacklist_reason'), {
    target: { value: 'Refuses cash on delivery parcels' },
  });
  fireEvent.click(
    within(drawer).getByRole('button', { name: 'blacklist_add_entry' }),
  );
  expect(request.mock.calls[0][1]?.data).toEqual({
    kind: 'email',
    value: 'buyer@example.test',
    reason: 'Refuses cash on delivery parcels',
    scope: 'payment_methods',
    payment_method_ids: ['3'],
  });
});
