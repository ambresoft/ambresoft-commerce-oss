import { render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Directory from '../storefront/themes/Default/Directory';
import type { DirectoryData, PublicBlock } from '../storefront/types';
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
const block = (directory: DirectoryData): PublicBlock =>
  ({ content: { directory } }) as PublicBlock;

it('renders the sitemap hierarchy, information links and server-approved account shortcuts', () => {
  render(
    <Directory
      block={block({
        kind: 'sitemap',
        pages: [{ name: 'Terms of use', url: '/terms' }],
        account: [{ name: 'My account', url: '/account' }],
        categories: [
          {
            id: '1',
            name: 'Technology',
            url: '/technology',
            children: [
              {
                id: '2',
                name: 'Laptops',
                url: '/technology/laptops',
                children: [],
              },
            ],
          },
        ],
      })}
    />,
  );
  expect(
    screen.getByRole('heading', { level: 1, name: 'storefront.sitemap_title' }),
  ).toBeVisible();
  expect(
    screen.getByRole('navigation', { name: 'storefront.breadcrumb' }),
  ).toBeVisible();
  expect(screen.getByRole('link', { name: 'Terms of use' })).toHaveAttribute(
    'href',
    '/terms',
  );
  expect(screen.getByRole('link', { name: 'Laptops' })).toHaveAttribute(
    'href',
    '/technology/laptops',
  );
  expect(screen.queryByRole('link', { name: 'Cart' })).not.toBeInTheDocument();
});
it('groups brands by letter with working anchors and preserves names that have no landing page', () => {
  render(
    <Directory
      block={block({
        kind: 'brands',
        groups: [
          { letter: 'A', items: [{ id: '1', name: 'Apple', url: '/apple' }] },
          { letter: 'Α', items: [{ id: '2', name: 'Άλφα', url: null }] },
        ],
      })}
    />,
  );
  const alphabet = screen.getByRole('navigation', {
    name: 'storefront.brands_alphabet',
  });
  for (const letter of ['A', 'Α']) {
    const link = within(alphabet).getByRole('link', { name: letter });
    const target = document.getElementById(link.getAttribute('href')!.slice(1));
    expect(target).toHaveTextContent(letter);
  }
  expect(screen.getByRole('link', { name: 'Apple' })).toHaveAttribute(
    'href',
    '/apple',
  );
  expect(screen.getByText('Άλφα')).toBeVisible();
  expect(screen.queryByRole('link', { name: 'Άλφα' })).not.toBeInTheDocument();
});
it('shows a useful empty brand state without an empty alphabet navigation', () => {
  render(<Directory block={block({ kind: 'brands', groups: [] })} />);
  expect(screen.getByText('storefront.brands_empty')).toBeVisible();
  expect(
    screen.queryByRole('navigation', { name: 'storefront.brands_alphabet' }),
  ).not.toBeInTheDocument();
});
