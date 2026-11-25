import { createContext, useContext } from 'react';
import type { CartPageData } from '../../types';

export type CartReply =
  | { ok: true; cart: CartPageData; count: number }
  | { ok: false; message: string };

export type CartApi = {
  /** The store's cart is on; off, nothing offers to add to it. */
  enabled: boolean;
  /** Units in the cart (the header badge). */
  count: number;
  /** Puts a quantity of a product in the cart and says so; resolves to whether it worked. */
  add: (
    item: { id: string; name?: string; addPackage?: boolean },
    quantity?: number,
  ) => Promise<boolean>;
  /** Changes a line's quantity (PATCH) or removes it (DELETE); resolves to the cart as it now stands. */
  change: (
    method: 'PATCH' | 'DELETE',
    product: string,
    quantity?: number,
  ) => Promise<CartReply>;
  /** Removes every line; resolves to the emptied cart. */
  clear: () => Promise<CartReply>;
  /** The cart as it stands (the header's mini cart). */
  peek: () => Promise<CartReply>;
  /** Enters a coupon or gift card code (POST) or removes the coupon or gift card (DELETE with its kind). */
  code: (method: 'POST' | 'DELETE', value: string) => Promise<CartReply>;
};

/** Cart buttons, the header badge and the cart page share the cart through this context (see CartProvider). */
export const CartContext = createContext<CartApi>({
  enabled: true,
  count: 0,
  add: async () => false,
  change: async () => ({ ok: false, message: '' }),
  clear: async () => ({ ok: false, message: '' }),
  peek: async () => ({ ok: false, message: '' }),
  code: async () => ({ ok: false, message: '' }),
});

export const useCart = () => useContext(CartContext);
