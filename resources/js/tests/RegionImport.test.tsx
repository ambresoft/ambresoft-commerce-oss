import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import RegionImport, {
  type ImportPreview,
} from '../pages/Admin/Regions/Import';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: shared.props, url: '/admin/regions/import' }),
    Head: () => null,
  };
});
const strings = Object.fromEntries(
  Object.entries({
    title: 'Import regions',
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
    created: 'New regions',
    updated: 'Updates',
    skipped: 'Unchanged',
    errors: 'Errors',
    confirm: 'I reviewed these changes',
    run: 'Import regions now',
    cancel: 'Discard preview',
    preview: 'Preview — nothing saved',
    completed: 'Completed',
    failed: 'Failed — rolled back',
    processing: 'Processing',
    row: 'Row',
    column: 'Column',
    identifier: 'Region',
    action: 'Action',
    bounded: 'Bounded preview',
    help: 'CSV help',
    columns: 'Columns',
    download_errors: 'Download error report',
  }).map(([key, value]) => ['region_import.' + key, value]),
);
const props = {
  navigation: [],
  breadcrumbs: [],
  columns: ['region_id', 'country_code', 'code', 'name.en', 'name.el'],
  delimiters: [',', ';'],
  maxFileBytes: 20971520,
};
const preview: ImportPreview = {
  token: 'preview-token',
  filename: 'regions.csv',
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
      name: 'Attica',
      country: 'GR',
      code: 'ATT',
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
    capabilities: { 'categories.import': true },
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
  render(<RegionImport {...props} preview={null} />);
  choose(screen.getByLabelText('CSV delimiter'), 'Semicolon');
  fireEvent.change(screen.getByLabelText('CSV file', { selector: 'input' }), {
    target: {
      files: [
        new File(['name.en\nExample'], 'regions.csv', { type: 'text/csv' }),
      ],
    },
  });
  fireEvent.submit(
    screen.getByRole('button', { name: 'Upload & preview' }).closest('form')!,
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/regions/import/preview',
    expect.objectContaining({
      delimiter: ';',
      file: expect.any(File),
    }),
    expect.objectContaining({ forceFormData: true }),
  );
  expect(
    screen.getByRole('link', { name: 'Download CSV template' }),
  ).toHaveAttribute('href', '/admin/regions/import/template?delimiter=%3B');
});

it('requires explicit confirmation and shows a bounded useful operation preview', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<RegionImport {...props} preview={preview} />);
  expect(post).not.toHaveBeenCalled();
  expect(screen.getByText('Attica')).toBeVisible();
  expect(screen.getByText('ATT')).toBeVisible();
  const submit = screen.getByRole('button', { name: 'Import regions now' });
  expect(submit).toBeDisabled();
  fireEvent.click(
    screen.getByRole('checkbox', { name: /I reviewed these changes/ }),
  );
  expect(submit).toBeEnabled();
  fireEvent.click(submit);
  expect(post).toHaveBeenCalledWith(
    '/admin/regions/import/preview-token/confirm',
    { confirmed: true },
    expect.any(Object),
  );
});

it('blocks invalid previews and exposes row errors and a downloadable report', () => {
  render(
    <RegionImport
      {...props}
      preview={{
        ...preview,
        errors: 1,
        issues: [
          {
            row: 18,
            identifier: 'GR:BAD',
            column: 'code',
            reason: 'Code is already in use',
            severity: 'error',
          },
        ],
      }}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'Import regions now' }),
  ).not.toBeInTheDocument();
  expect(screen.getByText('Row 18 · code')).toBeVisible();
  expect(screen.getByText('Code is already in use')).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'Download error report' }),
  ).toHaveAttribute('href', '/admin/regions/import/preview-token/errors');
});

it('uses the shared drop zone and allows replacement and removal without automatic uploads', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<RegionImport {...props} preview={null} />);
  const submit = screen.getByRole('button', { name: 'Upload & preview' });
  expect(submit).toBeDisabled();
  const drop =
    screen.getByText('Drop your CSV here').parentElement!.parentElement!;
  fireEvent.drop(drop, {
    dataTransfer: {
      types: ['Files'],
      files: [
        new File(['country_code,code,name.en\nGR,ATT,Attica'], 'regions.csv'),
      ],
    },
  });
  expect(screen.getByText('regions.csv')).toBeVisible();
  expect(submit).toBeEnabled();
  fireEvent.change(screen.getByLabelText('CSV file', { selector: 'input' }), {
    target: { files: [new File(['header\nrow'], 'replacement.csv')] },
  });
  expect(screen.queryByText('regions.csv')).not.toBeInTheDocument();
  expect(screen.getByText('replacement.csv')).toBeVisible();
  expect(
    screen.getByText('CSV format and instructions').closest('details'),
  ).not.toHaveAttribute('open');
  fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
  expect(submit).toBeDisabled();
  expect(post).not.toHaveBeenCalled();
});

it('uses the server file limit and blocks invalid selections before preview', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<RegionImport {...props} maxFileBytes={1024} preview={null} />);
  const field = screen.getByLabelText('CSV file', { selector: 'input' });
  expect(screen.getByText('UTF-8 CSV or TXT · up to 1 KB')).toBeVisible();
  for (const files of [
    [new File(['x'], 'photo.png')],
    [new File([new Uint8Array(1025)], 'large.csv')],
  ]) {
    fireEvent.change(field, { target: { files } });
    expect(
      screen.getByText('Choose one CSV or TXT file, up to 1 KB.'),
    ).toBeVisible();
    expect(
      screen.getByRole('button', { name: 'Upload & preview' }),
    ).toBeDisabled();
  }
  expect(post).not.toHaveBeenCalled();
});
