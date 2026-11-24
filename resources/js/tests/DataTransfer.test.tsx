import { render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';
import DataTransferIndex from '../pages/Admin/DataTransfer/Index';
import { english } from './translations';

vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  usePage: () => ({
    url: '/admin/data-transfer',
    props: {
      app: { name: 'CMS' },
      auth: { user: null },
      flash: { status: null },
      capabilities: {},
      links: {},
      i18n: {
        locale: 'en',
        direction: 'ltr',
        locales: [{ code: 'en', name: 'English' }],
        strings: english,
      },
    },
  }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

it('lists available imports and explains when no exports exist', () => {
  render(
    <DataTransferIndex
      navigation={[]}
      breadcrumbs={[]}
      transfers={[
        {
          id: 'core.categories-import',
          kind: 'import',
          title: 'Import categories',
          description: 'Create or update categories from a CSV file.',
          href: '/admin/categories/import',
        },
        {
          id: 'core.regions-import',
          kind: 'import',
          title: 'Import regions',
          description: 'Create or update regions from a CSV file.',
          href: '/admin/regions/import',
        },
      ]}
    />,
  );
  expect(
    screen.getByRole('heading', { level: 1, name: 'Imports / Exports' }),
  ).toBeVisible();
  const imports = screen.getByRole('region', { name: 'Imports' });
  expect(
    within(imports).getByRole('link', { name: 'Open: Import categories' }),
  ).toHaveAttribute('href', '/admin/categories/import');
  expect(
    within(imports).getByRole('link', { name: 'Open: Import regions' }),
  ).toHaveAttribute('href', '/admin/regions/import');
  expect(
    within(screen.getByRole('region', { name: 'Exports' })).getByText(
      'No exports are available yet.',
    ),
  ).toBeVisible();
});
