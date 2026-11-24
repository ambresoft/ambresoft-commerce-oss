import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import CategoryLanding from '../storefront/themes/Default/CategoryLanding';
import type { CategoryLandingData, PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

const category: CategoryLandingData = {
  id: '120',
  name: 'Gaming',
  description: '<p>Consoles and more.</p>',
  url: '/technology/gaming',
  path: [
    { name: 'Technology', url: '/technology' },
    { name: 'Gaming', url: '/technology/gaming' },
  ],
  items: [
    {
      id: '121',
      name: 'Consoles',
      url: '/technology/gaming/consoles',
      image: { url: '/c.png', width: 400, height: 400 },
      image_alt: 'Consoles',
    },
    { id: '122', name: 'Chairs', url: null, image: null, image_alt: 'Chairs' },
  ],
};
const block = (content: Partial<PublicBlock['content']>): PublicBlock =>
  ({
    id: 'b',
    type: 'main_content',
    template: 'main_content',
    content: { category, ...content },
  }) as unknown as PublicBlock;

it('shows the trail, the name and the subcategory cards with the block settings', () => {
  const { container } = render(
    <CategoryLanding
      block={block({ per_row: 6, card_shadow: true, mobile_per_row: 3 })}
    />,
  );
  expect(
    screen.getByRole('heading', { level: 1, name: 'Gaming' }),
  ).toBeVisible();
  expect(screen.getByText('Consoles and more.')).toBeVisible();
  expect(screen.getByRole('link', { name: 'storefront.home' })).toHaveAttribute(
    'href',
    '/',
  );
  expect(screen.getByRole('link', { name: 'Technology' })).toHaveAttribute(
    'href',
    '/technology',
  );
  expect(screen.getByRole('link', { name: /Consoles/ })).toHaveAttribute(
    'href',
    '/technology/gaming/consoles',
  );
  expect(screen.getByText('Chairs').closest('a')).toBeNull();
  const grid = container.querySelector('.sf-cl-grid') as HTMLElement;
  expect(grid.style.getPropertyValue('--sf-cl-cols')).toBe('6');
  expect(grid.style.getPropertyValue('--sf-cl-mobile-cols')).toBe('3');
  expect(grid).not.toHaveClass('is-mobile-carousel');
  expect(container.querySelectorAll('.sf-cl-card.has-shadow')).toHaveLength(2);
});

it('can be a carousel on phones and drop the shadow', () => {
  const { container } = render(
    <CategoryLanding
      block={block({ card_shadow: false, mobile_display: 'carousel' })}
    />,
  );
  expect(container.querySelector('.sf-cl-grid')).toHaveClass(
    'is-mobile-carousel',
  );
  expect(container.querySelectorAll('.sf-cl-card.has-shadow')).toHaveLength(0);
});

it('says when a landing page has no subcategories yet', () => {
  render(
    <CategoryLanding block={block({ category: { ...category, items: [] } })} />,
  );
  expect(screen.getByText('storefront.category_empty')).toBeVisible();
});
