import { createContext, useContext } from 'react';
import type { PublicItem } from '../../types';

export type WishlistApi = {
  /** Wishlists are switched on for the store (General settings → Catalog). */
  enabled: boolean;
  /** Whether the product is saved (the card's own state until the visitor changes it). */
  isFavourite: (item: PublicItem) => boolean;
  /** Opens the "add to list" window (or sends a guest to sign in). */
  open: (item: PublicItem) => void;
};

/** Hearts on product cards talk to the wishlist window through this context (see WishlistProvider). */
export const WishlistContext = createContext<WishlistApi>({
  enabled: true,
  isFavourite: (item) => !!item.favorite,
  open: () => undefined,
});

export const useWishlist = () => useContext(WishlistContext);
