import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Cart from '../storefront/themes/Default/Cart';
import CartProvider from '../storefront/themes/Default/CartProvider';
import { CardStyleContext, Product } from '../storefront/themes/Default/parts';
import type { CartPageData, PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, params?: Record<string, unknown>) =>
    params ? `${key} ${JSON.stringify(params)}` : key,
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};

const cart = (quantity: number, total: string): CartPageData => ({
  id: 'c0ffee00-0000-4000-8000-000000000000',
  lines: [
    {
      id: '7',
      name: 'Speaker',
      url: '/audio/speaker',
      image: null,
      price: '10.00',
      available: true,
      quantity,
      total,
      problem: null,
      rules: { min: 1, max: 10, step: 1 },
    },
  ],
  count: quantity,
  subtotal: total,
  coupon: null,
  promotions: [],
  gift_card: null,
  total,
  codes: true,
  currency_format: format,
});

const block = (data: CartPageData): PublicBlock =>
  ({
    id: 'b',
    type: 'main_content',
    template: 'main_content',
    content: { cart: data },
  }) as unknown as PublicBlock;

const reply = (data: CartPageData) => ({
  ok: true,
  json: async () => ({ count: data.count, cart: data }),
});

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

it('waits for the shopper to stop clicking before it sends the quantity once', async () => {
  const fetchMock = vi.fn().mockResolvedValue(reply(cart(3, '30.00')));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={1}>
      <Cart block={block(cart(1, '10.00'))} />
    </CartProvider>,
  );
  const more = screen.getByRole('button', {
    name: 'storefront.quantity_increase',
  });
  fireEvent.click(more);
  fireEvent.click(more);
  // The chosen quantity shows at once; nothing is sent yet.
  expect(screen.getByText('3')).toBeInTheDocument();
  expect(fetchMock).not.toHaveBeenCalled();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500);
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  expect(fetchMock).toHaveBeenCalledWith(
    '/cart/items/7',
    expect.objectContaining({
      method: 'PATCH',
      body: JSON.stringify({ quantity: 3 }),
    }),
  );
  // The server's totals replace the old ones.
  expect(screen.getAllByLabelText('€30.00').length).toBeGreaterThan(0);
});

it('removes a line and shows the server refusal on a line', async () => {
  const empty = { ...cart(1, '10.00'), lines: [], count: 0, subtotal: '0.00' };
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce({
      ok: false,
      json: async () => ({ errors: { quantity: ['Only 1 available.'] } }),
    })
    .mockResolvedValueOnce(reply(empty));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={1}>
      <Cart block={block(cart(1, '10.00'))} />
    </CartProvider>,
  );
  fireEvent.click(
    screen.getByRole('button', { name: 'storefront.quantity_increase' }),
  );
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500);
  });
  expect(screen.getByRole('alert')).toHaveTextContent('Only 1 available.');
  await act(async () => {
    fireEvent.click(
      screen.getByRole('button', {
        name: 'storefront.cart_remove_item {"name":"Speaker"}',
      }),
    );
  });
  expect(fetchMock).toHaveBeenLastCalledWith(
    '/cart/items/7',
    expect.objectContaining({ method: 'DELETE' }),
  );
  expect(screen.getByText('storefront.cart_empty')).toBeInTheDocument();
});

it('applies a code and shows what it takes off the total', async () => {
  const applied: CartPageData = {
    ...cart(1, '10.00'),
    coupon: { code: 'SUMMER', discount: '1.00', problem: null },
    total: '9.00',
  };
  const fetchMock = vi.fn().mockResolvedValue(reply(applied));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={1}>
      <Cart block={block(cart(1, '10.00'))} />
    </CartProvider>,
  );
  fireEvent.change(screen.getByLabelText('storefront.cart_code_label'), {
    target: { value: ' SUMMER ' },
  });
  await act(async () => {
    fireEvent.click(
      screen.getByRole('button', { name: 'storefront.cart_code_apply' }),
    );
  });
  expect(fetchMock).toHaveBeenCalledWith(
    '/cart/codes',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ code: 'SUMMER' }),
    }),
  );
  expect(
    screen.getByText('storefront.cart_coupon {"code":"SUMMER"}'),
  ).toBeInTheDocument();
  expect(screen.getAllByLabelText('€9.00').length).toBeGreaterThan(0);
});

it('shows no promotion tooltip when the admin description is empty', () => {
  const data: CartPageData = {
    ...cart(1, '100.00'),
    promotions: [
      { id: '2', name: 'Cart 10%', description: '', discount: '10.00' },
    ],
    total: '90.00',
  };
  render(
    <CartProvider count={1}>
      <Cart block={block(data)} />
    </CartProvider>,
  );
  expect(screen.getByText('Cart 10%')).toBeVisible();
  expect(
    screen.queryByRole('button', { name: /storefront.cart_promotion_details/ }),
  ).not.toBeInTheDocument();
});

it('shows automatic savings alongside a coupon and gift card and refreshes them with quantities', async () => {
  const data: CartPageData = {
    ...cart(1, '100.00'),
    promotions: [
      {
        id: '2',
        name: 'Cart 10%',
        description: '10% off carts of €100 or more.',
        discount: '9.00',
      },
    ],
    coupon: { code: 'SUMMER', discount: '10.00', problem: null },
    gift_card: { code: 'GC-…1234', amount: '30.00', problem: null },
    total: '51.00',
  };
  const next = {
    ...cart(2, '200.00'),
    promotions: [
      {
        id: '2',
        name: 'Cart 10%',
        description: '10% off carts of €100 or more.',
        discount: '20.00',
      },
    ],
    total: '180.00',
  };
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(next)));
  render(
    <CartProvider count={1}>
      <Cart block={block(data)} />
    </CartProvider>,
  );
  expect(screen.getByText('Cart 10%')).toBeVisible();
  const details = screen.getByRole('button', {
    name: /storefront.cart_promotion_details/,
  });
  fireEvent.focus(details);
  expect(screen.getByRole('tooltip')).toHaveTextContent(
    '10% off carts of €100 or more.',
  );
  fireEvent.keyDown(details, { key: 'Escape' });
  expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  fireEvent.click(details);
  expect(screen.getByRole('tooltip')).toHaveTextContent(
    '10% off carts of €100 or more.',
  );
  for (const amount of ['€9.00', '€10.00', '€30.00', '€51.00']) {
    expect(screen.getAllByLabelText(amount).length).toBeGreaterThan(0);
  }
  fireEvent.click(
    screen.getByRole('button', { name: 'storefront.quantity_increase' }),
  );
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500);
  });
  expect(screen.getByText('Cart 10%')).toBeVisible();
  expect(screen.getAllByLabelText('€20.00').length).toBeGreaterThan(0);
  expect(screen.getAllByLabelText('€180.00').length).toBeGreaterThan(0);
});

it('puts one product in the cart from a card and says so', async () => {
  vi.useRealTimers();
  const fetchMock = vi.fn().mockResolvedValue(reply(cart(1, '10.00')));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={0}>
      <Product
        item={{ id: '7', name: 'Speaker', price: '10.00', available: true }}
        display={{ show_add_to_cart: true }}
        format={format}
      />
    </CartProvider>,
  );
  await act(async () => {
    fireEvent.click(
      screen.getByRole('button', { name: 'storefront.add_to_cart' }),
    );
  });
  expect(fetchMock).toHaveBeenCalledWith(
    '/cart/items',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ product_id: 7, quantity: 1 }),
    }),
  );
  expect(await screen.findByText('storefront.cart_added')).toBeInTheDocument();
  expect(
    screen.getByRole('link', { name: 'storefront.cart_view' }),
  ).toHaveAttribute('href', '/cart');
});

it('offers no purchase when the store has no cart', () => {
  render(
    <CartProvider count={0} enabled={false}>
      <Product
        item={{ id: '7', name: 'Speaker', price: '10.00', available: true }}
        display={{ show_add_to_cart: true }}
        format={format}
      />
    </CartProvider>,
  );
  expect(
    screen.queryByRole('button', { name: 'storefront.add_to_cart' }),
  ).not.toBeInTheDocument();
});

it.each(['classic', 'showcase', 'signature'] as const)(
  'hides the cart control on %s cards when the price is hidden',
  (style) => {
    render(
      <CartProvider count={0}>
        <CardStyleContext.Provider value={style}>
          <Product
            item={{ id: '7', name: 'Speaker', price: null, available: true }}
            display={{ show_add_to_cart: true }}
            format={format}
          />
        </CardStyleContext.Provider>
      </CartProvider>,
    );
    expect(
      screen.queryByRole('button', {
        name: /storefront.add_to_cart|storefront.unavailable/,
      }),
    ).not.toBeInTheDocument();
  },
);

it('keeps the Signature card display settings and cart behavior', async () => {
  const fetchMock = vi.fn().mockResolvedValue(reply(cart(1, '10.00')));
  vi.stubGlobal('fetch', fetchMock);
  const item = {
    id: '7',
    name: 'Speaker',
    sku: 'SPEAKER-7',
    price: '75.00',
    base_price: '100.00',
    discount_percent: 25,
    available: true,
    category: { name: 'Audio', url: '/audio' },
    brand_link: { name: 'Acme', url: '/acme' },
    rating: { average: '4.5', count: 24 },
    variants: [
      { id: '7', name: 'Speaker', url: '/speaker', image: null },
      { id: '8', name: 'Other speaker', url: '/other-speaker', image: null },
    ],
  };
  const card = (enabled: boolean) => (
    <CartProvider count={0}>
      <CardStyleContext.Provider value="signature">
        <Product
          item={item}
          display={{
            show_category: enabled,
            show_brand: enabled,
            show_price: enabled,
            show_rating: enabled,
            show_sku: enabled,
            show_availability: enabled,
            show_variations: enabled,
            show_favorites: enabled,
            show_add_to_cart: enabled,
          }}
          format={format}
        />
      </CardStyleContext.Provider>
    </CartProvider>
  );
  const { container, rerender } = render(card(true));
  expect(
    container.querySelector('.sf-showcase-body .sf-card-category'),
  ).toHaveTextContent('AcmeAudio');
  expect(
    container.querySelector('.sf-media-wrap .sf-card-category'),
  ).toBeNull();
  expect(
    container.querySelector('.sf-showcase-body .sf-card-category')
      ?.nextElementSibling?.tagName,
  ).toBe('H3');
  expect(container.querySelector('.sf-card-rating')).toHaveTextContent('(24)');
  expect(container.querySelector('.sf-card-variations')).not.toBeNull();
  expect(container.querySelector('.sf-sku')).toHaveTextContent('SPEAKER-7');
  expect(container.querySelector('.sf-availability')).not.toBeNull();
  expect(container.querySelector('.sf-card-heart')).not.toBeNull();
  expect(container.querySelector('.sf-showcase-prices')).toHaveTextContent(
    '€75.00-25%€100.00',
  );
  await act(async () =>
    fireEvent.click(
      screen.getByRole('button', { name: 'storefront.add_to_cart' }),
    ),
  );
  expect(fetchMock).toHaveBeenCalledWith(
    '/cart/items',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ product_id: 7, quantity: 1 }),
    }),
  );
  rerender(card(false));
  for (const selector of [
    '.sf-card-category',
    '.sf-card-rating',
    '.sf-card-variations',
    '.sf-sku',
    '.sf-availability',
    '.sf-card-heart',
    '.sf-showcase-prices',
    '.sf-signature-cart',
  ]) {
    expect(container.querySelector(selector)).toBeNull();
  }
});

it('keeps the unavailable Signature cart button icon-only with an accessible explanation', () => {
  render(
    <CartProvider count={0}>
      <CardStyleContext.Provider value="signature">
        <Product
          item={{ id: '7', name: 'Speaker', price: '129.00', available: false }}
          display={{ show_add_to_cart: true }}
          format={format}
        />
      </CardStyleContext.Provider>
    </CartProvider>,
  );
  const button = screen.getByRole('button', { name: 'storefront.unavailable' });
  expect(button).toBeDisabled();
  expect(button).toHaveAttribute('title', 'storefront.unavailable');
  expect(button.querySelector('svg')).not.toBeNull();
  expect(button.querySelector('.sf-card-cart-label')).toBeNull();
});

it.each(['classic', 'showcase', 'signature'] as const)(
  'shows grouped features and a view link instead of quick add on %s cards',
  (style) => {
    render(
      <CardStyleContext.Provider value={style}>
        <Product
          item={{
            id: '7',
            name: 'Laptop',
            url: '/laptop',
            price: '10.00',
            available: true,
            view_product: true,
            variation_features: [
              {
                name: 'RAM',
                values: ['8 GB', '16 GB', '24 GB', '32 GB', '64 GB'],
              },
            ],
          }}
          display={{ show_add_to_cart: true }}
          format={format}
        />
      </CardStyleContext.Provider>,
    );
    expect(
      screen.queryByRole('button', { name: 'storefront.add_to_cart' }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'variations.view_product_link' }),
    ).toHaveAttribute('href', '/laptop');
    expect(screen.getByText('RAM:')).toBeVisible();
    expect(screen.getByText('64 GB')).not.toBeVisible();
    fireEvent.click(screen.getByText('…'));
    expect(screen.getByText('64 GB')).toBeVisible();
  },
);

it.each([
  [['16 GB', '24 GB', '32 GB'], '16, 24, 32 GB'],
  [['512 GB SSD', '1024 GB SSD'], '512, 1024 GB SSD'],
  [['512 GB SSD', '1 TB SSD'], '512 GB SSD, 1 TB SSD'],
  [['16gb', '1tb'], '16gb, 1tb'],
  [['1.5 GB', '2.5 GB'], '1.5, 2.5 GB'],
  [['Black', 'Blue'], 'Black, Blue'],
])(
  'compacts repeated feature units without losing mixed units: %j',
  (values, expected) => {
    render(
      <Product
        item={{
          id: '7',
          name: 'Laptop',
          variation_features: [{ name: 'Memory', values }],
        }}
        display={{}}
        format={format}
      />,
    );
    expect(screen.getByText('Memory:').parentElement).toHaveTextContent(
      'Memory: ' + expected,
    );
  },
);

it('shows six labelled color circles and expands extra colors', () => {
  const swatches = [
    'Blue',
    'Silver',
    'Black',
    'White',
    'Gold',
    'Graphite',
    'Red',
  ].map((label, i) => ({
    label,
    color: i === 1 ? null : '#123456',
    selected: i === 0,
  }));
  render(
    <Product
      item={{
        id: '7',
        name: 'Laptop',
        variation_features: [
          { name: 'Color', values: swatches.map((s) => s.label), swatches },
        ],
      }}
      display={{}}
      format={format}
    />,
  );
  const blue = screen.getByRole('img', { name: 'Blue' });
  expect(blue).toHaveAttribute('title', 'Blue');
  expect(blue).toHaveAttribute('tabindex', '0');
  expect(blue).toHaveClass('is-selected');
  expect(screen.getByRole('img', { name: 'Silver' })).toHaveAttribute(
    'title',
    'Silver',
  );
  expect(screen.queryByText('Color:')).not.toBeInTheDocument();
  expect(screen.getByText('+1')).toBeVisible();
  expect(screen.getByRole('img', { name: 'Red' })).not.toBeVisible();
  fireEvent.click(screen.getByText('+1'));
  expect(screen.getByRole('img', { name: 'Red' })).toHaveAttribute(
    'title',
    'Red',
  );
});

it('links enabled color swatches above the title and keeps other swatches display-only', () => {
  render(
    <Product
      item={{
        id: '7',
        name: 'Laptop',
        variation_features: [
          {
            name: 'Color',
            values: ['Blue', 'Red'],
            swatches: [
              {
                label: 'Blue',
                color: '#0000FF',
                selected: true,
                url: '/laptop-blue',
              },
              { label: 'Red', color: '#FF0000', selected: false },
            ],
          },
        ],
      }}
      display={{}}
      format={format}
    />,
  );
  const link = screen.getByRole('link', { name: 'Blue' });
  expect(link).toHaveAttribute('href', '/laptop-blue');
  expect(link).toHaveAttribute('title', 'Blue');
  expect(screen.getByRole('img', { name: 'Red' })).toHaveAttribute(
    'title',
    'Red',
  );
  expect(
    link.compareDocumentPosition(
      screen.getByRole('heading', { name: 'Laptop' }),
    ) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});
