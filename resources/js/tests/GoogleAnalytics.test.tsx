import { act, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GoogleAnalytics from '../storefront/components/GoogleAnalytics';
import { emitStorefrontEvent } from '../storefront/events';

let props: Record<string, unknown> = {};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props, url: '/' }),
}));

type GaWindow = Window & {
  dataLayer?: IArguments[];
  gtag?: (...args: unknown[]) => void;
};
const w = window as GaWindow;
/** gtag calls as plain arrays (gtag queues arguments objects). */
const calls = () => (w.dataLayer ?? []).map((a) => Array.from(a));

beforeEach(() => {
  props = {
    storefrontAnalytics: { measurementId: 'G-ABC123XYZ9', ecommerce: true },
  };
});
afterEach(() => {
  document.head.querySelectorAll('script[data-ga]').forEach((s) => s.remove());
  delete w.dataLayer;
  delete w.gtag;
  document.cookie = 'cms_ga=; path=/; max-age=0';
});

it('loads gtag once and configures the property', () => {
  const { rerender } = render(<GoogleAnalytics />);
  rerender(<GoogleAnalytics />);
  const scripts = document.head.querySelectorAll('script[data-ga]');
  expect(scripts).toHaveLength(1);
  expect(scripts[0]).toHaveAttribute(
    'src',
    'https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ9',
  );
  expect(calls()[0][0]).toBe('js');
  expect(calls()[1]).toEqual(['config', 'G-ABC123XYZ9']);
});

it('remembers the IDs gtag reports so checkout can send the purchase', () => {
  render(<GoogleAnalytics />);
  const get = calls().filter((c) => c[0] === 'get');
  expect(get[0].slice(0, 3)).toEqual(['get', 'G-ABC123XYZ9', 'client_id']);
  act(() => (get[0][3] as (v: string) => void)('123456789.1700000000'));
  const session = calls().find((c) => c[2] === 'session_id')!;
  act(() => (session[3] as (v: number) => void)(1700000123));
  expect(document.cookie).toContain('cms_ga=123456789.1700000000:1700000123');
});

it('sends GA4 ecommerce events and ignores events GA4 has no name for', () => {
  render(<GoogleAnalytics />);
  act(() => {
    emitStorefrontEvent({
      type: 'add_to_cart',
      currency: 'EUR',
      items: [
        { id: '7', sku: 'SKU-7', name: 'Shirt', price: '19.90', quantity: 2 },
      ],
    });
    emitStorefrontEvent({
      type: 'add_to_comparison',
      items: [{ id: '7', quantity: 1 }],
    });
    emitStorefrontEvent({ type: 'call_request' });
  });
  expect(calls().filter((c) => c[0] === 'event')).toEqual([
    [
      'event',
      'add_to_cart',
      {
        currency: 'EUR',
        value: 39.8,
        items: [
          { item_id: 'SKU-7', item_name: 'Shirt', price: 19.9, quantity: 2 },
        ],
      },
    ],
  ]);
});

it('without ecommerce tracking sends page data only and stores no IDs', () => {
  props = {
    storefrontAnalytics: { measurementId: 'G-ABC123XYZ9', ecommerce: false },
  };
  render(<GoogleAnalytics />);
  act(() =>
    emitStorefrontEvent({
      type: 'add_to_cart',
      items: [{ id: '7', quantity: 1 }],
    }),
  );
  expect(calls().map((c) => c[0])).toEqual(['js', 'config']);
});

it('does nothing on pages without a property', () => {
  props = { storefrontAnalytics: null };
  render(<GoogleAnalytics />);
  expect(document.head.querySelector('script[data-ga]')).toBeNull();
  expect(w.dataLayer).toBeUndefined();
});
