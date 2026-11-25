import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Comparison from '../storefront/themes/Default/Comparison';
import ProductActions from '../storefront/themes/Default/ProductActions';
import type {
  ComparisonPageData,
  ProductPageData,
  PublicBlock,
} from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, params?: Record<string, unknown>) =>
    params ? `${key} ${JSON.stringify(params)}` : key,
}));
vi.mock('@inertiajs/react', () => ({
  router: { reload: vi.fn() },
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};

afterEach(() => vi.restoreAllMocks());

const product = (comparison: ProductPageData['comparison']): ProductPageData =>
  ({
    id: '22',
    name: 'MacBook Pro',
    url: '/tech/macbook-pro',
    comparison,
  }) as ProductPageData;

it('puts the comparison button above share, and only when comparison is on', () => {
  const { rerender } = render(
    <ProductActions
      product={product({ enabled: true, max: 4, ids: [], in_list: false })}
    />,
  );
  const buttons = screen.getAllByRole('button').map((b) => b.ariaLabel);
  expect(buttons.indexOf('storefront.comparison_add')).toBeGreaterThan(-1);
  expect(buttons.indexOf('storefront.comparison_add')).toBeLessThan(
    buttons.indexOf('storefront.share'),
  );
  rerender(<ProductActions product={product(null)} />);
  expect(screen.queryByLabelText('storefront.comparison_add')).toBeNull();
});

it('adds a product to the comparison and links to it', async () => {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ ids: ['22'], max: 4 }),
  });
  vi.stubGlobal('fetch', fetchMock);
  render(
    <ProductActions
      product={product({ enabled: true, max: 4, ids: [], in_list: false })}
    />,
  );
  fireEvent.click(screen.getByLabelText('storefront.comparison_add'));
  await waitFor(() =>
    expect(
      screen.getByRole('link', { name: 'storefront.comparison_view' }),
    ).toHaveAttribute('href', '/comparison'),
  );
  expect(fetchMock).toHaveBeenCalledWith(
    '/comparison/products/22',
    expect.objectContaining({ method: 'PUT' }),
  );
  expect(screen.getByLabelText('storefront.comparison_remove')).toHaveAttribute(
    'aria-pressed',
    'true',
  );
});

it('refuses a product past the limit with a message and no request', () => {
  const fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
  render(
    <ProductActions
      product={product({
        enabled: true,
        max: 2,
        ids: ['1', '2'],
        in_list: false,
      })}
    />,
  );
  fireEvent.click(screen.getByLabelText('storefront.comparison_add'));
  expect(screen.getByRole('status')).toHaveTextContent(
    'storefront.comparison_full {"max":2}',
  );
  expect(fetchMock).not.toHaveBeenCalled();
});

const data: ComparisonPageData = {
  items: [
    { id: '1', name: 'Laptop A', url: '/a', price: '100.00', image: null },
    { id: '2', name: 'Laptop B', url: '/b', price: '120.00', image: null },
  ],
  groups: [
    {
      name: 'General',
      rows: [
        { id: 'c', name: 'Colour', values: ['Black', 'Silver'], same: false },
        { id: 's', name: 'Screen', values: ['14 in', '14 in'], same: true },
      ],
    },
  ],
  max: 4,
  currency_format: format,
};
const block = (comparison: ComparisonPageData): PublicBlock =>
  ({
    id: 'b',
    type: 'main_content',
    template: 'main_content',
    content: { comparison },
  }) as unknown as PublicBlock;

it('shows the products side by side and filters similar and different features', () => {
  const { container } = render(<Comparison block={block(data)} />);
  expect(screen.getByRole('link', { name: 'Laptop A' })).toHaveAttribute(
    'href',
    '/a',
  );
  expect(container.querySelectorAll('.sf-cmp-row')).toHaveLength(2);
  expect(container.querySelectorAll('.sf-cmp-row.is-different')).toHaveLength(
    1,
  );
  fireEvent.click(
    screen.getByRole('button', {
      name: 'storefront.comparison_show_differences',
    }),
  );
  expect(container.querySelectorAll('.sf-cmp-row')).toHaveLength(1);
  expect(screen.getAllByText('Black')).toHaveLength(1);
  fireEvent.click(
    screen.getByRole('button', {
      name: 'storefront.comparison_show_similarities',
    }),
  );
  expect(screen.getAllByText('14 in')).toHaveLength(2);
  expect(screen.queryByText('Black')).toBeNull();
});

it('explains an empty comparison', () => {
  render(<Comparison block={block({ ...data, items: [], groups: [] })} />);
  expect(screen.getByText('storefront.comparison_empty')).toBeVisible();
});
