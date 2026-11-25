import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Footer from '../storefront/themes/Default/Footer';
import type { PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

const block = (id: string): PublicBlock =>
  ({
    id,
    content: {
      display: { mobile_style: 'accordion' },
      groups: [
        {
          id: 'info',
          name: 'Information',
          url: null,
          children: [
            { id: 'terms', name: 'Terms', url: '/terms', children: [] },
          ],
        },
      ],
      social: [
        {
          network: 'instagram',
          name: 'Instagram',
          url: 'https://instagram.com',
        },
      ],
      apps: [
        {
          network: 'app_store',
          name: 'App Store',
          url: 'https://apps.apple.com',
          image: null,
        },
      ],
    },
  }) as unknown as PublicBlock;

it('starts closed, opens and closes each link group while extras have no toggle', () => {
  render(<Footer block={block('one')} />);
  const toggle = screen.getByRole('button', { name: 'Information' });
  expect(toggle).toHaveAttribute('aria-expanded', 'false');
  expect(toggle).toHaveAttribute('aria-controls', 'footer-one-info');
  fireEvent.click(toggle);
  expect(toggle).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: 'Terms' })).toHaveAttribute(
    'href',
    '/terms',
  );
  fireEvent.click(toggle);
  expect(toggle).toHaveAttribute('aria-expanded', 'false');
  expect(screen.getAllByRole('button')).toHaveLength(1);
  expect(screen.getByRole('link', { name: /Instagram/ })).toBeVisible();
  expect(screen.getByRole('link', { name: /App Store/ })).toBeVisible();
});

it('keeps independent panels and unique controls when a menu is reused in two blocks', () => {
  render(
    <>
      <Footer block={block('one')} />
      <Footer block={block('two')} />
    </>,
  );
  const toggles = screen.getAllByRole('button', { name: 'Information' });
  fireEvent.click(toggles[0]);
  expect(toggles[0]).toHaveAttribute('aria-expanded', 'true');
  expect(toggles[1]).toHaveAttribute('aria-expanded', 'false');
  expect(toggles[1]).toHaveAttribute('aria-controls', 'footer-two-info');
});
