import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductImport, {
  type ImportPreview,
} from '../pages/Admin/Products/Import';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: shared.props, url: '/admin/products/import' }),
    Head: () => null,
  };
});
const strings = Object.fromEntries(
  Object.entries({
    title: 'Import products',
    intro: 'Preview before writing',
    template: 'Download CSV template',
    file: 'CSV file',
    delimiter: 'CSV delimiter',
    comma: 'Comma',
    semicolon: 'Semicolon',
    separator: 'Path separator',
    upload: 'Upload & preview',
    summary: 'Import summary',
    rows: 'Rows',
    created: 'New products',
    updated: 'Updates',
    skipped: 'Unchanged',
    parents: 'Parents',
    errors: 'Errors',
    confirm: 'I reviewed these changes',
    run: 'Import products now',
    cancel: 'Discard preview',
    preview: 'Preview — nothing saved',
    completed: 'Completed',
    failed: 'Failed — rolled back',
    processing: 'Processing',
    row: 'Row',
    column: 'Column',
    identifier: 'Category',
    action: 'Action',
    bounded: 'Bounded preview',
    help: 'CSV help',
    columns: 'Columns',
    download_errors: 'Download error report',
  }).map(([key, value]) => ['product_import.' + key, value]),
);
const props = {
  navigation: [],
  breadcrumbs: [],
  columns: ['sku', 'price', 'name.en'],
  delimiters: [',', ';'],
  limits: { fileBytes: 20971520, imageUrls: 100, imageBytes: 104857600 },
};
const preview: ImportPreview = {
  token: 'preview-token',
  filename: 'products.csv',
  status: 'preview',
  rows: 1,
  created: 1,
  updated: 0,
  skipped: 0,
  errors: 0,
  sample: [
    {
      row: 2,
      action: 'created',
      name: 'Chairs',
      sku: 'CHAIR-001',
      status: 'active',
    },
  ],
  issues: [],
};

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
    capabilities: { 'products.import': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: {
        ...english,
        ...strings,
        'categories.status': 'Status',
        'categories.position': 'Position',
        'categories.active': 'Active',
      },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: {
      logout: '/logout',
      'admin.dashboard': '/admin',
    } as SharedProps['links'],
  };
});

it('uploads a file with explicit delimiters for preview, never directly confirms', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<ProductImport {...props} preview={null} />);
  choose(screen.getByLabelText('CSV delimiter'), 'Semicolon');
  fireEvent.change(screen.getByLabelText('CSV file', { selector: 'input' }), {
    target: {
      files: [
        new File(['name.en\nExample'], 'products.csv', { type: 'text/csv' }),
      ],
    },
  });
  fireEvent.submit(
    screen.getByRole('button', { name: 'Upload & preview' }).closest('form')!,
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/products/import/preview',
    expect.objectContaining({
      delimiter: ';',
      file: expect.any(File),
    }),
    expect.objectContaining({ forceFormData: true }),
  );
  expect(
    screen.getByRole('link', { name: 'Download CSV template' }),
  ).toHaveAttribute('href', '/admin/products/import/template?delimiter=%3B');
});

it('requires explicit confirmation and shows a bounded useful operation preview', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<ProductImport {...props} preview={preview} />);
  expect(post).not.toHaveBeenCalled();
  expect(screen.getByText('Chairs')).toBeVisible();
  expect(screen.getByText('CHAIR-001')).toBeVisible();
  const submit = screen.getByRole('button', { name: 'Import products now' });
  expect(submit).toBeDisabled();
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'I reviewed these changes' }),
  );
  expect(submit).toBeEnabled();
  fireEvent.click(submit);
  expect(post).toHaveBeenCalledWith(
    '/admin/products/import/preview-token/confirm',
    { confirmed: true },
    expect.any(Object),
  );
});

it('blocks invalid previews and exposes row errors and a downloadable report', () => {
  render(
    <ProductImport
      {...props}
      preview={{
        ...preview,
        errors: 1,
        issues: [
          {
            row: 18,
            identifier: 'Bad path',
            column: 'sku',
            reason: 'SKU is duplicated',
            severity: 'error',
          },
        ],
      }}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'Import products now' }),
  ).not.toBeInTheDocument();
  expect(screen.getByText('Row 18 · sku')).toBeVisible();
  expect(screen.getByText('SKU is duplicated')).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'Download error report' }),
  ).toHaveAttribute('href', '/admin/products/import/preview-token/errors');
});

it('supports CSV drop, replacement and removal without submitting automatically', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<ProductImport {...props} preview={null} />);
  const submit = screen.getByRole('button', { name: 'Upload & preview' });
  expect(submit).toBeDisabled();
  const drop =
    screen.getByText('Drop your CSV here').parentElement!.parentElement!;
  fireEvent.drop(drop, {
    dataTransfer: {
      types: ['Files'],
      files: [new File(['name.en\nShoes'], 'shoes.csv')],
    },
  });
  expect(screen.getByText('shoes.csv')).toBeVisible();
  expect(submit).toBeEnabled();
  expect(post).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('CSV file', { selector: 'input' }), {
    target: { files: [new File(['name.en\nNew'], 'new.csv')] },
  });
  expect(screen.queryByText('shoes.csv')).not.toBeInTheDocument();
  expect(screen.getByText('new.csv')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
  expect(submit).toBeDisabled();
  expect(screen.queryByText('new.csv')).not.toBeInTheDocument();
  expect(post).not.toHaveBeenCalled();
});

it('rejects invalid file types, multiple files and oversized uploads before preview', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<ProductImport {...props} preview={null} />);
  const field = screen.getByLabelText('CSV file', { selector: 'input' });
  for (const files of [
    [new File(['image'], 'photo.png')],
    [new File(['a'], 'a.csv'), new File(['b'], 'b.csv')],
    [new File([new Uint8Array(props.limits.fileBytes + 1)], 'large.csv')],
  ]) {
    fireEvent.change(field, { target: { files } });
    expect(
      screen.getByText('Choose one CSV or TXT file, up to 20 MB.'),
    ).toBeVisible();
    expect(
      screen.getByRole('button', { name: 'Upload & preview' }),
    ).toBeDisabled();
  }
  expect(post).not.toHaveBeenCalled();
});
