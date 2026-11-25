import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import MenuBlock from '../storefront/themes/Default/Menu';
import type { PublicBlock, PublicMenuNode } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../storefront/themes/Default/MiniCart', () => ({
  default: () => (
    <a href="/cart" aria-label="storefront.cart">
      Cart
    </a>
  ),
}));
vi.mock('../storefront/themes/Default/SearchBox', () => ({
  default: () => <input aria-label="Product search" />,
}));
const features = vi.hoisted(() => ({ wishlist: true, cart: true }));
vi.mock('../storefront/themes/Default/wishlistContext', () => ({
  useWishlist: () => ({ enabled: features.wishlist }),
}));
vi.mock('../storefront/themes/Default/cartContext', () => ({
  useCart: () => ({ enabled: features.cart }),
}));
vi.mock('../storefront/themes/Default/parts', () => ({
  Picture: ({ image, alt }: { image: { url: string }; alt: string }) => (
    <img src={image.url} alt={alt} />
  ),
}));
beforeEach(() => {
  features.cart = true;
  features.wishlist = true;
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: query.includes('min-width') || query.includes('hover'),
  }));
});

const node = (
  id: string,
  name: string,
  children: PublicMenuNode[] = [],
): PublicMenuNode => ({
  id,
  name,
  url: '/' + id,
  new_window: false,
  image: null,
  children,
});
const block = (template = 'identity', showLanguage = true) =>
  ({
    id: 'fashion',
    template,
    content: {
      name: 'Clothing store',
      mobile_links: {
        title: 'storefront.account',
        items: [
          { label: 'storefront.account', url: '/account', new_window: false },
          { label: 'storefront.cart', url: '/cart', new_window: false },
          {
            label: 'storefront.favorites',
            url: '/wishlist',
            new_window: false,
          },
        ],
      },
      logo: { url: '/logo.png' },
      main: [
        {
          ...node('men', 'Men', [
            node('clothing', 'Clothing', [node('jackets', 'Jackets')]),
            node('collections', 'Collections', [node('summer', 'Summer')]),
          ]),
          image: { url: '/men.jpg', width: 400, height: 600 },
        },
      ],
      languages: [
        { code: 'en', name: 'English', url: '/locale/en', active: true },
        { code: 'el', name: 'Greek', url: '/locale/el', active: false },
      ],
      display: { show_language_switcher: showLanguage, open_on_click: true },
    },
  }) as unknown as PublicBlock;

it('shows all groups together, a promotion picture and the right-side actions', () => {
  const { container } = render(<MenuBlock fashion block={block()} />);
  expect(screen.getByRole('img', { name: 'Clothing store' })).toHaveAttribute(
    'src',
    '/logo.png',
  );
  for (const action of ['search', 'account', 'favorites', 'cart'])
    expect(screen.getByLabelText(`storefront.${action}`)).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Men' }));
  expect(screen.getByRole('link', { name: 'Jackets' })).toHaveAttribute(
    'href',
    '/jackets',
  );
  expect(screen.getByRole('link', { name: 'Summer' })).toBeVisible();
  expect(screen.getByRole('img', { name: 'Men' })).toHaveAttribute(
    'src',
    '/men.jpg',
  );
  expect(container.querySelector('.sf-mega')).toHaveClass('is-full-width');
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('link', { name: 'Jackets' })).toBeNull();
});

it.each(['menu', 'identity'])(
  'can turn off the language picker in %s',
  (template) => {
    const { rerender } = render(
      <MenuBlock fashion={template === 'identity'} block={block(template)} />,
    );
    expect(
      screen.getByRole('button', { name: 'storefront.language' }),
    ).toBeVisible();
    rerender(
      <MenuBlock
        fashion={template === 'identity'}
        block={block(template, false)}
      />,
    );
    expect(
      screen.queryByRole('button', { name: 'storefront.language' }),
    ).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
    expect(screen.queryByRole('link', { name: 'Greek' })).toBeNull();
  },
);

it('opens the existing search field and restores focus on Escape', () => {
  render(<MenuBlock fashion block={block()} />);
  const button = screen.getByRole('button', { name: 'storefront.search' });
  fireEvent.click(button);
  expect(
    screen.getByRole('dialog', { name: 'storefront.search' }),
  ).toHaveAttribute('open');
  expect(document.body.style.overflow).toBe('hidden');
  expect(screen.getByRole('textbox', { name: 'Product search' })).toHaveFocus();
  fireEvent.keyDown(screen.getByRole('textbox'), { key: 'Escape' });
  expect(screen.queryByRole('textbox')).toBeNull();
  expect(button).toHaveFocus();
  expect(document.body.style.overflow).not.toBe('hidden');
});

it('keeps the hamburger drawer and its existing drill-down navigation', () => {
  render(<MenuBlock fashion block={block()} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  expect(screen.getByRole('dialog', { name: 'storefront.menu' })).toBeVisible();
  fireEvent.click(screen.getAllByRole('button', { name: 'Men' })[1]);
  fireEvent.click(screen.getByRole('button', { name: 'Clothing' }));
  expect(screen.getByRole('link', { name: 'Jackets' })).toHaveAttribute(
    'href',
    '/jackets',
  );
  fireEvent.click(screen.getByRole('button', { name: 'storefront.close' }));
  expect(screen.queryByRole('dialog')).toBeNull();
});

it('honors logo and individual action visibility while keeping the saved order', () => {
  const data = block();
  const order = ['cart', 'language', 'favorites', 'search', 'account'];
  data.content.display = {
    ...data.content.display,
    icon_order: order,
    show_logo: false,
    show_search: false,
    show_account: false,
    show_favorites: false,
    show_cart: false,
  };
  const { container, rerender } = render(<MenuBlock fashion block={data} />);
  expect(screen.queryByRole('img', { name: 'Clothing store' })).toBeNull();
  for (const action of ['search', 'account', 'favorites', 'cart'])
    expect(screen.queryByLabelText(`storefront.${action}`)).toBeNull();
  expect(
    screen.getByRole('button', { name: 'storefront.language' }),
  ).toBeVisible();
  rerender(
    <MenuBlock
      fashion
      block={{
        ...data,
        content: {
          ...data.content,
          display: {
            ...data.content.display,
            show_logo: true,
            show_search: true,
            show_account: true,
            show_favorites: true,
            show_cart: true,
          },
        },
      }}
    />,
  );
  expect(screen.getByRole('img', { name: 'Clothing store' })).toBeVisible();
  expect(
    Array.from(
      container.querySelectorAll(
        '.sf-fashion-actions [aria-label]:not([popover] *)',
      ),
    ).map((el) => el.getAttribute('aria-label')),
  ).toEqual([
    'storefront.cart',
    'storefront.language',
    'storefront.favorites',
    'storefront.search',
    'storefront.account',
  ]);
});

it('shows guest account links at the bottom of the Fashion drawer by default and restores focus', () => {
  render(<MenuBlock fashion block={block()} />);
  const burger = screen.getByRole('button', { name: 'storefront.menu' });
  burger.focus();
  fireEvent.click(burger);
  const dialog = screen.getByRole('dialog');
  expect(dialog).toHaveClass('sf-drawer-fashion');
  const section = within(dialog).getByRole('region', {
    name: 'storefront.account',
  });
  expect(
    within(section).getByRole('link', { name: 'storefront.menu_sign_in' }),
  ).toHaveAttribute('href', '/login');
  expect(
    within(section).getByRole('link', { name: 'storefront.menu_register' }),
  ).toHaveAttribute('href', '/register');
  expect(
    within(section).getByRole('link', { name: 'storefront.cart' }),
  ).toHaveAttribute('href', '/cart');
  expect(
    within(section).getByRole('link', { name: 'storefront.favorites' }),
  ).toHaveAttribute('href', '/wishlist');
  const close = within(dialog).getByRole('button', {
    name: 'storefront.close',
  });
  fireEvent.keyDown(close, { key: 'Tab', shiftKey: true });
  expect(within(dialog).getByRole('link', { name: 'Greek' })).toHaveFocus();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(burger).toHaveFocus();
});

it('can disable the Fashion drawer account section', () => {
  const data = block();
  data.content.display = {
    ...data.content.display,
    show_mobile_account_links: false,
  };
  render(<MenuBlock fashion block={data} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  expect(
    within(screen.getByRole('dialog')).queryByRole('region', {
      name: 'storefront.account',
    }),
  ).toBeNull();
});

it('shows the account destination for signed-in customers and respects disabled store features', () => {
  features.cart = false;
  features.wishlist = false;
  const data = block();
  data.content.account_signed_in = true;
  render(<MenuBlock fashion block={data} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  const section = within(screen.getByRole('dialog')).getByRole('region', {
    name: 'storefront.account',
  });
  expect(
    within(section).getByRole('link', { name: 'storefront.account' }),
  ).toHaveAttribute('href', '/account');
  for (const key of ['menu_sign_in', 'menu_register', 'cart', 'favorites'])
    expect(
      within(section).queryByRole('link', { name: `storefront.${key}` }),
    ).toBeNull();
});

it('renders the chosen links in order without restoring removed defaults', () => {
  const data = block();
  data.content.mobile_links = {
    title: 'Helpful links',
    items: [
      { label: 'Contact', url: '/contact', new_window: false },
      {
        label: 'Size guide',
        url: 'https://example.com/sizes',
        new_window: true,
      },
    ],
  };
  render(<MenuBlock fashion block={data} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  const section = within(screen.getByRole('dialog')).getByRole('region', {
    name: 'Helpful links',
  });
  expect(
    within(section)
      .getAllByRole('link')
      .map((link) => link.textContent),
  ).toEqual(['Contact', 'Size guide']);
  expect(
    within(section).getByRole('link', { name: 'Size guide' }),
  ).toHaveAttribute('rel', 'noopener noreferrer');
  expect(
    within(section).getByRole('link', { name: 'Size guide' }),
  ).toHaveAttribute('target', '_blank');
});

it('keeps a deliberately empty mobile links list empty', () => {
  const data = block();
  data.content.mobile_links = { title: 'Helpful links', items: [] };
  render(<MenuBlock fashion block={data} />);
  fireEvent.click(screen.getByRole('button', { name: 'storefront.menu' }));
  expect(within(screen.getByRole('dialog')).queryByRole('region')).toBeNull();
});

it('dismisses full-screen Fashion search using its close button', () => {
  render(<MenuBlock fashion block={block()} />);
  const trigger = screen.getByRole('button', { name: 'storefront.search' });
  fireEvent.click(trigger);
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', {
      name: 'storefront.close',
    }),
  );
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(trigger).toHaveFocus();
});
