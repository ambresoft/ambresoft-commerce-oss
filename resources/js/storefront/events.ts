import type { CartLine } from './types';

export type StorefrontEventItem = {
  id: string;
  name?: string;
  sku?: string | null;
  /** The unit price as the storefront shows it (a decimal string). */
  price?: string | null;
  quantity: number;
};

/**
 * A shopper action, announced on window as `cms:storefront-event` for tag add-ons (A147). It never carries personal
 * data: no names, contact details or order numbers.
 */
export type StorefrontEvent = {
  type:
    | 'add_to_cart'
    | 'remove_from_cart'
    | 'add_to_wishlist'
    | 'add_to_comparison'
    | 'call_request';
  currency?: string;
  items?: StorefrontEventItem[];
};

export const STOREFRONT_EVENT = 'cms:storefront-event';

export function emitStorefrontEvent(event: StorefrontEvent) {
  window.dispatchEvent(new CustomEvent(STOREFRONT_EVENT, { detail: event }));
}

export const cartItem = (
  line: CartLine,
  quantity = line.quantity,
): StorefrontEventItem => ({
  id: line.id,
  name: line.name,
  sku: line.sku,
  price: line.price,
  quantity,
});

/** GA4-style ecommerce data; prices are analytics values here, never amounts the store charges. */
export function ga4Ecommerce(event: StorefrontEvent) {
  if (!event.items?.length) return undefined;
  const cents = (value?: string | null) =>
    value ? Math.round(Number(value) * 100) : 0;
  const priced = event.items.every((i) => i.price);
  return {
    currency: event.currency,
    // Without a price for every item a total would be wrong, so none is sent.
    value: priced
      ? event.items.reduce((sum, i) => sum + cents(i.price) * i.quantity, 0) /
        100
      : undefined,
    items: event.items.map((i) => ({
      item_id: i.sku || i.id,
      item_name: i.name,
      price: i.price ? cents(i.price) / 100 : undefined,
      quantity: i.quantity,
    })),
  };
}
