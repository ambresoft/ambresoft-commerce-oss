import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DefaultTheme from '../storefront/themes/Default';
import type { PublicBlock, StorefrontPage } from '../storefront/types';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props }),
  router: { reload },
  Head: () => null,
}));

const words: Record<string, string> = {
  'storefront.wishlist_title': 'Favourites',
  'storefront.wishlist_all': 'All favourites',
  'storefront.wishlist_private': 'Private',
  'storefront.wishlist_add_to_list': 'Add to list',
  'storefront.wishlist_new_list': 'Create a new list',
  'storefront.wishlist_name': 'List name',
  'storefront.wishlist_create': 'Create',
  'storefront.wishlist_empty': 'Nothing saved yet',
  'storefront.wishlist_in_list': 'In this list',
  'storefront.wishlist_add': 'Add to this list',
  'storefront.wishlist_saved': 'Saved to favourites',
  'storefront.wishlist_error': 'Something went wrong',
  'storefront.add_to_favourites': 'Add to favourites',
  'storefront.close': 'Close',
  'account.menu_title': 'My account',
  'account.nav_profile': 'My details',
  'account.nav_orders': 'Orders',
  'account.nav_addresses': 'Addresses',
  'account.nav_reviews': 'Reviews',
  'account.nav_password': 'Password',
};
const lists = (has: boolean[]) => [
  { id: '1', name: null, default: true, count: 2, thumb: null, has: has[0] },
  {
    id: '2',
    name: 'Black Friday',
    default: false,
    count: 1,
    thumb: '/t.jpg',
    has: has[1],
  },
];
const item = (id: string, favorite = false) => ({
  id,
  name: `Lamp ${id}`,
  price: '75.00',
  available: true,
  image: null,
  favorite,
});
const block = (
  content: PublicBlock['content'],
  template = 'product_carousel',
): PublicBlock => ({
  id: 'b',
  template,
  title: '',
  css_class: '',
  mobile: true,
  tablet: true,
  desktop: true,
  content,
});
const page = (blocks: PublicBlock[], signed_in = true): StorefrontPage => ({
  theme: 'default',
  signed_in,
  regions: { content: [{ id: 'g', width: 16, row: 1, children: [], blocks }] },
});
const reply = (body: unknown, ok = true, status = 200) =>
  Promise.resolve({
    ok,
    status,
    json: () => Promise.resolve(body),
  } as Response);

beforeEach(() => {
  reload.mockReset();
  shared.props = {
    app: { name: 'Shop' },
    auth: { user: null, canAccessAdmin: false },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: {},
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: { ...english, ...words },
    },
  } as SharedProps;
});
afterEach(() => vi.unstubAllGlobals());

it('opens the list window from a heart, toggles lists and fills the heart', async () => {
  const fetchMock = vi
    .fn()
    .mockImplementationOnce(() =>
      reply({ lists: lists([false, false]), favorite: false, created: null }),
    )
    .mockImplementationOnce(() =>
      reply({ lists: lists([true, false]), favorite: true, created: null }),
    );
  vi.stubGlobal('fetch', fetchMock);
  const { container } = render(
    <DefaultTheme page={page([block({ items: [item('7')] })])} />,
  );
  const heart = container.querySelector('.sf-card-heart')!;
  expect(heart).not.toHaveClass('is-saved');
  fireEvent.click(heart);
  const dialog = await screen.findByRole('dialog', { name: 'Add to list' });
  expect(fetchMock.mock.calls[0][0]).toBe('/wishlists/product/7');
  const rows = await within(dialog).findAllByRole('button', { pressed: false });
  expect(within(dialog).getByText('All favourites')).toBeVisible();
  expect(within(dialog).getByText('Black Friday')).toBeVisible();
  fireEvent.click(rows.find((r) => r.textContent?.includes('All favourites'))!);
  await waitFor(() =>
    expect(container.querySelector('.sf-card-heart')).toHaveClass('is-saved'),
  );
  expect(fetchMock.mock.calls[1][0]).toBe('/wishlists/1/products/7');
  expect(fetchMock.mock.calls[1][1].method).toBe('PUT');
  expect(
    within(dialog).getByRole('button', { name: /All favourites/ }),
  ).toHaveAttribute('aria-pressed', 'true');
});

it('creates a named list with the product from the window and shows a refused name', async () => {
  const fetchMock = vi
    .fn()
    .mockImplementationOnce(() =>
      reply({ lists: lists([false, false]), favorite: false, created: null }),
    )
    .mockImplementationOnce(() =>
      reply(
        {
          message: 'x',
          errors: { name: ['You already have a list with this name.'] },
        },
        false,
        422,
      ),
    )
    .mockImplementationOnce(() =>
      reply({ lists: lists([true, true]), favorite: true, created: '2' }),
    );
  vi.stubGlobal('fetch', fetchMock);
  const { container } = render(
    <DefaultTheme page={page([block({ items: [item('7')] })])} />,
  );
  fireEvent.click(container.querySelector('.sf-card-heart')!);
  const dialog = await screen.findByRole('dialog', { name: 'Add to list' });
  fireEvent.click(
    await within(dialog).findByRole('button', { name: /Create a new list/ }),
  );
  fireEvent.change(within(dialog).getByPlaceholderText('List name'), {
    target: { value: 'Black Friday' },
  });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
  expect(await within(dialog).findByRole('alert')).toHaveTextContent(
    'already have a list',
  );
  fireEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
  await waitFor(() =>
    expect(container.querySelector('.sf-card-heart')).toHaveClass('is-saved'),
  );
  expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({
    name: 'Black Friday',
    product_id: '7',
  });
});

it('sends guests from a heart to sign in without opening the window', () => {
  const fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
  const location = { href: '/' };
  vi.stubGlobal('location', location);
  const { container } = render(
    <DefaultTheme page={page([block({ items: [item('7')] })], false)} />,
  );
  fireEvent.click(container.querySelector('.sf-card-heart')!);
  expect(location.href).toBe('/login');
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(fetchMock).not.toHaveBeenCalled();
});

it('shows the wishlist as a grid with the configured cards per row and the lists as tabs', () => {
  const content = {
    per_row: 4,
    wishlist: {
      lists: lists([false, false]).map((l) => ({ ...l })),
      current: '1',
      items: [item('1', true), item('2', true)],
      currency_format: {
        code: 'EUR',
        symbol: '€',
        position: 'before' as const,
        thousands: ',',
        decimal: '.',
        decimals: 2,
      },
      display: { show_price: true, show_favorites: true },
    },
  };
  const { container } = render(
    <DefaultTheme page={page([block(content, 'main_content')])} />,
  );
  expect(
    screen.getByRole('heading', { level: 2, name: 'Favourites' }),
  ).toBeVisible();
  // The favourites sit inside the account, beside its menu.
  const menu = screen.getByRole('navigation', { name: 'My account' });
  expect(
    within(menu).getByRole('link', { name: 'Favourites' }),
  ).toHaveAttribute('aria-current', 'page');
  expect(within(menu).getByRole('link', { name: 'Orders' })).toHaveAttribute(
    'href',
    '/account/orders',
  );
  const grid = screen.getByTestId('wishlist-grid');
  expect(grid.style.getPropertyValue('--sf-wl-cols')).toBe('4');
  expect(grid.querySelectorAll('.sf-product')).toHaveLength(2);
  expect(grid.querySelectorAll('.sf-card-heart.is-saved')).toHaveLength(2);
  const tabs = container.querySelectorAll('.sf-wl-tabs a');
  expect([...tabs].map((a) => a.textContent)).toEqual([
    'All favourites (2)',
    'Black Friday (1)',
  ]);
  expect(tabs[1]).toHaveAttribute('href', '/wishlist?list=2');
  expect(tabs[0]).toHaveAttribute('aria-current', 'page');
});

it('shows an empty list with a hint and renames or deletes only named lists', () => {
  const base = {
    per_row: 5,
    wishlist: {
      lists: lists([false, false]),
      current: '2',
      items: [],
      currency_format: {
        code: 'EUR',
        symbol: '€',
        position: 'before' as const,
        thousands: ',',
        decimal: '.',
        decimals: 2,
      },
      display: {},
    },
  };
  const { container, unmount } = render(
    <DefaultTheme page={page([block(base, 'main_content')])} />,
  );
  expect(screen.getByText('Nothing saved yet')).toBeVisible();
  expect(container.querySelectorAll('.sf-wl-actions button')).toHaveLength(2);
  unmount();
  const general = render(
    <DefaultTheme
      page={page([
        block(
          { ...base, wishlist: { ...base.wishlist, current: '1' } },
          'main_content',
        ),
      ])}
    />,
  );
  expect(general.container.querySelector('.sf-wl-actions')).toBeNull();
});

it('shows no hearts and no header heart when wishlists are switched off', () => {
  const { container } = render(
    <DefaultTheme
      page={{
        ...page([block({ items: [item('7')] })]),
        wishlist_enabled: false,
      }}
    />,
  );
  expect(container.querySelector('.sf-card-heart')).toBeNull();
  const identity = render(
    <DefaultTheme
      page={{
        theme: 'default',
        wishlist_enabled: false,
        regions: {
          header: [
            {
              id: 'h',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                block(
                  {
                    name: 'Shop',
                    display: { show_favorites: true, show_cart: true },
                  },
                  'identity',
                ),
              ],
            },
          ],
        },
      }}
    />,
  );
  expect(identity.container.querySelector('a[href="/wishlist"]')).toBeNull();
  const on = render(
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          header: [
            {
              id: 'h',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                block(
                  { name: 'Shop', display: { show_favorites: true } },
                  'identity',
                ),
              ],
            },
          ],
        },
      }}
    />,
  );
  expect(on.container.querySelector('a[href="/wishlist"]')).not.toBeNull();
});
