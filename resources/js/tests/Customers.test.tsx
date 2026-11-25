import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import CustomersIndex from '../pages/Admin/Customers/Index';
import CustomerForm from '../pages/Admin/Customers/Form';
import CustomerShow from '../pages/Admin/Customers/Show';
import CustomerGroupForm from '../pages/Admin/CustomerGroups/Form';
import type { CustomerDetail } from '../types/customers';
import type { SharedProps } from '../types/page';
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
// Readable labels: each key renders as its last segment unless overridden.
const keys = [
  'title',
  'count',
  'create',
  'edit',
  'search_placeholder',
  'empty',
  'no_matches',
  'sort_created',
  'sort_name',
  'sort_email',
  'active',
  'disabled',
  'group',
  'groups',
  'created',
  'updated',
  'email',
  'email_hint',
  'phone',
  'first_name',
  'last_name',
  'none',
  'yes',
  'no',
  'section_profile',
  'section_account',
  'section_addresses',
  'no_groups',
  'groups_none',
  'tax_exempt',
  'tax_exempt_hint',
  'notes',
  'notes_hint',
  'disable',
  'restore',
  'disable_confirm',
  'restore_confirm',
  'disabled_notice',
  'address',
  'address_add',
  'address_edit',
  'address_save',
  'address_delete',
  'address_delete_confirm',
  'addresses_empty',
  'address_label',
  'address_label_hint',
  'company',
  'line1',
  'line2',
  'city',
  'postal_code',
  'postal_code_hint',
  'country',
  'country_choose',
  'region',
  'region_hint',
  'address_now',
  'address_now_hint',
  'default_billing',
  'default_shipping',
  'make_default_billing',
  'make_default_shipping',
  'groups_title',
  'group_create',
  'group_edit',
  'group_description',
  'group_default',
  'group_default_hint',
  'group_status_hint',
  'group_delete',
  'group_delete_confirm',
  'group_delete_move_confirm',
  'group_default_delete',
  'group_reassign',
  'group_reassign_choose',
  'members',
  'blacklisted',
  'blacklist_filter',
  'blacklist_yes',
  'blacklist_no',
  'blacklist_banner',
  'blacklist_reason',
  'blacklist_reason_hint',
  'blacklist_add_customer',
  'blacklist_save',
  'blacklist_email',
  'blacklist_phone',
  'blacklist_release',
  'blacklist_kind',
];
const labels: Record<string, string> = {
  ...Object.fromEntries(keys.map((k) => ['customers.' + k, k])),
  'customers.count': ':count customers',
  'customers.disable_confirm': 'Disable :name?',
  'customers.blacklist_release_confirm': 'Remove :value?',
  'customers.group_reassign': 'Move :count customers to',
  'customers.group_delete_move_confirm': 'Move :count to :target?',
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
const countries = [
  { code: 'GR', name: 'Greece' },
  { code: 'CY', name: 'Cyprus' },
];
const maria: CustomerDetail = {
  id: '7',
  version: 3,
  first_name: 'Maria',
  last_name: 'Papadopoulou',
  email: 'maria@example.test',
  phone: '+30 210 1234567',
  status: 'active',
  account: { state: 'none', since: null, email_verified: false, active: true },
  tax_exempt: false,
  notes: 'VIP caller',
  group_ids: ['1'],
  groups: ['Retail'],
  addresses: [
    {
      id: '10',
      label: 'Home',
      first_name: 'Maria',
      last_name: 'Papadopoulou',
      company: null,
      line1: 'Ermou 1',
      line2: null,
      city: 'Athens',
      postal_code: '10563',
      country_code: 'GR',
      country: 'Greece',
      region_code: 'I',
      phone: null,
    },
    {
      id: '11',
      label: 'Office',
      first_name: 'Maria',
      last_name: 'Papadopoulou',
      company: 'ACME',
      line1: 'Makariou 5',
      line2: null,
      city: 'Nicosia',
      postal_code: null,
      country_code: 'CY',
      country: 'Cyprus',
      region_code: null,
      phone: null,
    },
  ],
  blacklist: [],
  default_billing_address_id: '10',
  default_shipping_address_id: '10',
  created_at: '2026-10-12T09:00:00+00:00',
  updated_at: '2026-10-12T10:00:00+00:00',
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
      'customers.view': true,
      'customers.create': true,
      'customers.update': true,
      'customers.status': true,
      'customer-groups.view': true,
      'customer-groups.manage': true,
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
  } as SharedProps;
});

it('lists customers with groups and applies the group filter', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  render(
    <CustomersIndex
      {...shell}
      customers={{
        data: [
          {
            id: '7',
            name: 'Maria Papadopoulou',
            email: 'maria@example.test',
            phone: null,
            status: 'disabled',
            tax_exempt: false,
            blacklisted: true,
            groups: ['Retail', 'VIP'],
            created_at: '2026-10-12T09:00:00+00:00',
          },
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
      filters={{}}
      groups={[{ id: '2', name: 'VIP' }]}
    />,
  );
  const row = screen
    .getByRole('link', { name: 'Maria Papadopoulou' })
    .closest('tr')!;
  expect(within(row).getByText('Retail, VIP')).toBeVisible();
  expect(within(row).getByText('disabled')).toBeVisible();
  expect(within(row).getByText('blacklisted')).toBeVisible();
  expect(within(row).getByText('—')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: /^group:/ }));
  fireEvent.click(screen.getByRole('button', { name: 'VIP' }));
  expect(get.mock.lastCall?.[1]).toMatchObject({ group: '2' });
});

it('creates a customer in the default group with an optional first address', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CustomerForm
      {...shell}
      customer={null}
      countries={countries}
      groups={[
        { id: '1', name: 'Retail', active: true, default: true },
        { id: '2', name: 'VIP', active: true, default: false },
      ]}
    />,
  );
  expect(screen.getByRole('checkbox', { name: /Retail/ })).toBeChecked();
  fireEvent.change(screen.getByLabelText('first_name'), {
    target: { value: 'Nikos' },
  });
  fireEvent.change(screen.getByLabelText('last_name'), {
    target: { value: 'Georgiou' },
  });
  fireEvent.change(screen.getByLabelText('email'), {
    target: { value: 'nikos@example.test' },
  });
  fireEvent.click(screen.getByRole('checkbox', { name: /VIP/ }));
  fireEvent.click(screen.getByLabelText(/address_now/));
  fireEvent.change(screen.getByLabelText('line1'), {
    target: { value: 'Egnatia 1' },
  });
  fireEvent.change(screen.getByLabelText('city'), {
    target: { value: 'Thessaloniki' },
  });
  fireEvent.click(screen.getByRole('combobox', { name: /country/ }));
  fireEvent.change(screen.getByRole('combobox', { name: 'Search options' }), {
    target: { value: 'gr' },
  });
  expect(screen.getAllByRole('option')).toHaveLength(1);
  fireEvent.click(screen.getByRole('option', { name: 'Greece' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/customers');
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({
    first_name: 'Nikos',
    email: 'nikos@example.test',
    group_ids: ['1', '2'],
    tax_exempt: false,
    address: {
      first_name: 'Nikos',
      last_name: 'Georgiou',
      line1: 'Egnatia 1',
      city: 'Thessaloniki',
      country_code: 'GR',
    },
  });
  expect(data).not.toHaveProperty('with_address');
});

it('edits the profile, tax exemption and default addresses with the version', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <CustomerForm
      {...shell}
      customer={maria}
      countries={countries}
      groups={[{ id: '1', name: 'Retail', active: true, default: true }]}
    />,
  );
  fireEvent.click(screen.getByLabelText(/tax_exempt/));
  choose(
    screen.getByRole('combobox', { name: 'default_shipping' }),
    /Makariou 5/,
  );
  expect(screen.queryByLabelText(/address_now/)).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/customers/7');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'put',
    data: {
      version: 3,
      tax_exempt: true,
      default_billing_address_id: '10',
      default_shipping_address_id: '11',
      group_ids: ['1'],
    },
  });
});

it('shows separated sections and confirms before disabling the customer', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  expect(
    screen.getByRole('heading', { name: 'section_addresses' }),
  ).toBeVisible();
  const home = screen.getByRole('listitem', { name: 'Home' });
  expect(within(home).getByText('default_billing')).toBeVisible();
  expect(within(home).getByText('default_shipping')).toBeVisible();
  expect(screen.getByText('VIP caller')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'disable' }));
  expect(confirm).toHaveBeenCalledWith('Disable Maria Papadopoulou?');
  expect(request.mock.calls[0][0]).toBe('/admin/customers/7/status');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'patch',
    data: { version: 3, status: 'disabled' },
  });
});

it('adds, defaults and deletes addresses for the same customer only', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <CustomerShow
      {...shell}
      customer={maria}
      countries={countries}
      regions={{ GR: [{ code: 'I', name: 'Attica' }] }}
    />,
  );
  const office = screen.getByRole('listitem', { name: 'Office' });
  fireEvent.click(
    within(office).getByRole('button', { name: 'Actions: Office' }),
  );
  fireEvent.click(
    within(office).getByRole('button', { name: 'make_default_shipping' }),
  );
  expect(request.mock.calls[0][0]).toBe('/admin/customers/7');
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    version: 3,
    default_shipping_address_id: '11',
    email: 'maria@example.test',
  });
  fireEvent.click(
    within(office).getByRole('button', { name: 'Actions: Office' }),
  );
  fireEvent.click(
    within(office).getByRole('button', { name: 'address_delete' }),
  );
  expect(request.mock.calls[1][0]).toBe('/admin/customers/7/addresses/11');
  expect(request.mock.calls[1][1]).toMatchObject({
    method: 'delete',
    data: { version: 3 },
  });

  fireEvent.click(screen.getByRole('button', { name: 'address_add' }));
  expect(screen.getByRole('dialog', { name: 'address_add' })).toBeVisible();
  // The recipient defaults to the customer's name.
  expect(screen.getByLabelText('first_name')).toHaveValue('Maria');
  fireEvent.change(screen.getByLabelText('line1'), {
    target: { value: 'Mitropoleos 2' },
  });
  fireEvent.change(screen.getByLabelText('city'), {
    target: { value: 'Athens' },
  });
  // The region waits for a country; its options are that country's Geography regions.
  expect(screen.getByRole('combobox', { name: /^region/ })).toBeDisabled();
  choose(screen.getByRole('combobox', { name: /country/ }), 'Greece');
  choose(screen.getByRole('combobox', { name: /^region/ }), 'Attica');
  fireEvent.click(screen.getByRole('button', { name: 'address_save' }));
  expect(request.mock.calls[2][0]).toBe('/admin/customers/7/addresses');
  expect(request.mock.calls[2][1]?.data).toMatchObject({
    version: 3,
    line1: 'Mitropoleos 2',
    country_code: 'GR',
    region_code: 'I',
  });
});

it('hides changes from view-only staff', () => {
  shared.props.capabilities = { 'customers.view': true };
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  expect(screen.queryByRole('button', { name: 'disable' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'address_add' })).toBeNull();
  expect(screen.queryByRole('link', { name: 'Edit' })).toBeNull();
});

it('deletes a group with members only after choosing where they move', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <CustomerGroupForm
      {...shell}
      defaultLocale="en"
      group={{
        id: '4',
        version: 2,
        status: 'active',
        is_default: false,
        names: { en: 'Old' },
        descriptions: {},
        members: 3,
      }}
      others={[{ id: '5', name: 'Target', active: true, default: false }]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  const remove = screen.getByRole('button', { name: 'group_delete' });
  expect(remove).toBeDisabled();
  fireEvent.pointerDown(
    screen.getByRole('combobox', { name: 'Move 3 customers to' }),
  );
  choose(
    screen.getByRole('combobox', { name: 'Move 3 customers to' }),
    'Target',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'group_delete' }));
  expect(request.mock.calls[0][0]).toBe('/admin/customer-groups/4');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'delete',
    data: { version: 2, reassign_to: '5' },
  });
});

it('shows why a customer is blacklisted and can blacklist or release entries', () => {
  shared.props.capabilities = {
    ...shared.props.capabilities,
    'customer-blacklist.manage': true,
  };
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <CustomerShow
      {...shell}
      countries={countries}
      customer={{
        ...maria,
        blacklist: [
          {
            id: '9',
            kind: 'email',
            value: 'maria@example.test',
            reason: 'Chargeback fraud',
            created_at: '2026-10-12T09:00:00+00:00',
            actor: 'Admin',
            own: true,
            scope: 'full',
            payment_methods: [],
          },
        ],
      }}
    />,
  );
  const banner = screen.getByRole('region', { name: 'blacklist_banner' });
  expect(within(banner).getByText('Chargeback fraud')).toBeVisible();
  fireEvent.click(
    within(banner).getByRole('button', { name: 'blacklist_release' }),
  );
  expect(request.mock.calls[0][0]).toBe('/admin/customer-blacklist/9');
  expect(request.mock.calls[0][1]).toMatchObject({ method: 'delete' });

  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(
    screen.getByRole('button', { name: 'blacklist_add_customer' }),
  );
  fireEvent.click(screen.getByLabelText(/blacklist_phone/));
  fireEvent.change(screen.getByLabelText('blacklist_reason'), {
    target: { value: 'Abusive returns' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'blacklist_save' }));
  expect(request.mock.calls[1][0]).toBe('/admin/customers/7/blacklist');
  expect(request.mock.calls[1][1]?.data).toEqual({
    kinds: ['email', 'phone'],
    reason: 'Abusive returns',
    scope: 'full',
    payment_method_ids: [],
  });
});

it('edits a saved address in the drawer with its version and address identity', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  const office = screen.getByRole('listitem', { name: 'Office' });
  fireEvent.click(within(office).getByRole('button', { name: 'Edit' }));
  const drawer = screen.getByRole('dialog', { name: 'address_edit' });
  expect(within(drawer).getByLabelText('line1')).toHaveValue(
    maria.addresses[1].line1,
  );
  fireEvent.change(within(drawer).getByLabelText('city'), {
    target: { value: 'Piraeus' },
  });
  fireEvent.click(within(drawer).getByRole('button', { name: 'address_save' }));
  expect(request.mock.lastCall?.[0]).toBe('/admin/customers/7/addresses/11');
  expect(request.mock.lastCall?.[1]).toMatchObject({
    method: 'put',
    data: { version: 3, city: 'Piraeus' },
  });
});

it('discards a cancelled address draft and returns focus to the add button', () => {
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  const add = screen.getByRole('button', { name: 'address_add' });
  add.focus();
  fireEvent.click(add);
  const drawer = screen.getByRole('dialog', { name: 'address_add' });
  expect(within(drawer).getByLabelText('address_label')).toHaveFocus();
  fireEvent.change(within(drawer).getByLabelText('line1'), {
    target: { value: 'Discard this street' },
  });
  fireEvent(drawer, new Event('cancel', { cancelable: true }));
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(add).toHaveFocus();
  fireEvent.click(add);
  expect(
    within(screen.getByRole('dialog')).getByLabelText('line1'),
  ).toHaveValue('');
});

it('keeps the address draft and drawer open when the server returns a field error', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  fireEvent.click(screen.getByRole('button', { name: 'address_add' }));
  fireEvent.change(screen.getByLabelText('line1'), {
    target: { value: 'Saved draft street' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'address_save' }));
  const onError = request.mock.lastCall?.[1]?.onError;
  act(() => {
    onError?.({ line1: 'Check the street address.' });
  });
  const drawer = screen.getByRole('dialog', { name: 'address_add' });
  expect(within(drawer).getByLabelText('line1')).toHaveValue(
    'Saved draft street',
  );
  expect(within(drawer).getByText('Check the street address.')).toBeVisible();
});

it('requires confirmation before emailing a customer account invitation and lets staff cancel', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<CustomerShow {...shell} customer={maria} countries={countries} />);
  fireEvent.click(
    screen.getByRole('button', { name: 'Send account invitation' }),
  );
  const dialog = screen.getByRole('dialog');
  expect(dialog).toHaveTextContent('maria@example.test');
  expect(dialog).toHaveTextContent('does not grant staff or admin access');
  expect(post).not.toHaveBeenCalled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  fireEvent.click(
    screen.getByRole('button', { name: 'Send account invitation' }),
  );
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', {
      name: 'Send account invitation',
    }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/customers/7/invitation',
    {},
    expect.anything(),
  );
});
