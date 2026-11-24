import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import CartProvider from '../storefront/themes/Default/CartProvider';
import HeaderCart from '../storefront/themes/Default/MiniCart';
import type { CartPageData } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, params?: Record<string, unknown>) =>
    params ? `${key} ${JSON.stringify(params)}` : key,
}));

const cart = (lines: CartPageData['lines']): CartPageData => ({
  id: 'c0ffee00-0000-4000-8000-000000000000',
  lines,
  count: lines.length,
  subtotal: '10.00',
  coupon: null,
  promotions: [],
  gift_card: null,
  total: '10.00',
  codes: true,
  currency_format: {
    code: 'EUR',
    symbol: '€',
    position: 'before',
    thousands: ',',
    decimal: '.',
    decimals: 2,
  },
});
const speaker = {
  id: '7',
  name: 'Speaker',
  url: '/audio/speaker',
  image: null,
  price: '10.00',
  quantity: 1,
  total: '10.00',
  problem: null,
  rules: { min: 1, max: 10, step: 1 },
};
const reply = (data: CartPageData) => ({
  ok: true,
  json: async () => ({ count: data.count, cart: data }),
});
const screenIs = (phone: boolean) =>
  vi.stubGlobal('matchMedia', () => ({ matches: phone }));

afterEach(() => vi.unstubAllGlobals());

it('opens on desktop with the lines read from the server, and removes one', async () => {
  screenIs(false);
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(reply(cart([speaker])))
    .mockResolvedValueOnce(reply(cart([])));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={1}>
      <HeaderCart mini />
    </CartProvider>,
  );
  const icon = screen.getByRole('link', { name: /storefront.cart_badge/ });
  await act(async () => {
    fireEvent.click(icon);
  });
  expect(fetchMock).toHaveBeenCalledWith(
    '/cart/items',
    expect.objectContaining({ method: 'GET' }),
  );
  expect(icon).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: 'Speaker' })).toBeInTheDocument();
  await act(async () => {
    fireEvent.click(
      screen.getByRole('button', {
        name: 'storefront.cart_remove_item {"name":"Speaker"}',
      }),
    );
  });
  expect(fetchMock).toHaveBeenLastCalledWith(
    '/cart/items/7',
    expect.objectContaining({ method: 'DELETE' }),
  );
  expect(screen.getByText('storefront.cart_empty')).toBeInTheDocument();
});

it('goes to the cart page on phones and when the header switches it off', () => {
  vi.stubGlobal('fetch', vi.fn());
  screenIs(true);
  const { unmount } = render(
    <CartProvider count={1}>
      <HeaderCart mini />
    </CartProvider>,
  );
  // A click that is not prevented follows the link to /cart.
  const phone = screen.getByRole('link', { name: /storefront.cart_badge/ });
  expect(fireEvent.click(phone)).toBe(true);
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  unmount();
  screenIs(false);
  render(
    <CartProvider count={1}>
      <HeaderCart mini={false} />
    </CartProvider>,
  );
  const off = screen.getByRole('link', { name: /storefront.cart_badge/ });
  expect(off).toHaveAttribute('href', '/cart');
  expect(fireEvent.click(off)).toBe(true);
  expect(fetch).not.toHaveBeenCalled();
});

it('is only an icon with the count on the cart page', () => {
  window.history.pushState({}, '', '/cart');
  render(
    <CartProvider count={2}>
      <HeaderCart mini />
    </CartProvider>,
  );
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
  expect(
    screen.getByRole('img', { name: 'storefront.cart_badge {"count":2}' }),
  ).toBeInTheDocument();
  window.history.pushState({}, '', '/');
});

it('hides the header cart icon when selling is disabled', () => {
  const { container } = render(
    <CartProvider count={2} enabled={false}>
      <HeaderCart mini />
    </CartProvider>,
  );
  expect(container.querySelector('a[href="/cart"]')).not.toBeInTheDocument();
});
