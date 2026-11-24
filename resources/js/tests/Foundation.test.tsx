import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { english } from './translations';
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    props: { i18n: { strings: english, locale: 'en', direction: 'ltr' } },
  }),
}));
import Foundation from '../pages/Foundation';

it('renders the server-provided application name as an accessible heading', () => {
  render(<Foundation applicationName="Foundation test" />);
  expect(
    screen.getByRole('heading', { level: 1, name: 'Foundation test' }),
  ).toBeVisible();
});
