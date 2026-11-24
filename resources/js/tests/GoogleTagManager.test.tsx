import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GoogleTagManager from '../storefront/components/GoogleTagManager';
import CartProvider from '../storefront/themes/Default/CartProvider';
import { useCart } from '../storefront/themes/Default/cartContext';
import { emitStorefrontEvent } from '../storefront/events';

let props: Record<string, unknown> = {};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props, url: '/' }),
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

type Layer = Record<string, unknown>[];
const layer = (name = 'dataLayer') =>
  (window as unknown as Record<string, Layer | undefined>)[name];
const config = {
  container: 'GTM-ABC1234',
  dataLayer: 'shopLayer',
  events: { add_to_cart: 'gtm-add-to-cart', call_request: 'gtm-call-request' },
};

beforeEach(() => {
  props = { storefrontTagManager: config };
});
afterEach(() => {
  document.head.querySelectorAll('script[data-gtm]').forEach((s) => s.remove());
  delete (window as unknown as Record<string, unknown>).shopLayer;
  vi.unstubAllGlobals();
});

it('loads the container once from Google with the custom data layer name', () => {
  const { rerender } = render(<GoogleTagManager />);
  rerender(<GoogleTagManager />);
  const scripts = document.head.querySelectorAll('script[data-gtm]');
  expect(scripts).toHaveLength(1);
  expect(scripts[0]).toHaveAttribute(
    'src',
    'https://www.googletagmanager.com/gtm.js?id=GTM-ABC1234&l=shopLayer',
  );
  expect(layer('shopLayer')?.[0]).toMatchObject({ event: 'gtm.js' });
});

it('pushes only the enabled events under the merchant names, clearing ecommerce first', () => {
  render(<GoogleTagManager />);
  act(() => {
    emitStorefrontEvent({
      type: 'add_to_cart',
      currency: 'EUR',
      items: [
        { id: '7', sku: 'SKU-7', name: 'Shirt', price: '19.90', quantity: 2 },
      ],
    });
    emitStorefrontEvent({
      type: 'add_to_wishlist',
      items: [{ id: '7', quantity: 1 }],
    });
    emitStorefrontEvent({ type: 'call_request' });
  });
  expect(layer('shopLayer')?.slice(1)).toEqual([
    { ecommerce: null },
    {
      event: 'gtm-add-to-cart',
      ecommerce: {
        currency: 'EUR',
        value: 39.8,
        items: [
          { item_id: 'SKU-7', item_name: 'Shirt', price: 19.9, quantity: 2 },
        ],
      },
    },
    { event: 'gtm-call-request' },
  ]);
});

it('does nothing on pages without a container', () => {
  props = { storefrontTagManager: null };
  render(<GoogleTagManager />);
  emitStorefrontEvent({ type: 'call_request' });
  expect(document.head.querySelector('script[data-gtm]')).toBeNull();
  expect(layer('shopLayer')).toBeUndefined();
});

it('announces a successful cart addition with the line the server returned', async () => {
  const cart = {
    lines: [
      { id: '7', name: 'Shirt', sku: 'SKU-7', price: '19.90', quantity: 3 },
    ],
    currency_format: { code: 'EUR' },
  };
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify({ cart, count: 3 }))),
  );
  const seen: unknown[] = [];
  const listen = (e: Event) => seen.push((e as CustomEvent).detail);
  window.addEventListener('cms:storefront-event', listen);
  function Add() {
    const api = useCart();
    return <button onClick={() => void api.add({ id: '7' }, 2)}>add</button>;
  }
  render(
    <CartProvider count={1}>
      <Add />
    </CartProvider>,
  );
  await act(async () => fireEvent.click(screen.getByText('add')));
  window.removeEventListener('cms:storefront-event', listen);
  expect(seen).toEqual([
    {
      type: 'add_to_cart',
      currency: 'EUR',
      items: [
        { id: '7', name: 'Shirt', sku: 'SKU-7', price: '19.90', quantity: 2 },
      ],
    },
  ]);
});

it('marks later in-app visits once the container runs, so private pages load fresh', () => {
  render(<GoogleTagManager />);
  const visit = { url: '/account', headers: {} as Record<string, string> };
  document.dispatchEvent(
    new CustomEvent('inertia:before', { detail: { visit }, cancelable: true }),
  );
  expect(visit.headers['X-Cms-Tags']).toBe('1');
});
