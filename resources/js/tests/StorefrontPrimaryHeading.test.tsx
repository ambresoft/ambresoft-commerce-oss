import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import StorefrontPrimaryHeading from '../components/StorefrontPrimaryHeading';

it('provides a real store title when a custom homepage layout has no primary heading', () => {
  render(
    <main>
      <StorefrontPrimaryHeading title="Ambre shop" />
    </main>,
  );
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Ambre shop',
  );
});
it('preserves the heading supplied by merchant content', () => {
  render(
    <main>
      <h1>Merchant heading</h1>
      <StorefrontPrimaryHeading title="Ambre shop" />
    </main>,
  );
  expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Merchant heading',
  );
});
