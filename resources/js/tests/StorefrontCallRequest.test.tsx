import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DefaultTheme from '../storefront/themes/Default';
import type { StorefrontPage } from '../storefront/types';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props }),
  Head: () => null,
}));
const words = {
  'storefront.account': 'My account',
  'storefront.close': 'Close',
  'storefront.call_request_title': 'Request a call',
  'storefront.call_request_name': 'Name (optional)',
  'storefront.call_request_phone': 'Phone number',
  'storefront.call_request_order_number': 'Order number (optional)',
  'storefront.call_request_from': 'From',
  'storefront.call_request_to': 'To',
  'storefront.call_request_submit': 'Submit request',
  'storefront.call_request_sending': 'Sending…',
  'storefront.call_request_success': 'Your request has been received.',
  'storefront.call_request_error': 'Please try again.',
  'storefront.call_request_limit': 'Please wait a minute.',
};
const page = (enabled?: boolean): StorefrontPage => ({
  theme: 'default',
  cart_enabled: false,
  wishlist_enabled: false,
  regions: {
    header: [
      {
        id: 'grid',
        width: 16,
        row: 1,
        children: [],
        blocks: [
          {
            id: 'placement',
            template: 'identity',
            title: '',
            css_class: '',
            mobile: true,
            tablet: true,
            desktop: true,
            content: {
              name: 'Shop',
              call_request_form: 'saved-identity-block',
              display: {
                show_logo: false,
                show_search: false,
                show_cart: false,
                show_call_request: enabled,
              },
            },
          },
        ],
      },
    ],
  },
});
const reply = (status: number, body: unknown = {}) =>
  Promise.resolve({
    ok: status === 200,
    status,
    json: () => Promise.resolve(body),
  } as Response);
beforeEach(() => {
  shared.props = {
    app: { name: 'Shop' },
    auth: { user: null, canAccessAdmin: false },
    flash: { status: null },
    capabilities: {},
    links: {} as SharedProps['links'],
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: { ...english, ...words },
    },
  };
  document.cookie = 'XSRF-TOKEN=test%20token';
});
afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

it('defaults to no phone icon and opens an address-style dialog immediately after the account icon when enabled', () => {
  const view = render(<DefaultTheme page={page()} />);
  expect(
    screen.queryByRole('button', { name: 'Request a call' }),
  ).not.toBeInTheDocument();
  view.rerender(<DefaultTheme page={page(true)} />);
  const trigger = screen.getByRole('button', { name: 'Request a call' });
  expect(trigger.previousElementSibling?.previousElementSibling).toBe(
    screen.getByRole('button', { name: 'My account' }),
  );
  fireEvent.click(trigger);
  const dialog = screen.getByRole('dialog', { name: 'Request a call' });
  expect(within(dialog).getByLabelText('Name (optional)')).not.toBeRequired();
  expect(within(dialog).getByLabelText(/Phone number/)).toBeRequired();
  expect(
    within(dialog).getByLabelText('Order number (optional)'),
  ).not.toBeRequired();
  expect(within(dialog).getByLabelText('From')).toHaveValue('09:00');
  expect(within(dialog).getByLabelText('To')).toHaveValue('20:00');
  expect(dialog.querySelector('textarea')).toBeNull();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('keeps contact/time values after validation errors and sends only the visitor form with CSRF protection', async () => {
  const fetchMock = vi
    .fn()
    .mockImplementationOnce(() =>
      reply(422, { errors: { phone: ['Enter a valid phone.'] } }),
    )
    .mockImplementationOnce(() => reply(200));
  vi.stubGlobal('fetch', fetchMock);
  render(<DefaultTheme page={page(true)} />);
  fireEvent.click(screen.getByRole('button', { name: 'Request a call' }));
  const dialog = screen.getByRole('dialog');
  fireEvent.change(within(dialog).getByLabelText('Name (optional)'), {
    target: { value: 'Maria' },
  });
  fireEvent.change(within(dialog).getByLabelText(/Phone number/), {
    target: { value: 'wrong' },
  });
  fireEvent.change(within(dialog).getByLabelText('Order number (optional)'), {
    target: { value: '100007' },
  });
  fireEvent.change(within(dialog).getByLabelText('To'), {
    target: { value: '18:30' },
  });
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Submit request' }),
  );
  expect(await within(dialog).findByRole('alert')).toHaveTextContent(
    'Enter a valid phone.',
  );
  expect(within(dialog).getByLabelText('Name (optional)')).toHaveValue('Maria');
  expect(within(dialog).getByLabelText('To')).toHaveValue('18:30');
  expect(within(dialog).getByLabelText('Order number (optional)')).toHaveValue(
    '100007',
  );
  fireEvent.change(within(dialog).getByLabelText(/Phone number/), {
    target: { value: '2101234567' },
  });
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Submit request' }),
  );
  expect(await within(dialog).findByRole('status')).toHaveTextContent(
    'Your request has been received.',
  );
  expect(fetchMock.mock.calls[1][0]).toBe('/call-requests');
  expect(fetchMock.mock.calls[1][1].headers['X-XSRF-TOKEN']).toBe('test token');
  expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
    form: 'saved-identity-block',
    name: 'Maria',
    phone: '2101234567',
    order_number: '100007',
    call_from: '09:00',
    call_to: '18:30',
    website: '',
  });
});

it('prevents repeated submits while sending and shows a retry message when throttled', async () => {
  let complete!: (value: Response) => void;
  const fetchMock = vi.fn(
    () =>
      new Promise<Response>((resolve) => {
        complete = resolve;
      }),
  );
  vi.stubGlobal('fetch', fetchMock);
  render(<DefaultTheme page={page(true)} />);
  fireEvent.click(screen.getByRole('button', { name: 'Request a call' }));
  const dialog = screen.getByRole('dialog');
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Submit request' }),
  );
  expect(
    within(dialog).getByRole('button', { name: 'Sending…' }),
  ).toBeDisabled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Sending…' }));
  expect(fetchMock).toHaveBeenCalledTimes(1);
  complete({ ok: false, status: 429 } as Response);
  await waitFor(() =>
    expect(within(dialog).getByRole('alert')).toHaveTextContent(
      'Please wait a minute.',
    ),
  );
  expect(
    within(dialog).getByRole('button', { name: 'Submit request' }),
  ).toBeEnabled();
});

it('fills the account icon only for an authenticated session and keeps the phone stroke lighter', () => {
  const guest = page(true);
  const view = render(<DefaultTheme page={guest} />);
  const account = () =>
    screen.getByRole('button', { name: 'My account' }).querySelector('svg');
  expect(account()).toHaveAttribute('fill', 'none');
  expect(
    screen.getByRole('button', { name: 'Request a call' }).querySelector('svg'),
  ).toHaveAttribute('stroke-width', '1.6');
  const signedIn = page(true);
  signedIn.regions.header[0].blocks[0].content.account_signed_in = true;
  view.rerender(<DefaultTheme page={signedIn} />);
  expect(account()).toHaveAttribute('fill', '#fff');
  expect(account()).toHaveAttribute('stroke', '#fff');
  view.rerender(<DefaultTheme page={guest} />);
  expect(account()).toHaveAttribute('fill', 'none');
});
