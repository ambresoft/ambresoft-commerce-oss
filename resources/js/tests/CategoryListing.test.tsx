import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { CardStyleContext } from '../storefront/themes/Default/parts';
import CategoryListing, {
  ProductFilters,
} from '../storefront/themes/Default/CategoryListing';
import {
  listingHref,
  toggleValue,
} from '../storefront/themes/Default/listingUrl';
import type {
  ListingData,
  PublicBlock,
  PublicSticker,
} from '../storefront/types';

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({
  // Visits finish at once, as Inertia's do once the answer arrives.
  router: {
    get: (href: string, data: object, options: { onFinish?: () => void }) => {
      visit(href, data, options);
      options.onFinish?.();
    },
    on: () => () => {},
  },
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string;
    children: ReactNode;
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};
const category = {
  id: '4',
  name: 'Smartphones',
  description: '',
  url: '/tech/smartphones',
  path: [{ name: 'Smartphones', url: '/tech/smartphones' }],
  items: [],
};
const block = (content: Partial<PublicBlock['content']>): PublicBlock =>
  ({
    id: 'b',
    template: 'main_content',
    content,
  }) as unknown as PublicBlock;

beforeEach(() => {
  visit.mockClear();
  window.history.pushState({}, '', '/tech/smartphones?brand=apple&page=2');
});

it('builds one sorted, readable address per state and drops the page on a filter change', () => {
  expect(
    listingHref('/c?sort=name&page=3', (q) => toggleValue(q, 'brand', 'b')),
  ).toBe('/c?brand=b&sort=name');
  expect(listingHref('/c?brand=a,b', (q) => toggleValue(q, 'brand', 'a'))).toBe(
    '/c?brand=b',
  );
  expect(
    listingHref('/c?rating=4', (q) => toggleValue(q, 'rating', '3', true)),
  ).toBe('/c?rating=3');
  expect(listingHref('/c?page=2', (q) => q.set('page', '3'), true)).toBe(
    '/c?page=3',
  );
});

it('adds a checked value to the address and keeps the filters already chosen', () => {
  render(
    <ProductFilters
      block={block({
        filters: {
          total: 3,
          active: [],
          currency_format: format,
          visible_options: 8,
          groups: [
            {
              key: 'brand',
              kind: 'choice',
              name: 'Brand',
              param: 'brand',
              options: [
                { value: 'apple', label: 'Apple', count: 2, selected: true },
                {
                  value: 'samsung',
                  label: 'Samsung',
                  count: 1,
                  selected: false,
                },
                { value: 'nokia', label: 'Nokia', count: 0, selected: false },
              ],
            },
          ],
        },
      })}
    />,
  );
  expect(screen.getByRole('checkbox', { name: /Apple/ })).toBeChecked();
  // A value without products cannot be chosen.
  expect(screen.getByRole('checkbox', { name: /Nokia/ })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: /Samsung/ }));
  expect(visit).toHaveBeenCalledWith(
    '/tech/smartphones?brand=apple,samsung',
    {},
    expect.objectContaining({ preserveState: true }),
  );
});

it('lays the products out with the block setting and removes one chip at a time', () => {
  const listing: ListingData = {
    category,
    items: [{ id: '7', name: 'Pixel', url: '/p', price: '10.00' }],
    currency_format: format,
    display: {},
    total: 1,
    page: 1,
    last_page: 1,
    sort: 'newest',
    default_sort: 'newest',
    sorts: ['newest', 'name'],
    per_row: 4,
    active: [
      {
        kind: 'choice',
        param: 'brand',
        name: 'Brand',
        value: 'apple',
        label: 'Apple',
      },
    ],
    comparison: { max: 4, ids: ['7'] },
  };
  const { container } = render(<CategoryListing block={block({ listing })} />);
  expect(
    (
      container.querySelector('.sf-listing-grid') as HTMLElement
    ).style.getPropertyValue('--sf-lp-cols'),
  ).toBe('4');
  expect(
    screen.getByRole('button', { name: 'storefront.comparison_remove' }),
  ).toHaveAttribute('aria-pressed', 'true');
  fireEvent.click(
    screen.getByRole('button', { name: 'storefront.filter_remove' }),
  );
  expect(visit).toHaveBeenCalledWith(
    '/tech/smartphones',
    {},
    expect.anything(),
  );
});

it('sorts from the menu, opens the filters from the listing and shows the optional card details', () => {
  const listing: ListingData = {
    category,
    items: [
      {
        id: '7',
        name: 'Pixel',
        url: '/p',
        price: '10.00',
        category: { name: 'Smartphones', url: '/tech/smartphones' },
        brand_link: { name: 'Google', url: '/google' },
        variants: [
          { id: '7', name: 'Pixel black', url: '/p', image: null },
          { id: '8', name: 'Pixel white', url: '/p-white', image: null },
        ],
      },
    ],
    currency_format: format,
    display: { show_brand: true, show_category: true, show_variations: true },
    total: 1,
    page: 1,
    last_page: 1,
    sort: 'recommended',
    default_sort: 'recommended',
    sorts: ['recommended', 'price_asc', 'newest'],
    active: [],
  };
  render(
    <>
      <ProductFilters
        block={block({
          filters: {
            total: 1,
            active: [],
            currency_format: format,
            visible_options: 8,
            groups: [],
          },
        })}
      />
      <CategoryListing block={block({ listing })} />
    </>,
  );
  expect(screen.getByRole('link', { name: 'Smartphones' })).toHaveAttribute(
    'href',
    '/tech/smartphones',
  );
  expect(screen.getByRole('link', { name: 'Google' })).toHaveAttribute(
    'href',
    '/google',
  );
  expect(screen.getByRole('link', { name: 'Pixel white' })).toHaveAttribute(
    'href',
    '/p-white',
  );

  // The sort menu lists the current order first, then the others.
  fireEvent.click(
    screen.getByRole('button', {
      name: 'storefront.sort_by: storefront.sort_recommended',
    }),
  );
  fireEvent.click(
    screen.getByRole('button', { name: 'storefront.sort_price_asc' }),
  );
  expect(visit).toHaveBeenCalledWith(
    '/tech/smartphones?brand=apple&sort=price_asc',
    {},
    expect.anything(),
  );

  // The listing's filters button opens the filters block's panel.
  const open = screen.getByRole('button', { name: /storefront.filters_open/ });
  fireEvent.click(open);
  expect(screen.getByRole('dialog')).toHaveClass('is-open');
  fireEvent.click(screen.getByRole('button', { name: 'storefront.close' }));
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(open).toHaveFocus();
});

it('applies a range when a slider handle is released and drops a bound moved back to its end', () => {
  window.history.pushState({}, '', '/c');
  render(
    <ProductFilters
      block={block({
        filters: {
          total: 5,
          active: [],
          currency_format: format,
          visible_options: 8,
          groups: [
            {
              key: 'f15',
              kind: 'range',
              name: 'Screen',
              param: 'f15',
              money: false,
              unit: 'in',
              min: '13.4',
              max: '17',
              from: null,
              to: null,
            },
          ],
        },
      })}
    />,
  );
  const [low, high] = screen.getAllByRole('slider');
  fireEvent.change(low, { target: { value: '150' } });
  // Dragging alone loads nothing.
  expect(visit).not.toHaveBeenCalled();
  expect(
    screen.getByRole('textbox', { name: 'Screen: storefront.range_from' }),
  ).toHaveValue('15.0');
  fireEvent.pointerUp(low);
  expect(visit).toHaveBeenCalledWith('/c?f15_min=15.0', {}, expect.anything());
  visit.mockClear();
  fireEvent.change(high, { target: { value: '170' } });
  fireEvent.pointerUp(high);
  // The maximum at the range's end is no bound, and the minimum is unchanged: the same address again.
  expect(visit).toHaveBeenCalledWith('/c?f15_min=15.0', {}, expect.anything());
});

it('lets several star ratings be chosen together', () => {
  window.history.pushState({}, '', '/c?rating=5');
  render(
    <ProductFilters
      block={block({
        filters: {
          total: 3,
          active: [],
          currency_format: format,
          visible_options: 8,
          groups: [
            {
              key: 'rating',
              kind: 'rating',
              name: 'Rating',
              param: 'rating',
              options: [
                {
                  value: '5',
                  label: '5 stars',
                  count: 1,
                  selected: true,
                  stars: 5,
                },
                {
                  value: '1',
                  label: '1 stars',
                  count: 2,
                  selected: false,
                  stars: 1,
                },
              ],
            },
          ],
        },
      })}
    />,
  );
  fireEvent.click(screen.getByRole('checkbox', { name: /1 stars/ }));
  expect(visit).toHaveBeenCalledWith('/c?rating=5,1', {}, expect.anything());
});

it('shows a brand page banner above the products, inside the listing column', () => {
  const listing: ListingData = {
    brand: {
      id: '1',
      source: 'brand',
      name: 'Apple',
      url: '/apple',
      description: '',
      image: null,
      image_alt: '',
      banner: { url: '/banner.png', width: 1200, height: 300 },
      banner_alt: 'Summer offers',
      banner_link: null,
    },
    items: [{ id: '7', name: 'Pixel', url: '/p', price: '10.00' }],
    currency_format: format,
    display: {},
    total: 1,
    page: 1,
    last_page: 1,
    sort: 'newest',
    default_sort: 'newest',
    sorts: ['newest'],
    per_row: 3,
    active: [],
  };
  const { container } = render(<CategoryListing block={block({ listing })} />);
  const banner = screen.getByRole('img', { name: 'Summer offers' });
  const bar = container.querySelector('.sf-listing-bar')!;
  expect(container.querySelector('.sf-listing')).toContainElement(banner);
  expect(
    banner.compareDocumentPosition(bar) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});

it.each(['classic', 'showcase', 'signature'] as const)(
  'uses the mobile row setting with the existing %s card and cart control',
  (style) => {
    const listing: ListingData = {
      category,
      items: [{ id: '7', name: 'Phone', price: '99.00', available: true }],
      per_row: 4,
      mobile_per_row: 1,
      currency_format: format,
      display: {},
    };
    const page = (data: ListingData) => (
      <CardStyleContext.Provider value={style}>
        <CategoryListing block={block({ listing: data })} />
      </CardStyleContext.Provider>
    );
    const { container, rerender } = render(page(listing));
    const grid = container.querySelector('.sf-listing-grid') as HTMLElement;
    expect(grid).toHaveAttribute('data-mobile-columns', '1');
    expect(grid.style.getPropertyValue('--sf-lp-cols')).toBe('4');
    expect(grid.style.getPropertyValue('--sf-lp-mobile-cols')).toBe('1');
    expect(grid.querySelector('.sf-product-details')).not.toBeNull();
    expect(
      grid.querySelector(
        style === 'classic'
          ? '.sf-cart'
          : style === 'showcase'
            ? '.sf-showcase-cart'
            : '.sf-signature-cart',
      ),
    ).not.toBeNull();
    rerender(page({ ...listing, mobile_per_row: 2 }));
    expect(grid).toHaveAttribute('data-mobile-columns', '2');
    rerender(page({ ...listing, mobile_per_row: undefined }));
    expect(grid).toHaveAttribute('data-mobile-columns', '2');
  },
);

it('defaults tablet listings to two columns and keeps all device settings independent', () => {
  const listing: ListingData = {
    category,
    items: [{ id: '7', name: 'Phone', price: '99.00' }],
    currency_format: format,
    per_row: 5,
    mobile_per_row: 1,
  };
  const { container, rerender } = render(
    <CategoryListing block={block({ listing })} />,
  );
  const grid = container.querySelector('.sf-listing-grid') as HTMLElement;
  expect(grid.style.getPropertyValue('--sf-lp-tablet-cols')).toBe('2');
  rerender(
    <CategoryListing
      block={block({ listing: { ...listing, tablet_per_row: 3 } })}
    />,
  );
  expect(grid.style.getPropertyValue('--sf-lp-tablet-cols')).toBe('3');
  expect(grid.style.getPropertyValue('--sf-lp-cols')).toBe('5');
  expect(grid.style.getPropertyValue('--sf-lp-mobile-cols')).toBe('1');
});

it.each(['classic', 'showcase', 'signature'] as const)(
  'places mobile image stickers above the title on one-column %s cards',
  (style) => {
    const sticker: PublicSticker = {
      id: '1',
      style: 'text',
      text: 'BLACK FRIDAY',
      alt: '',
      tooltip: '',
      placement: 'image_top',
      visibility: { mobile: true, tablet: true, desktop: true },
      image: null,
      background: '#000000',
      color: '#ffffff',
      border_color: '#000000',
      border_width: 0,
      radius: 4,
      padding: 4,
      font_size: 12,
      alignment: 'left',
      css_class: '',
    };
    const listing: ListingData = {
      category,
      items: [
        {
          id: '7',
          name: 'Phone',
          price: '99.00',
          stickers: [
            sticker,
            {
              ...sticker,
              id: '2',
              text: 'Desktop only',
              visibility: { mobile: false, tablet: true, desktop: true },
            },
            {
              ...sticker,
              id: '3',
              text: 'Bottom badge',
              placement: 'image_bottom',
            },
          ],
        },
      ],
      currency_format: format,
      mobile_per_row: 1,
    };
    const page = (data: ListingData) => (
      <CardStyleContext.Provider value={style}>
        <CategoryListing block={block({ listing: data })} />
      </CardStyleContext.Provider>
    );
    const { container, rerender } = render(page(listing));
    const badges = container.querySelector(
      '.sf-product-details .sf-card-mobile-stickers',
    )!;
    expect(badges).toHaveTextContent('BLACK FRIDAY');
    expect(badges).toHaveTextContent('Bottom badge');
    expect(badges).not.toHaveTextContent('Desktop only');
    expect(
      badges.compareDocumentPosition(
        container.querySelector('.sf-product h3')!,
      ) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    rerender(page({ ...listing, mobile_per_row: 2 }));
    expect(container.querySelector('.sf-card-mobile-stickers')).toBeNull();
    expect(
      container.querySelector('.sf-media-wrap .sf-stickers-image_top'),
    ).toHaveTextContent('BLACK FRIDAY');
  },
);
