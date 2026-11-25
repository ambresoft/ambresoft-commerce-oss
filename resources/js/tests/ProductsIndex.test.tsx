import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductsIndex from '../pages/Admin/Products/Index';
import type { SharedProps } from '../types/page';
import type { ProductRow } from '../types/products';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: shared.props, url: '/admin/products' }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
  };
});

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'admin@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'products.update': true,
      'products.status': true,
      'products.create': true,
      'products.delete': true,
    },
    links: {} as SharedProps['links'],
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [{ code: 'en', name: 'English' }],
      strings: {
        ...english,
        ...Object.fromEntries(
          [
            'title',
            'type',
            'categories',
            'incomplete',
            'complete',
            'active',
            'disabled',
            'hidden',
            'physical',
            'digital',
            'all',
            'apply',
            'duplicate',
            'trash',
            'updated',
            'updated_at',
            'name',
            'sku',
            'price',
            'status',
            'select_page',
            'search_placeholder',
            'out_of_stock',
            'stock',
            'list_price_label',
            'stock_action_filter',
            'stock_action_general',
            'stock_action_custom',
          ].map((key) => ['products.' + key, key]),
        ),
        'products.count_other': ':count products',
        'products.price_currency': 'Price (:code)',
      },
    },
  } as SharedProps;
});

const row = (id: string, name: string, category: string[]): ProductRow => ({
  id,
  name,
  confirmationName: name,
  version: 1,
  sku: 'SKU-' + id,
  type: 'physical',
  price: '10.00',
  price_formatted: '€10.00',
  status: 'active',
  category,
  incomplete: false,
  updated_at: '2026-10-10T09:00:00+00:00',
  image: null,
  variation_group: null,
  quantity: 5,
  list_price_formatted: null,
});

const render_ = (filters: Record<string, string> = {}) =>
  render(
    <ProductsIndex
      navigation={[]}
      breadcrumbs={[]}
      products={{
        data: [
          {
            ...row('1', 'Chair', ['Home', 'Furniture']),
            variation_group: { id: '4', name: 'Chair family' },
          },
          row('2', 'Lamp', []),
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 2,
      }}
      filters={filters}
      trash={false}
      catalogCount={8}
      categories={[{ id: '5', name: 'Home → Furniture' }]}
      bulkResults={[]}
      currency={{ code: 'EUR' }}
    />,
  );

it('renders catalogue rows with category paths, SKU and type', () => {
  render_();
  expect(screen.getByText('8 products')).toBeVisible();
  const chair = screen.getByRole('link', { name: 'Chair' }).closest('tr')!;
  expect(within(chair).getByText('SKU-1 · physical')).toBeVisible();
  expect(chair).toHaveTextContent('Home›Furniture');
  expect(within(chair).getByText('Furniture')).toHaveClass('font-medium');
  expect(
    within(chair).getByRole('button', { name: 'duplicate: Chair' }),
  ).toBeVisible();
});

it('submits search with Enter and applies filter chips and sort immediately', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  render_({ sort: 'updated_at', direction: 'desc', per_page: '50' });
  fireEvent.change(screen.getByPlaceholderText('search_placeholder'), {
    target: { value: 'chair' },
  });
  fireEvent.submit(screen.getByRole('search'));
  expect(get).toHaveBeenLastCalledWith(
    '/admin/products',
    { search: 'chair', sort: 'updated_at', direction: 'desc', per_page: '50' },
    expect.objectContaining({ preserveState: true, replace: true }),
  );
  fireEvent.click(screen.getByRole('button', { name: /^status:/i }));
  fireEvent.click(screen.getByRole('button', { name: 'hidden' }));
  expect(get.mock.lastCall?.[1]).toMatchObject({ status: 'hidden' });
  fireEvent.click(screen.getByRole('button', { name: 'Sort ascending' }));
  expect(get.mock.lastCall?.[1]).toMatchObject({
    sort: 'updated_at',
    direction: 'asc',
  });
  expect(
    screen.queryByRole('link', { name: 'Reset filters' }),
  ).not.toBeInTheDocument();
});

it('shows the bulk bar for selected rows and applies the chosen status', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render_();
  expect(screen.queryByText('1 selected')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Chair' }));
  expect(screen.getByText('1 selected')).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'Chair' }).closest('tr'),
  ).toHaveAttribute('data-selected');
  fireEvent.click(screen.getByRole('button', { name: 'apply' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/products/bulk',
    { ids: ['1'], status: 'disabled', return_to: '/admin/products' },
    expect.any(Object),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Clear selection' }));
  expect(screen.queryByText('1 selected')).not.toBeInTheDocument();
});

it("shows each product's variation group and filters grouped or standalone products", () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  render_();
  expect(screen.getByRole('link', { name: 'Chair family' })).toHaveAttribute(
    'href',
    '/admin/variation-groups/4',
  );
  const lamp = screen.getByRole('link', { name: 'Lamp' }).closest('tr')!;
  expect(within(lamp).getByText('Standalone')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: /^Variation group:/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Standalone' }));
  expect(get.mock.lastCall?.[1]).toMatchObject({ variation: 'standalone' });
});

it('filters products that use the general stock setting or their own value', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  render_();
  fireEvent.click(
    screen.getByRole('button', { name: /^stock_action_filter:/ }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'stock_action_custom' }));
  expect(get.mock.lastCall?.[1]).toMatchObject({ stock_action: 'custom' });
});

it('shows stock and a reference price only when it is above the selling price', () => {
  render(
    <ProductsIndex
      navigation={[]}
      breadcrumbs={[]}
      products={{
        data: [
          {
            ...row('1', 'Chair', []),
            quantity: 0,
            list_price_formatted: '€150.00',
          },
          { ...row('2', 'Lamp', []), quantity: 12 },
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 2,
      }}
      filters={{}}
      trash={false}
      catalogCount={2}
      categories={[]}
      bulkResults={[]}
      currency={{ code: 'EUR' }}
    />,
  );
  const chair = screen.getByRole('link', { name: 'Chair' }).closest('tr')!;
  expect(within(chair).getByText('€150.00').tagName).toBe('DEL');
  expect(within(chair).getByText(/0 · out_of_stock/)).toBeVisible();
  const lamp = screen.getByRole('link', { name: 'Lamp' }).closest('tr')!;
  expect(within(lamp).queryByText('€150.00')).not.toBeInTheDocument();
  expect(within(lamp).getByText('12')).toBeVisible();
});
