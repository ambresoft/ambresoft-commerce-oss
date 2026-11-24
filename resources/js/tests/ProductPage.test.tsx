import { CartContext } from '../storefront/themes/Default/cartContext';
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductPage from '../storefront/themes/Default/ProductPage';
import CartProvider from '../storefront/themes/Default/CartProvider';
import type { ProductPageData, PublicBlock } from '../storefront/types';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props }),
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};
const product: ProductPageData = {
  id: '22',
  name: 'MacBook Pro 14',
  sku: 'MBP-14',
  url: '/tech/laptops/macbook-pro-14',
  stickers: [],
  image: null,
  price: '2799.00',
  base_price: '3000.00',
  saving: '201.00',
  sale: { title: 'Black Friday', ends_at: '2099-01-01T00:00:00+02:00' },
  brand: {
    name: 'Apple',
    image: { url: '/apple.png', width: 120, height: 60 },
    image_alt: 'Apple',
    url: '/apple',
  },
  quantity: { min: 1, max: 3, step: 1 },
  currency: 'EUR',
  available: true,
  short_description: '<p>Fast laptop</p>',
  description: '<h2>Power</h2>',
  currency_format: format,
  images: [
    { url: '/a.jpg', width: 800, height: 800 },
    { url: '/b.jpg', width: 800, height: 800 },
  ],
  category: { name: 'Laptops', url: '/tech/laptops', path: [] },
  availability: { label: 'In stock', in_stock: true },
  free_shipping: true,
  returnable: true,
  faqs: [{ id: '4', question: 'Does it fit?', answer: '<p>Yes.</p>' }],
  documents: [
    {
      id: '9',
      name: 'Product information sheet',
      type: 'pdf',
      url: '/storefront/products/22/documents/9',
    },
  ],
  price_history: [
    { at: '2026-08-01T10:00:00Z', price: '2999.00' },
    { at: '2026-10-01T10:00:00Z', price: '2799.00' },
  ],
  available_from: null,
  rating: {
    count: 2,
    average: '4.5',
    distribution: { '1': 0, '2': 0, '3': 0, '4': 1, '5': 1 },
  },
  features: [
    {
      name: 'Display',
      features: [{ id: '1', name: 'Size', value: '14.2 in' }],
    },
  ],
  highlights: [{ id: '1', name: 'Size', value: '14.2 in' }],
  variations: [
    {
      id: '11',
      name: 'Colour',
      style: 'text',
      options: [
        {
          id: '1',
          label: 'Black',
          selected: true,
          url: '/black',
          image: null,
          price: '2799.00',
          available: true,
          swatch: null,
        },
        {
          id: '2',
          label: 'Silver',
          selected: false,
          url: '/silver',
          image: null,
          price: '2399.00',
          available: true,
          swatch: null,
        },
      ],
    },
  ],
  similar: null,
  bought_together: {
    items: [
      {
        id: '22',
        name: 'MacBook Pro 14',
        price: '2799.00',
        available: true,
        main: true,
        included: true,
        quantity: 1,
      },
      {
        id: '12',
        name: 'Backpack',
        price: '69.00',
        available: true,
        main: false,
        included: true,
        quantity: 1,
      },
      {
        id: '15',
        name: 'Charger',
        price: '39.00',
        available: false,
        main: false,
        included: false,
        quantity: 1,
      },
    ],
    total: '2868.00',
    included: 2,
    currency_format: format,
  },
  review: { url: '/products/22/reviews', signed_in: false },
};
const block = (
  data: ProductPageData | undefined,
  sections: Record<string, boolean> = {},
): PublicBlock => ({
  id: 'main',
  template: 'main_content',
  title: '',
  css_class: '',
  mobile: true,
  tablet: true,
  desktop: true,
  content: { product: data, sections },
});

beforeEach(() => {
  shared.props = {
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'packages.add_all': 'Add entire package',
        'storefront.more_reviews': 'Show more reviews',
      },
    },
    reviews: {
      data: [
        {
          id: '1',
          rating: 5,
          title: 'Great',
          body: 'Love it',
          author: 'Maria P.',
          verified_purchase: true,
          created_at: '2026-10-01T10:00:00Z',
        },
      ],
      current_page: 1,
      last_page: 2,
      per_page: 10,
      total: 2,
    },
  } as unknown as SharedProps;
});

it('shows the product, its choices, the bundle total and its sections in order', () => {
  const { container } = render(<ProductPage block={block(product)} />);
  expect(
    screen.getByRole('heading', { level: 1, name: 'MacBook Pro 14' }),
  ).toBeVisible();
  expect(screen.getByRole('link', { name: /Laptops/ })).toHaveAttribute(
    'href',
    '/tech/laptops',
  );
  expect(screen.getAllByLabelText('€2,799.00').length).toBeGreaterThan(0);
  expect(screen.getByRole('link', { name: 'Silver' })).toHaveAttribute(
    'href',
    '/silver',
  );
  expect(screen.getByRole('link', { name: 'Black' })).toHaveAttribute(
    'aria-current',
    'true',
  );
  expect(screen.getByLabelText('€2,868.00')).toBeVisible();
  // The unavailable companion is listed but not counted.
  const charger = [...container.querySelectorAll('.sf-pdp-bundle-name')]
    .find((a) => a.textContent === 'Charger')!
    .closest('li')!;
  expect(charger).toHaveClass('is-excluded', 'is-unavailable');
  expect(within(charger).getByRole('checkbox')).toBeDisabled();
  const headings = [
    ...container.querySelectorAll('.sf-pdp-section h2[id]'),
  ].map((h) => h.id);
  expect(headings).toEqual([
    'sf-pdp-bundle',
    'sf-pdp-description',
    'sf-pdp-features',
    'sf-pdp-reviews',
    'sf-pdp-faqs',
  ]);
  expect(
    within(container.querySelector('.sf-pdp-specs')!).getByText('14.2 in'),
  ).toBeVisible();
  expect(screen.getByText('Love it')).toBeVisible();
});

it('loads more reviews, sends guests to sign in and leaves out empty sections', () => {
  const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
  const { container } = render(
    <ProductPage
      block={block({
        ...product,
        bought_together: null,
        description: '',
        features: [],
        highlights: [],
        variations: [],
      })}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Show more reviews' }));
  expect(reload).toHaveBeenCalledWith({
    only: ['reviews'],
    data: { reviews_page: 2 },
  });
  expect(container.querySelector('#sf-pdp-bundle')).toBeNull();
  expect(container.querySelector('#description')).toBeNull();
  expect(container.querySelector('#features')).toBeNull();
  expect(container.querySelector('.sf-pdp-review-form')).toBeNull();
  expect(container.querySelector('a.sf-pdp-outline')).toHaveAttribute(
    'href',
    '/login',
  );
});

it('shows the sale countdown and a quantity stepper that follows the product rules', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.on_sale': 'On sale',
        'storefront.days': 'days',
        'storefront.quantity_increase': 'Increase quantity',
        'storefront.quantity_decrease': 'Decrease quantity',
      },
    },
  };
  const { container } = render(<ProductPage block={block(product)} />);
  expect(container.querySelector('.sf-pdp-sale')).toHaveTextContent(
    'Black Friday',
  );
  expect(container.querySelector('[role="timer"]')).toHaveTextContent('days');
  const more = screen.getByRole('button', { name: 'Increase quantity' });
  const less = screen.getByRole('button', { name: 'Decrease quantity' });
  expect(less).toBeDisabled();
  fireEvent.click(more);
  fireEvent.click(more);
  expect(container.querySelector('.sf-pdp-qty output')).toHaveTextContent('3');
  expect(more).toBeDisabled();
});

it('renders nothing without a product', () => {
  const { container } = render(<ProductPage block={block(undefined)} />);
  expect(container).toBeEmptyDOMElement();
});

it('shows the saving above the price and hides the sections the block switches off', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: { ...english, 'storefront.you_save': 'You save:' },
    },
  };
  const { container } = render(
    <ProductPage
      block={block(product, {
        show_bought_together: false,
        show_description: false,
        show_reviews: false,
        show_product_bar: false,
      })}
    />,
  );
  expect(container.querySelector('.sf-pdp-was')).toHaveTextContent(
    'You save: €201.00',
  );
  expect(container.querySelector('.sf-pdp-was del')).toHaveTextContent(
    '€3,000.00',
  );
  expect(container.querySelector('#sf-pdp-bundle')).toBeNull();
  expect(container.querySelector('#description')).toBeNull();
  expect(container.querySelector('#reviews')).toBeNull();
  expect(container.querySelector('.sf-pdp-sticky')).toBeNull();
  expect(container.querySelector('a.sf-pdp-more')).toBeNull();
  expect(container.querySelector('#features')).not.toBeNull();
});

it('lets the visitor untick companions but never the main product, and recomputes the total exactly', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.bundle_add': 'Add :count products',
      },
    },
  };
  const { container } = render(
    <ProductPage
      block={block({
        ...product,
        bought_together: {
          ...product.bought_together!,
          items: product.bought_together!.items.map((i) =>
            i.id === '15'
              ? { ...i, price: '0.10', available: true, included: true }
              : i.id === '12'
                ? { ...i, price: '0.20' }
                : i,
          ),
        },
      })}
    />,
  );
  const boxes = container.querySelectorAll<HTMLInputElement>(
    '.sf-pdp-check input',
  );
  expect(boxes[0]).toBeDisabled();
  expect(boxes[0]).toBeChecked();
  expect(screen.getByRole('button', { name: 'Add 3 products' })).toBeVisible();
  // 2799.00 + 0.20 + 0.10 without floating point error.
  expect(screen.getByLabelText('€2,799.30')).toBeVisible();
  fireEvent.click(boxes[1]);
  expect(screen.getByLabelText('€2,799.10')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Add 2 products' })).toBeVisible();
});

it('shows returnability, a link to the questions and the questions themselves, and opens the price history', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.returnable': 'Returns accepted',
        'storefront.faqs': 'Questions',
        'storefront.price_history': 'Price history',
        'storefront.history_6m': '6 months',
      },
    },
  };
  const { container } = render(<ProductPage block={block(product)} />);
  expect(screen.getByText('Returns accepted')).toBeVisible();
  expect(
    container.querySelector('a.sf-pdp-card-link:not(.sf-pdp-document)'),
  ).toHaveAttribute('href', '#faqs');
  const sheet = container.querySelector('a.sf-pdp-document')!;
  expect(sheet).toHaveAttribute('href', '/storefront/products/22/documents/9');
  expect(sheet).toHaveAttribute('target', '_blank');
  expect(sheet).toHaveTextContent('Product information sheet');
  expect(
    within(container.querySelector('#faqs')!).getByText('Does it fit?'),
  ).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Price history' }));
  const dialog = screen.getByRole('dialog', { name: 'Price history' });
  expect(
    within(dialog).getByRole('img', { name: 'Price history' }),
  ).toBeInTheDocument();
  fireEvent.click(within(dialog).getByRole('button', { name: '6 months' }));
  expect(
    within(dialog).getByRole('button', { name: '6 months' }),
  ).toHaveAttribute('aria-pressed', 'true');
});

it('shows brand attachments above Questions and opens documents in the existing modal', () => {
  const data = {
    ...product,
    brand_documents: [
      {
        id: '9',
        name: 'Shoe size guide',
        type: 'pdf',
        url: '/storefront/products/22/brand-documents/9',
      },
    ],
  };
  const { container, rerender } = render(<ProductPage block={block(data)} />);
  const link = screen.getByRole('link', { name: 'Shoe size guide' });
  const questionLink = container.querySelector(
    'a.sf-pdp-card-link[href="#faqs"]',
  )!;
  expect(
    link.compareDocumentPosition(questionLink) &
      Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  fireEvent.click(link);
  const modal = screen.getByRole('dialog', { name: 'Shoe size guide' });
  expect(within(modal).getByTitle('Shoe size guide')).toHaveAttribute(
    'src',
    `${data.brand_documents[0].url}#navpanes=0&view=FitH`,
  );
  expect(within(modal).getByRole('link')).toHaveAttribute('target', '_blank');
  fireEvent(modal, new Event('cancel', { bubbles: false, cancelable: true }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  // Product-specific attachments use the same preview, and identical association IDs do not collide.
  fireEvent.click(
    screen.getByRole('link', { name: 'Product information sheet' }),
  );
  expect(
    screen.getByRole('dialog', { name: 'Product information sheet' }),
  ).toBeVisible();
  fireEvent(
    screen.getByRole('dialog'),
    new Event('cancel', { bubbles: false, cancelable: true }),
  );
  rerender(<ProductPage block={block(data, { show_documents: false })} />);
  expect(
    screen.queryByRole('link', { name: 'Shoe size guide' }),
  ).not.toBeInTheDocument();
});

it('opens the full gallery from the picture, magnifies on click and returns to the chosen picture', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.gallery_next': 'Next picture',
        'storefront.zoom_in': 'Zoom in',
        'storefront.zoom_out': 'Zoom out',
        'storefront.close': 'Close',
      },
    },
  };
  const { container } = render(<ProductPage block={block(product)} />);
  const track = container.querySelector('.sf-pdp-track')!;
  fireEvent.pointerDown(track, { clientX: 100 });
  fireEvent.pointerUp(track, { clientX: 101 });
  const gallery = screen.getByRole('dialog', { name: 'MacBook Pro 14' });
  fireEvent.click(
    within(gallery).getByRole('button', { name: 'Next picture' }),
  );
  expect(within(gallery).getByAltText('MacBook Pro 14 (2/2)')).toBeVisible();
  // Moving the mouse alone does nothing; a click shows the lens and the enlarged pane.
  const picture = within(gallery).getByRole('button', { name: 'Zoom in' });
  fireEvent.pointerMove(picture, {
    pointerType: 'mouse',
    clientX: 10,
    clientY: 10,
  });
  expect(gallery.querySelector('.sf-lightbox-lens')).toBeNull();
  fireEvent.pointerDown(picture, { pointerType: 'mouse' });
  fireEvent.click(picture, { clientX: 10, clientY: 10 });
  expect(gallery.querySelector('.sf-lightbox-lens')).not.toBeNull();
  expect(gallery.querySelector('.sf-lightbox-loupe')).not.toBeNull();
  fireEvent.click(within(gallery).getByRole('button', { name: 'Zoom out' }));
  expect(gallery.querySelector('.sf-lightbox-lens')).toBeNull();
  // On touch screens a tap zooms the picture in place.
  fireEvent.pointerDown(picture, { pointerType: 'touch' });
  fireEvent.click(picture);
  expect(
    (within(gallery).getByAltText('MacBook Pro 14 (2/2)') as HTMLImageElement)
      .style.transform,
  ).toMatch(/^scale\(/);
  fireEvent.click(within(gallery).getByRole('button', { name: 'Close' }));
  expect(screen.queryByRole('dialog')).toBeNull();
  // The page's picture follows the one viewed last.
  expect(track).toHaveStyle({ transform: 'translateX(calc(-100% + 0px))' });
});

it('opens the review form in a dialog for signed-in customers', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.rate_product': 'Rate the product',
        'storefront.review_submit': 'Submit review',
        'storefront.close': 'Close',
      },
    },
  };
  render(
    <ProductPage
      block={block({
        ...product,
        review: { url: '/products/22/reviews', signed_in: true },
      })}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Rate the product' }));
  const dialog = screen.getByRole('dialog', { name: 'Rate the product' });
  expect(
    within(dialog).getByRole('button', { name: 'Submit review' }),
  ).toBeDisabled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
  expect(screen.queryByRole('dialog')).toBeNull();
});

it('shows the customer their own review state instead of the form, and errors without a field', () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.rate_product': 'Rate the product',
        'storefront.review_mine_pending': 'Waiting for approval',
      },
    },
  };
  const { rerender } = render(
    <ProductPage
      block={block({
        ...product,
        review: {
          url: '/products/22/reviews',
          signed_in: true,
          mine: 'pending',
        },
      })}
    />,
  );
  expect(screen.getByText('Waiting for approval')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Rate the product' })).toBeNull();
  rerender(
    <ProductPage
      block={block({
        ...product,
        review: { url: '/products/22/reviews', signed_in: true, mine: null },
      })}
    />,
  );
  expect(
    screen.getByRole('button', { name: 'Rate the product' }),
  ).toBeVisible();
});

it('shows heart and share beside the picture, copies the link without a share sheet and hides the heart when wishlists are off', async () => {
  shared.props = {
    ...shared.props,
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'storefront.add_to_favourites': 'Add to favourites',
        'storefront.share': 'Share',
        'storefront.link_copied': 'Link copied',
      },
    },
  };
  const writeText = vi.fn().mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { clipboard: { writeText } });
  const { container } = render(<ProductPage block={block(product)} />);
  expect(
    screen.getByRole('button', { name: 'Add to favourites' }),
  ).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Share' }));
  await waitFor(() =>
    expect(writeText).toHaveBeenCalledWith(
      new URL(product.url, window.location.origin).toString(),
    ),
  );
  expect(await screen.findByText('Link copied')).toBeVisible();
  // A device share sheet is used when there is one.
  const share = vi.fn().mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { share, clipboard: { writeText } });
  fireEvent.click(screen.getByRole('button', { name: 'Share' }));
  await waitFor(() =>
    expect(share).toHaveBeenCalledWith({
      title: 'MacBook Pro 14',
      url: expect.stringContaining('/tech/laptops/macbook-pro-14'),
    }),
  );
  expect(container.querySelectorAll('.sf-pdp-action')).toHaveLength(2);
  vi.unstubAllGlobals();
});

it.each(['hidden prices', 'disabled cart'])(
  'removes all purchase controls with %s',
  (mode) => {
    const data =
      mode === 'hidden prices'
        ? {
            ...product,
            price: null,
            base_price: null,
            saving: null,
            bought_together: product.bought_together
              ? {
                  ...product.bought_together,
                  total: null,
                  items: product.bought_together.items.map((item) => ({
                    ...item,
                    price: null,
                  })),
                }
              : null,
          }
        : product;
    const { container } = render(
      <CartProvider count={0} enabled={mode !== 'disabled cart'}>
        <ProductPage block={block(data)} />
      </CartProvider>,
    );
    expect(container.querySelector('.sf-pdp-add')).not.toBeInTheDocument();
    expect(container.querySelector('.sf-pdp-qty')).not.toBeInTheDocument();
    expect(
      container.querySelector('.sf-pdp-bundle-add'),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: product.name }),
    ).toBeVisible();
    const logo = screen.getByRole('img', { name: 'Apple' });
    if (mode === 'hidden prices') {
      expect(container.querySelector('.sf-pdp-buy')).not.toBeInTheDocument();
      expect(container.querySelector('#bundle')).not.toBeInTheDocument();
      expect(logo.closest('.sf-pdp-title')).not.toBeNull();
    } else {
      expect(container.querySelector('.sf-pdp-buy')).toBeVisible();
      expect(logo.closest('.sf-pdp-buy')).not.toBeNull();
    }
  },
);

it('links the brand logo to the brand page when there is one', () => {
  const { container, rerender } = render(
    <ProductPage block={block(product)} />,
  );
  const logo = () => container.querySelector('img.sf-pdp-brand');
  expect(logo()?.closest('a')?.getAttribute('href')).toBe('/apple');
  rerender(
    <ProductPage
      block={block({
        ...product,
        brand: { ...product.brand!, url: null },
      })}
    />,
  );
  expect(logo()).toBeTruthy();
  expect(logo()?.closest('a')).toBeNull();
  rerender(
    <ProductPage
      block={block({ ...product, brand: { ...product.brand!, image: null } })}
    />,
  );
  const placeholder = screen.getByRole('link', { name: 'Apple' });
  expect(placeholder).toHaveAttribute('href', '/apple');
  expect(
    within(placeholder)
      .getByRole('img', { name: 'Apple' })
      .querySelector('.sf-no-image'),
  ).not.toBeNull();
  expect(logo()).toBeNull();
});

it('places newest and recently viewed directly after similar and omits hidden or empty carousels', () => {
  const recommendations = {
    items: [{ ...product, id: '99', name: 'Another laptop' }],
    currency_format: format,
    display: { per_row: 5 },
  };
  const data = {
    ...product,
    similar: recommendations,
    newest: recommendations,
    recently_viewed: recommendations,
  };
  const { container, rerender } = render(<ProductPage block={block(data)} />);
  const similar = container.querySelector('#similar');
  expect(similar?.nextElementSibling?.id).toBe('newest');
  expect(similar?.nextElementSibling?.nextElementSibling?.id).toBe(
    'recently_viewed',
  );
  const recent = container.querySelector<HTMLElement>('#recently_viewed')!;
  expect(recent).toHaveClass('sf-compact-product-cards');
  expect(similar).not.toHaveClass('sf-compact-product-cards');
  expect(container.querySelector('#newest')).not.toHaveClass(
    'sf-compact-product-cards',
  );
  expect(
    within(recent).getByRole('heading', { name: 'Another laptop' }),
  ).toBeVisible();
  expect(within(recent).getByLabelText('€2,799.00')).toBeVisible();
  expect(within(recent).queryByRole('button')).not.toBeInTheDocument();
  rerender(
    <ProductPage
      block={block(data, { show_newest: false, show_recently_viewed: false })}
    />,
  );
  expect(container.querySelector('#similar')).not.toBeNull();
  expect(container.querySelector('#newest')).toBeNull();
  expect(container.querySelector('#recently_viewed')).toBeNull();
  rerender(
    <ProductPage
      block={block({
        ...data,
        newest: null,
        recently_viewed: { ...recommendations, items: [] },
      })}
    />,
  );
  expect(container.querySelector('#newest')).toBeNull();
  expect(container.querySelector('#recently_viewed')).toBeNull();
});

it('uses the saved section order, preserves defaults for older blocks and leaves questions last', () => {
  const data = block(product);
  data.content.section_order = [
    'show_reviews',
    'show_features',
    'show_description',
    'show_recently_viewed',
    'show_newest',
    'show_similar',
  ];
  const { container, rerender } = render(<ProductPage block={data} />);
  const ids = () =>
    Array.from(container.querySelectorAll('.sf-pdp > .sf-pdp-section')).map(
      (element) => element.id,
    );
  expect(ids()).toEqual([
    'bundle',
    'reviews',
    'features',
    'description',
    'faqs',
  ]);
  rerender(
    <ProductPage
      block={{
        ...data,
        content: { ...data.content, sections: { show_reviews: false } },
      }}
    />,
  );
  expect(ids()).toEqual(['bundle', 'features', 'description', 'faqs']);
  rerender(<ProductPage block={block(product)} />);
  expect(ids()).toEqual([
    'bundle',
    'description',
    'features',
    'reviews',
    'faqs',
  ]);
});

it('applies the Main Content mobile arrow setting to all product recommendation carousels', () => {
  const recommendations = {
    items: [{ ...product, id: '99', name: 'Another laptop' }],
    currency_format: format,
    display: { per_row: 5 },
  };
  const data = block({
    ...product,
    similar: recommendations,
    newest: recommendations,
    recently_viewed: recommendations,
  });
  data.content.display = { hide_arrows_mobile: true };
  const { container, rerender } = render(<ProductPage block={data} />);
  expect(
    container.querySelectorAll('.sf-pdp-similar .sf-hide-arrows-mobile'),
  ).toHaveLength(3);
  rerender(
    <ProductPage
      block={{
        ...data,
        content: { ...data.content, display: { hide_arrows_mobile: false } },
      }}
    />,
  );
  expect(
    container.querySelectorAll('.sf-pdp-similar .sf-hide-arrows-mobile'),
  ).toHaveLength(0);
});

it('keeps ordinary purchases separate from adding a reusable package', async () => {
  const add = vi.fn().mockResolvedValue(true);
  const api = {
    enabled: true,
    count: 0,
    add,
    change: vi.fn(),
    clear: vi.fn(),
    peek: vi.fn(),
    code: vi.fn(),
  };
  const data: ProductPageData = {
    ...product,
    package: {
      name: 'Accessories package',
      items: [
        {
          id: product.id,
          name: product.name,
          image: product.image,
          price: product.price,
          available: true,
          quantity: 1,
        },
        {
          id: '12',
          name: 'Backpack',
          price: '69.00',
          available: true,
          quantity: 1,
        },
      ],
      available: true,
      total: '2868.00',
      currency_format: format,
    },
  };
  const { container, rerender } = render(
    <CartContext.Provider value={api}>
      <ProductPage block={block(data)} />
    </CartContext.Provider>,
  );
  fireEvent.click(container.querySelector('.sf-pdp-add')!);
  await waitFor(() => expect(add).toHaveBeenCalled());
  expect(add.mock.calls[0][0].addPackage).toBeUndefined();
  fireEvent.click(screen.getByRole('button', { name: 'Add entire package' }));
  await waitFor(() =>
    expect(add).toHaveBeenLastCalledWith({ id: '22', addPackage: true }),
  );
  expect(container.querySelector('#sf-pdp-bundle')).toBeInTheDocument();
  const packageSection = container.querySelector('#product-package')!;
  expect(packageSection.querySelector('.sf-bundle-main')).toHaveTextContent(
    product.name,
  );
  expect(packageSection.querySelector('.sf-bundle-extras')).toHaveTextContent(
    'Backpack',
  );
  expect(packageSection.querySelector('.sf-bundle-summary')).toHaveTextContent(
    '€2,86800',
  );
  for (const checkbox of within(packageSection as HTMLElement).getAllByRole(
    'checkbox',
  )) {
    expect(checkbox).toBeChecked();
    expect(checkbox).toBeDisabled();
  }
  // An unavailable package keeps the same layout and cannot add a partial package.
  rerender(
    <CartContext.Provider value={api}>
      <ProductPage
        block={block({
          ...data,
          package: { ...data.package!, available: false, total: null },
        })}
      />
    </CartContext.Provider>,
  );
  expect(
    screen.getByRole('button', { name: 'Add entire package' }),
  ).toBeDisabled();
  expect(
    container.querySelector('#product-package .sf-bundle-summary'),
  ).toBeInTheDocument();
});

it('disables incompatible tiles and dropdown choices and offers explicit complete alternatives', () => {
  const changed = structuredClone(product);
  changed.variations[0].options[1].compatible = false;
  changed.variations[0].options[1].url = null;
  changed.variation_alternatives = [
    {
      id: 'other',
      name: 'Silver 16 GB',
      url: '/silver-16',
      image: null,
      price: '2199.00',
    },
  ];
  const { rerender } = render(<ProductPage block={block(changed)} />);
  const silver = screen.getByRole('link', { name: 'Silver' });
  expect(silver).toHaveAttribute('aria-disabled', 'true');
  expect(silver).not.toHaveAttribute('href');
  expect(silver).toHaveClass('is-disabled');
  expect(silver).toHaveAttribute(
    'title',
    'Unavailable with the other selected options.',
  );
  expect(silver).toHaveTextContent(/^Silver$/);
  const alternativeLink = () =>
    within(screen.getByRole('heading', { name: 'Silver 16 GB' })).getByRole(
      'link',
    );
  expect(alternativeLink()).toHaveAttribute('href', '/silver-16');
  expect(screen.getByLabelText('€2,199.00')).toBeVisible();
  const toggle = screen.getByRole('button', { name: 'Other variations' });
  const panel = screen.getByRole('region', { name: 'Other variations' });
  expect(within(panel).getByRole('article')).toHaveClass('sf-product');
  expect(within(panel).queryByRole('button')).not.toBeInTheDocument();
  expect(toggle).toHaveAttribute('aria-expanded', 'true');
  expect(toggle).toHaveAttribute('aria-controls', panel.id);
  fireEvent.click(toggle);
  expect(toggle).toHaveAttribute('aria-expanded', 'false');
  expect(panel).toHaveAttribute('inert');
  expect(
    screen.queryByRole('link', { name: 'Silver 16 GB' }),
  ).not.toBeInTheDocument();
  // Individual selectors remain usable while the alternative cards are collapsed.
  expect(screen.getByRole('link', { name: 'Black' })).toBeInTheDocument();
  fireEvent.click(toggle);
  expect(panel).not.toHaveAttribute('inert');
  expect(alternativeLink()).toHaveAttribute('href', '/silver-16');
  changed.variations[0].style = 'dropdown';
  rerender(<ProductPage block={block(changed)} />);
  expect(
    screen.getByRole('option', { name: 'Silver — Unavailable' }),
  ).toBeDisabled();
  expect(screen.getByRole('option', { name: 'Black' })).not.toBeDisabled();
});

it('hides the stars and the reviews section while reviews are off for the store or the product', () => {
  const { container } = render(
    <ProductPage block={block({ ...product, reviews_enabled: false })} />,
  );
  expect(container.querySelector('#reviews')).toBeNull();
  expect(container.querySelector('a[href="#reviews"]')).toBeNull();
});

it('shows as many items per row in each recommendation carousel as the block sets', () => {
  const recommendations = {
    items: [{ ...product, id: '99', name: 'Another laptop' }],
    currency_format: format,
    display: { per_row: 5 },
  };
  const data = block({
    ...product,
    similar: recommendations,
    newest: recommendations,
  });
  data.content.carousel_per_row = { similar: 3 };
  const { container } = render(<ProductPage block={data} />);
  const rows = (id: string) =>
    container
      .querySelector<HTMLElement>(`#${id} [style*="--sf-n"]`)
      ?.style.getPropertyValue('--sf-n');
  expect(rows('similar')).toBe('3');
  expect(rows('newest')).toBe('5');
});
