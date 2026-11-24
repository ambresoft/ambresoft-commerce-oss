import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import HeaderAccountMenu from '../storefront/themes/Default/HeaderAccountMenu';
import { ReviewsEnabledContext } from '../storefront/themes/Default/AccountParts';
import { WishlistContext } from '../storefront/themes/Default/wishlistContext';

const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  router: { post },
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

it('offers existing account destinations and posts sign out', () => {
  render(
    <HeaderAccountMenu signedIn>
      <span>User</span>
    </HeaderAccountMenu>,
  );
  expect(
    screen.getByRole('link', { name: 'account.nav_orders', hidden: true }),
  ).toHaveAttribute('href', '/account/orders');
  expect(
    screen.getByRole('link', { name: 'account.nav_addresses', hidden: true }),
  ).toHaveAttribute('href', '/account/addresses');
  expect(
    screen.getByRole('link', {
      name: 'storefront.wishlist_title',
      hidden: true,
    }),
  ).toHaveAttribute('href', '/wishlist');
  fireEvent.click(
    screen.getByRole('button', { name: 'navigation.sign_out', hidden: true }),
  );
  expect(post).toHaveBeenCalledWith('/logout');
  const trigger = screen.getByRole('button', { name: 'storefront.account' });
  expect(trigger).toHaveAttribute(
    'popovertarget',
    trigger.getAttribute('aria-controls'),
  );
  expect(
    document.getElementById(trigger.getAttribute('aria-controls')!),
  ).toHaveAttribute('popover', 'auto');
});

it('shows customer authentication links for guests', () => {
  render(
    <HeaderAccountMenu signedIn={false}>
      <span>User</span>
    </HeaderAccountMenu>,
  );
  expect(
    screen.getByRole('link', { name: 'auth.sign_in', hidden: true }),
  ).toHaveAttribute('href', '/login');
  expect(
    screen.getByRole('link', { name: 'auth.create_link', hidden: true }),
  ).toHaveAttribute('href', '/register');
  expect(
    screen.queryByRole('link', { name: 'account.nav_orders', hidden: true }),
  ).toBeNull();
});

it('omits favorites when wishlists are disabled', () => {
  render(
    <WishlistContext.Provider
      value={{
        enabled: false,
        isFavourite: () => false,
        open: () => undefined,
      }}
    >
      <HeaderAccountMenu signedIn>
        <span>User</span>
      </HeaderAccountMenu>
    </WishlistContext.Provider>,
  );
  expect(
    screen.queryByRole('link', {
      name: 'storefront.wishlist_title',
      hidden: true,
    }),
  ).toBeNull();
});

it('leaves out the reviews part while reviews are off for the store', () => {
  const { rerender } = render(
    <HeaderAccountMenu signedIn>
      <span>User</span>
    </HeaderAccountMenu>,
  );
  expect(
    screen.getByRole('link', { name: 'account.nav_reviews', hidden: true }),
  ).toHaveAttribute('href', '/account/reviews');
  rerender(
    <ReviewsEnabledContext.Provider value={false}>
      <HeaderAccountMenu signedIn>
        <span>User</span>
      </HeaderAccountMenu>
    </ReviewsEnabledContext.Provider>,
  );
  expect(
    screen.queryByRole('link', { name: 'account.nav_reviews', hidden: true }),
  ).toBeNull();
});
