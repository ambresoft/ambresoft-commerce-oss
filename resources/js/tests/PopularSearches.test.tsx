import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import PopularSearches from '../storefront/themes/Default/PopularSearches';
import type { PublicBlock } from '../storefront/types';

const block = (terms: { term: string; url: string }[]) =>
  ({ content: { popular_searches: terms } }) as unknown as PublicBlock;

it('links every term to its results and renders nothing without terms', () => {
  const { container, rerender } = render(
    <PopularSearches
      block={block([
        { term: 'iphone 17', url: '/search?q=iphone%2017' },
        { term: 'ps5', url: '/search?q=ps5' },
      ])}
    />,
  );
  expect(
    screen.getAllByRole('link').map((link) => link.getAttribute('href')),
  ).toEqual(['/search?q=iphone%2017', '/search?q=ps5']);
  expect(screen.getByRole('link', { name: 'iphone 17' })).toBeTruthy();
  rerender(<PopularSearches block={block([])} />);
  expect(container.innerHTML).toBe('');
});
