import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { ClassicIdentity } from '../storefront/themes/Default';
import CompactHeader from '../storefront/themes/Default/CompactHeader';
import type { PublicBlock, PublicMenuNode } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../storefront/components/AddonPageLinks', () => ({
  default: () => null,
}));
vi.mock('../storefront/themes/Default/MiniCart', () => ({
  default: () => <a href="/cart">cart</a>,
}));
vi.mock('../storefront/themes/Default/SearchBox', () => ({
  default: () => <input aria-label="search" />,
}));
vi.mock('../storefront/themes/Default/wishlistContext', () => ({
  useWishlist: () => ({ enabled: true }),
}));
vi.mock('../storefront/themes/Default/parts', () => ({
  Collection: () => null,
  Picture: () => <img alt="" />,
}));
vi.mock('../storefront/themes/Default/Menu', () => ({
  default: () => null,
  LanguageSwitcher: ({ languages }: { languages: { code: string }[] }) => (
    <span>{languages.map((l) => l.code).join(',')}</span>
  ),
}));

const node = (
  id: string,
  name: string,
  children: PublicMenuNode[] = [],
  url: string | null = '/' + id,
): PublicMenuNode => ({
  id,
  name,
  url,
  new_window: false,
  image: null,
  children,
});
const block = (
  display: Record<string, unknown>,
  extra: Record<string, unknown> = {},
) =>
  ({
    id: 'h',
    template: 'identity',
    content: {
      name: 'Shop',
      logo: null,
      display,
      languages: [
        { code: 'el', name: 'Ελληνικά', url: '/locale/el', active: true },
        { code: 'en', name: 'English', url: '/locale/en', active: false },
      ],
      drawer: [
        node('tech', 'Technology', [
          node('phones', 'Phones', [node('iphone', 'iPhone')]),
          node('tv', 'TV'),
        ]),
        node('offers', 'Offers'),
      ],
      ...extra,
    },
  }) as unknown as PublicBlock & { children?: ReactNode };

it('puts logo, search with the menu button, language and icons on one line', () => {
  render(<CompactHeader block={block({ header_style: 'compact' })} />);
  expect(screen.getByLabelText('search')).toBeInTheDocument();
  expect(screen.getByText('el,en')).toBeInTheDocument();
  expect(
    screen.getByRole('button', { name: 'storefront.account' }),
  ).toHaveAttribute('popovertarget');
  expect(
    screen.getByRole('link', { name: 'storefront.favorites' }),
  ).toBeInTheDocument();
  expect(screen.getByText('cart')).toBeInTheDocument();
});

it('follows the same block settings for each icon', () => {
  render(
    <CompactHeader
      block={block(
        {
          show_account: false,
          show_cart: false,
          show_favorites: false,
          show_search: false,
        },
        { languages: [] },
      )}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'storefront.account' }),
  ).toBeNull();
  expect(screen.queryByText('cart')).toBeNull();
  expect(screen.queryByLabelText('search')).toBeNull();
  expect(screen.queryByText('el,en')).toBeNull();
});

it('opens the drawer with top items left and the chosen item’s sub-items right, and closes on Escape', () => {
  render(<CompactHeader block={block({})} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  const drawer = screen.getByRole('dialog');
  expect(within(drawer).getByText('Phones, TV')).toBeInTheDocument();
  expect(
    within(drawer).getByRole('heading', { name: 'Technology' }),
  ).toBeInTheDocument();
  expect(
    within(drawer).getByRole('link', { name: /storefront.view_all/ }),
  ).toHaveAttribute('href', '/tech');
  // A sub-category with its own sub-categories opens them in place, down to the last level.
  fireEvent.click(within(drawer).getByRole('button', { name: 'Phones' }));
  expect(
    within(drawer).getByRole('heading', { name: 'Phones' }),
  ).toBeInTheDocument();
  expect(within(drawer).getByRole('link', { name: 'iPhone' })).toHaveAttribute(
    'href',
    '/iphone',
  );
  expect(
    within(drawer).getByRole('link', { name: /storefront.view_all/ }),
  ).toHaveAttribute('href', '/phones');
  fireEvent.click(
    within(drawer).getByRole('button', { name: /storefront.back/ }),
  );
  expect(
    within(drawer).getByRole('heading', { name: 'Technology' }),
  ).toBeInTheDocument();
  expect(within(drawer).getByRole('link', { name: 'TV' })).toHaveAttribute(
    'href',
    '/tv',
  );
  expect(within(drawer).getByRole('link', { name: 'Offers' })).toHaveAttribute(
    'href',
    '/offers',
  );
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).toBeNull();
});

it('has no menu button without a drawer menu', () => {
  render(<CompactHeader block={block({}, { drawer: [] })} />);
  expect(screen.queryByRole('button', { name: 'storefront.menu' })).toBeNull();
});

it('shows the icons in the order chosen in the block', () => {
  const { container } = render(
    <CompactHeader
      block={block({
        icon_order: ['cart', 'favorites', 'account', 'language', 'call'],
      })}
    />,
  );
  const end = container.querySelector('.sf-chead-end')!;
  expect(
    Array.from(end.children)
      .filter((el) => !el.hasAttribute('popover'))
      .map((el) => el.textContent || el.getAttribute('aria-label')),
  ).toEqual(['cart', 'storefront.favorites', 'storefront.account', 'el,en']);
});

it('follows the original header order without a saved one', () => {
  const { container } = render(<CompactHeader block={block({})} />);
  const end = container.querySelector('.sf-chead-end')!;
  expect(
    Array.from(end.children)
      .filter((el) => !el.hasAttribute('popover'))
      .map((el) => el.textContent || el.getAttribute('aria-label')),
  ).toEqual(['el,en', 'storefront.account', 'storefront.favorites', 'cart']);
});

it('opens the shared full-screen search from the mobile trigger and restores focus', () => {
  render(<CompactHeader block={block({ header_style: 'compact' })} />);
  const trigger = screen.getByRole('button', { name: 'storefront.search' });
  fireEvent.click(trigger);
  const dialog = screen.getByRole('dialog', { name: 'storefront.search' });
  expect(dialog).toHaveAttribute('open');
  expect(within(dialog).getByLabelText('search')).toHaveFocus();
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'storefront.close' }),
  );
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(trigger).toHaveFocus();
});

it('Classic follows the saved order and shows the language picker', () => {
  const { container } = render(
    <ClassicIdentity
      block={block({
        header_style: 'classic',
        icon_order: [
          'cart',
          'favorites',
          'language',
          'account',
          'call',
          'search',
        ],
      })}
    />,
  );
  expect(
    Array.from(
      container.querySelectorAll('.sf-header-actions > :not([popover])'),
    ).map((el) => el.getAttribute('aria-label') || el.textContent),
  ).toEqual(['cart', 'storefront.favorites', 'el,en', 'storefront.account']);
});
it('Classic hides the language picker and action group when switched off', () => {
  render(
    <ClassicIdentity
      block={block({
        header_style: 'classic',
        show_language_switcher: false,
        show_account: false,
        show_favorites: false,
        show_cart: false,
        show_call_request: false,
      })}
    />,
  );
  expect(screen.queryByText('el,en')).toBeNull();
  expect(
    screen.queryByRole('navigation', { name: 'storefront.account' }),
  ).toBeNull();
});
