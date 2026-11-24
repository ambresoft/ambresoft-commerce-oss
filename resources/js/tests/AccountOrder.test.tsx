import { fireEvent, render, renderHook, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { useDateFormat } from '../storefront/themes/Default/AccountParts';
import AccountOrder from '../storefront/themes/Default/AccountOrder';
import type { AccountOrderDetail } from '../storefront/types';

const preferences = vi.hoisted(() => ({ timezone: 'UTC' }));
beforeEach(() => {
  preferences.timezone = 'UTC';
});

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: { i18n: { locale: 'el' }, preferences } }),
  router: { post: vi.fn() },
}));

const order: AccountOrderDetail = {
  id: '7',
  number: '100007',
  created_at: '2026-10-21T12:13:00Z',
  status: { name: 'Completed', color: '#16a34a' },
  units: 1,
  items: [
    {
      id: '1',
      name: 'MacBook Pro',
      url: '/laptops/macbook-pro',
      sku: 'MBP',
      variation: null,
      quantity: 1,
      unit_price: '€2,799.00',
      total: '€2,799.00',
      image: null,
    },
  ],
  totals: {
    subtotal: '€2,799.00',
    discount: null,
    shipping: '€0.00',
    shipping_free: true,
    tax_included: null,
    total: '€2,799.00',
  },
  billing: null,
  shipping: { lines: ['Maria Papadopoulou', 'Athens', 'Greece'], phone: null },
  shipping_method: null,
  payment_method: null,
  note: null,
  timeline: [],
  can_cancel: false,
  invoice_requested: false,
  invoice_details: null,
};

it('links products and shows free shipping', () => {
  render(<AccountOrder order={order} />);
  expect(screen.getByRole('link', { name: 'MacBook Pro' })).toHaveAttribute(
    'href',
    '/laptops/macbook-pro',
  );
  expect(screen.getByText('account.order_free')).toBeInTheDocument();
});

it('shows the shipping and payment method once the order has them', () => {
  const { rerender } = render(<AccountOrder order={order} />);
  expect(screen.queryByText('Cash on delivery')).toBeNull();
  rerender(
    <AccountOrder
      order={{
        ...order,
        shipping_method: 'ACS Courier',
        payment_method: 'Cash on delivery',
      }}
    />,
  );
  expect(screen.getByText('ACS Courier')).toBeInTheDocument();
  expect(screen.getAllByText('Cash on delivery').length).toBeGreaterThan(0);
});

it('lets the customer cancel only when the order allows it, after confirming', () => {
  const { rerender } = render(<AccountOrder order={order} />);
  expect(
    screen.queryByRole('button', { name: 'orders.cancel_button' }),
  ).toBeNull();
  rerender(<AccountOrder order={{ ...order, can_cancel: true }} />);
  fireEvent.click(screen.getByRole('button', { name: 'orders.cancel_button' }));
  expect(router.post).not.toHaveBeenCalled();
  expect(screen.getByText('orders.cancel_confirm')).toBeInTheDocument();
  const confirm = screen.getAllByRole('button', {
    name: 'orders.cancel_button',
  });
  fireEvent.click(confirm[confirm.length - 1]);
  expect(router.post).toHaveBeenCalledWith(
    '/account/orders/7/cancel',
    {},
    expect.anything(),
  );
});

it('offers a normal download link only after the server releases the invoice', () => {
  const { rerender } = render(<AccountOrder order={order} />);
  expect(
    screen.queryByRole('link', { name: 'account.download_invoice' }),
  ).toBeNull();
  rerender(
    <AccountOrder
      order={{ ...order, invoice_url: '/account/orders/7/invoice' }}
    />,
  );
  const link = screen.getByRole('link', { name: 'account.download_invoice' });
  expect(link).toHaveAttribute('href', '/account/orders/7/invoice');
  expect(link).toHaveAttribute('download');
});

it('shows account dates in the configured store timezone, including the correct calendar day', () => {
  preferences.timezone = 'Pacific/Honolulu';
  const { result, rerender } = renderHook(() => useDateFormat());
  const options: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  };
  expect(result.current('2026-10-24T01:00:00Z', options)).toContain(
    '23/10/2026',
  );
  expect(result.current('2026-10-24T01:00:00Z', options)).toContain('15:00');
  preferences.timezone = 'Europe/Athens';
  rerender();
  expect(result.current('2026-10-24T01:00:00Z', options)).toContain(
    '24/10/2026',
  );
  expect(result.current('2026-10-24T01:00:00Z', options)).toContain('04:00');
});

it('shows saved invoice identity as escaped text and omits the section when absent', () => {
  const { rerender, container } = render(<AccountOrder order={order} />);
  expect(
    screen.queryByRole('heading', { name: 'checkout.modal_title' }),
  ).toBeNull();
  const details = {
    business_name: 'Saved Company <script>unsafe</script>',
    vat_number: '000000012',
    country_code: 'GR',
    country: 'Greece',
  };
  rerender(
    <AccountOrder
      order={{ ...order, invoice_requested: true, invoice_details: details }}
    />,
  );
  expect(
    screen.getByRole('heading', { name: 'checkout.modal_title' }),
  ).toBeInTheDocument();
  expect(screen.getByText(details.business_name)).toBeInTheDocument();
  expect(screen.getByText('000000012')).toBeInTheDocument();
  expect(screen.getByText('Greece (GR)')).toBeInTheDocument();
  expect(container.querySelector('script')).toBeNull();
  rerender(
    <AccountOrder
      order={{
        ...order,
        invoice_requested: true,
        invoice_details: { ...details, business_name: null },
      }}
    />,
  );
  expect(screen.queryByText('checkout.business_name')).toBeNull();
  expect(screen.getByText('000000012')).toBeInTheDocument();
});
