import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import OrderPaymentCard from '../components/admin/OrderPaymentCard';
import Form from '../pages/Admin/GiftCards/Form';
import Index from '../pages/Admin/GiftCards/Index';
import type { GiftCardDetail, GiftCardRow } from '../types/giftCards';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/gift-cards/1/edit' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const card: GiftCardDetail = {
  id: '1',
  version: 3,
  code: 'GC-ABCD-EFGH-JKLM',
  recipient_name: 'Nikos',
  sender_name: 'Test User',
  recipient_email: 'nikos@example.test',
  message: 'Enjoy',
  locale: 'en',
  status: 'active',
  notify: false,
  amount: '50.00',
  currency_code: 'EUR',
  balance: '€20.00',
  amount_locked: true,
  deleted_at: null,
  created_at: '2026-10-17T16:20:00+00:00',
};
const emptyPage = {
  data: [],
  total: 0,
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
};
const formProps = {
  ...shell,
  giftCard: card,
  currency: { code: 'EUR', decimals: 2 },
  locales: [
    { code: 'en', name: 'English' },
    { code: 'el', name: 'Ελληνικά' },
  ],
  defaultLocale: 'en',
  history: emptyPage,
  emails: [],
};
const row: GiftCardRow = {
  id: '1',
  version: 3,
  code: 'GC-ABCD-EFGH-JKLM',
  sender: 'Test User',
  recipient: 'Nikos',
  email: 'nikos@example.test',
  created_at: '2026-10-17T16:20:00+00:00',
  status: 'active',
  balance: '€20.00',
  amount: '€50.00',
  partly_used: true,
  notify: true,
};
const strings = {
  ...english,
  'features.delete': 'Permanently delete',
  'giftcards.title': 'Gift cards',
  'giftcards.create': 'Create gift card',
  'giftcards.general': 'General',
  'giftcards.history': 'History',
  'giftcards.status': 'Status',
  'giftcards.status_pending': 'Pending',
  'giftcards.status_active': 'Active',
  'giftcards.status_cancelled': 'Cancelled',
  'giftcards.status_used': 'Used',
  'giftcards.recipient_name': 'To',
  'giftcards.sender_name': 'From',
  'giftcards.recipient_email': 'Email',
  'giftcards.amount': 'Amount',
  'giftcards.amount_locked': 'Part of this card has been used',
  'giftcards.notify': 'Notify customer',
  'giftcards.send_email': 'Send email',
  'giftcards.delete': 'Delete gift card',
  'giftcards.delete_title': 'Delete :count gift card(s)?',
  'giftcards.delete_fact_balance': 'Balance left on the card:',
  'giftcards.delete_subtitle': 'They can no longer be used.',
  'giftcards.set_status': 'Change status',
  'giftcards.row_actions': 'Actions for :code',
  'giftcards.of_amount': 'of :amount',
  'giftcards.select_all': 'Select all gift cards',
  'giftcards.empty': 'No gift cards found.',
  'giftcards.balance': 'Current amount',
  'giftcards.code': 'Code',
  'payments.gift_card_title': 'Pay with a gift card',
  'payments.gift_card_code': 'Gift card code',
  'payments.gift_card_apply': 'Apply gift card',
  'payments.kind_gift_card': 'Gift card',
};
beforeEach(() => {
  vi.restoreAllMocks();
  window.history.replaceState({}, '', '/admin/gift-cards/1/edit');
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
      'giftcards.view': true,
      'giftcards.manage': true,
      'giftcards.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings,
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
  vi.spyOn(router, 'replace').mockImplementation(() => undefined);
});

it('shows the saved notify choice when editing a card', () => {
  const { unmount } = render(<Form {...formProps} />);
  expect(
    screen.getByRole('checkbox', { name: 'Notify customer' }),
  ).not.toBeChecked();
  unmount();
  render(<Form {...formProps} giftCard={{ ...card, notify: true }} />);
  expect(
    screen.getByRole('checkbox', { name: 'Notify customer' }),
  ).toBeChecked();
});

it('creates a card that is emailed by default and sends the versioned fields', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<Form {...formProps} giftCard={null} history={null} />);
  expect(
    screen.getByRole('checkbox', { name: 'Notify customer' }),
  ).toBeChecked();
  expect(screen.queryByRole('button', { name: 'Send email' })).toBeNull();
  fireEvent.change(screen.getByLabelText(/^To/), {
    target: { value: 'Maria' },
  });
  fireEvent.change(screen.getByLabelText(/^From/), {
    target: { value: 'Ann' },
  });
  fireEvent.change(screen.getByLabelText(/^Email/), {
    target: { value: 'maria@example.test' },
  });
  fireEvent.change(screen.getByLabelText(/^Amount/), {
    target: { value: '75' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/gift-cards',
    expect.objectContaining({
      recipient_name: 'Maria',
      sender_name: 'Ann',
      recipient_email: 'maria@example.test',
      amount: '75',
      status: 'active',
      notify: true,
      locale: 'en',
    }),
    expect.any(Object),
  );
});

it('locks the amount once some of the card was used and asks before deleting', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  const confirm = vi.spyOn(window, 'confirm');
  render(<Form {...formProps} />);
  expect(screen.getByLabelText(/^Amount/)).toBeDisabled();
  expect(screen.getByText('Part of this card has been used')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Delete gift card' }));
  const dialog = screen.getByRole('alertdialog');
  expect(within(dialog).getByText('€20.00')).toBeVisible();
  expect(remove).not.toHaveBeenCalled();
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Permanently delete' }),
  );
  expect(confirm).not.toHaveBeenCalled();
  expect(remove).toHaveBeenCalledWith('/admin/gift-cards/1', {
    data: { version: 3 },
  });
});

it('offers the email only for an active card and posts the request', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  const { unmount } = render(<Form {...formProps} />);
  fireEvent.click(screen.getByRole('button', { name: 'Send email' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/gift-cards/1/send',
    {},
    expect.any(Object),
  );
  unmount();
  render(<Form {...formProps} giftCard={{ ...card, status: 'cancelled' }} />);
  expect(screen.queryByRole('button', { name: 'Send email' })).toBeNull();
});

it('shows a used card read-only and hides mutations from view-only staff', () => {
  shared.props.capabilities = { 'giftcards.view': true };
  render(<Form {...formProps} giftCard={{ ...card, status: 'used' }} />);
  expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete gift card' })).toBeNull();
  expect(screen.queryByRole('combobox', { name: 'Status' })).toBeNull();
  expect(screen.getAllByText('Used').length).toBeGreaterThan(0);
  fireEvent.click(screen.getByRole('tab', { name: 'History' }));
  expect(screen.getByRole('tab', { name: 'History' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
});

const list = (data: GiftCardRow[]) => (
  <Index
    {...shell}
    filters={{ sort: 'created_at', direction: 'desc' }}
    giftCards={{ ...emptyPage, data, total: data.length }}
  />
);

it('lists cards with balance, remaining of the face value and a coloured status', () => {
  render(
    list([
      row,
      {
        ...row,
        id: '2',
        code: 'GC-QQQQ-WWWW-EEEE',
        status: 'cancelled',
        partly_used: false,
      },
    ]),
  );
  expect(
    screen.getByRole('link', { name: 'GC-ABCD-EFGH-JKLM' }),
  ).toHaveAttribute('href', '/admin/gift-cards/1/edit');
  expect(screen.getByText('of €50.00')).toBeVisible();
  expect(screen.getAllByText('Active').length).toBeGreaterThan(0);
  expect(screen.getAllByText('Cancelled').length).toBeGreaterThan(0);
  expect(screen.getByText('GC-ABCD-EFGH-JKLM').closest('table')).toHaveClass(
    'data-table',
  );
});

it('changes a status from the row menu, honouring the notify choice', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
  render(list([row]));
  fireEvent.click(
    screen.getByRole('button', { name: 'Actions for GC-ABCD-EFGH-JKLM' }),
  );
  expect(screen.getByRole('menuitemradio', { name: /Active/ })).toBeDisabled();
  // The box shows what is saved on the card, and a change is saved straight away.
  const box = screen.getByRole('checkbox', { name: 'Notify customer' });
  expect(box).toBeChecked();
  fireEvent.click(box);
  expect(patch).toHaveBeenCalledWith(
    '/admin/gift-cards/1/status',
    { version: 3, status: 'active', notify: false },
    expect.any(Object),
  );
  fireEvent.click(screen.getByRole('menuitemradio', { name: 'Cancelled' }));
  expect(patch).toHaveBeenLastCalledWith(
    '/admin/gift-cards/1/status',
    { version: 3, status: 'cancelled', notify: false },
    expect.any(Object),
  );
});

it('does not offer status changes for a used card', () => {
  render(list([{ ...row, status: 'used' }]));
  fireEvent.click(
    screen.getByRole('button', { name: 'Actions for GC-ABCD-EFGH-JKLM' }),
  );
  expect(screen.queryByRole('menuitemradio')).toBeNull();
  expect(screen.getByRole('menuitem', { name: 'Edit' })).toBeVisible();
});

it('confirms bulk deletion in a dialog and sends every selected version', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  const confirm = vi.spyOn(window, 'confirm');
  render(
    list([row, { ...row, id: '2', version: 7, code: 'GC-QQQQ-WWWW-EEEE' }]),
  );
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'Select all gift cards' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Delete (2)' }));
  const dialog = screen.getByRole('alertdialog');
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Permanently delete' }),
  );
  expect(confirm).not.toHaveBeenCalled();
  expect(remove).toHaveBeenCalledWith(
    '/admin/gift-cards/bulk',
    expect.objectContaining({
      data: {
        rows: [
          { id: '1', version: 3 },
          { id: '2', version: 7 },
        ],
      },
    }),
  );
});

it('hides management actions from view-only staff', () => {
  shared.props.capabilities = { 'giftcards.view': true };
  render(list([row]));
  expect(screen.queryByRole('link', { name: 'Create gift card' })).toBeNull();
  expect(
    screen.queryByRole('checkbox', { name: 'Select all gift cards' }),
  ).toBeNull();
  fireEvent.click(
    screen.getByRole('button', { name: 'Actions for GC-ABCD-EFGH-JKLM' }),
  );
  expect(screen.queryByRole('menuitem', { name: 'Delete' })).toBeNull();
  expect(screen.queryByRole('menuitemradio')).toBeNull();
});

const payment = {
  method: null,
  status: 'unpaid' as const,
  paid: '€0.00',
  outstanding: '€100.00',
  outstanding_raw: '100.00',
  decimals: 2,
  records: [
    {
      id: '9',
      kind: 'gift_card' as const,
      state: 'captured' as const,
      amount: '€30.00',
      method: 'Gift card ending JKLM',
      provider_reference: null,
      capture_reference: null,
      detail: null,
      note: null,
      recorded_by: 'Staff',
      created_at: '2026-10-17T16:20:00+00:00',
      completed_at: '2026-10-17T16:20:00+00:00',
      can_refresh: false,
    },
  ],
  link: null,
  can_record: true,
  can_gift_card: true,
};

it('applies a gift card code on the order page and labels its payment', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<OrderPaymentCard orderId="6" payment={payment} />);
  expect(screen.getByText(/Gift card · Gift card ending JKLM/)).toBeVisible();
  const apply = screen.getByRole('button', { name: 'Apply gift card' });
  expect(apply).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Gift card code'), {
    target: { value: 'GC-ABCD-EFGH-JKLM' },
  });
  fireEvent.click(apply);
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/6/gift-card',
    expect.anything(),
    expect.any(Object),
  );
});

it('does not offer gift cards to staff without access', () => {
  render(
    <OrderPaymentCard
      orderId="6"
      payment={{ ...payment, can_gift_card: false }}
    />,
  );
  expect(screen.queryByLabelText('Gift card code')).toBeNull();
});
