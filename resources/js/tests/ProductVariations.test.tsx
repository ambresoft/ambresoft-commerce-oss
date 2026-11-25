import { render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';
import ProductVariations from '../components/admin/ProductVariations';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', () => ({
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

const value = (id: string, label: string) => ({ id, label });

it('lists the other products of the group with links, values and statuses', () => {
  render(
    <ProductVariations
      variation={{
        pending: null,
        group: {
          id: '1',
          name: 'iPhone 18',
          features: [{ id: '11', name: 'Color' }],
          readiness: { total: 3, available: 3, eligible: true },
          members: [
            {
              id: '17',
              name: 'iPhone 18 Black',
              sku: 'IPHONE-18-BLK',
              status: 'active',
              values: { '11': value('16', 'Black') },
              current: true,
            },
            {
              id: '18',
              name: 'iPhone 18 White',
              sku: 'IPHONE-18-WHT',
              status: 'active',
              values: { '11': value('17', 'White') },
              current: false,
            },
            {
              id: '19',
              name: 'iPhone 18 Blue',
              sku: 'IPHONE-18-BLU',
              status: 'hidden',
              values: { '11': value('18', 'Blue') },
              current: false,
            },
          ],
        },
      }}
    />,
  );
  // The current product is marked, not linked; siblings open in their own editor on this tab.
  expect(
    screen.queryByRole('link', { name: 'iPhone 18 Black' }),
  ).not.toBeInTheDocument();
  expect(screen.getByText('iPhone 18 Black').closest('tr')).toHaveAttribute(
    'data-selected',
  );
  expect(screen.getByRole('link', { name: 'iPhone 18 White' })).toHaveAttribute(
    'href',
    '/admin/products/18/edit?tab=variations',
  );
  const blue = screen
    .getByRole('link', { name: 'iPhone 18 Blue' })
    .closest('tr')!;
  expect(within(blue).getByText('Blue')).toBeVisible();
  expect(within(blue).getByText('products.hidden')).toBeVisible();
  expect(screen.getByRole('columnheader', { name: 'Color' })).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'variations.manage_group' }),
  ).toHaveAttribute('href', '/admin/variation-groups/1');
});

it('explains a standalone product instead of showing an empty table', () => {
  render(<ProductVariations variation={{ group: null, pending: null }} />);
  expect(screen.getByText('variations.tab_standalone')).toBeVisible();
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
