import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Form from '../pages/Admin/Stickers/Form';
import Index from '../pages/Admin/Stickers/Index';
import type { SharedProps } from '../types/page';
import type { StickerDetail } from '../types/stickers';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/stickers/create' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const props = {
  navigation: [],
  breadcrumbs: [],
  sticker: null,
  currency: 'EUR',
  timezone: 'Europe/Athens',
  defaultLocale: 'el',
  options: {
    products: [{ id: '1', name: 'Lamp · SKU-1' }],
    features: [],
    categories: [],
    options: [],
    images: [],
  },
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: { user: null, canAccessAdmin: true },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: { 'stickers.view': true, 'stickers.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings: {
        ...english,
        'stickers.title': 'Stickers',
        'stickers.settings': 'Settings',
        'stickers.limits': 'Display limits',
        'stickers.card_limit': 'Maximum per product card',
        'stickers.detail_limit': 'Maximum per product detail',
        'stickers.general': 'General',
        'stickers.appearance': 'Appearance',
        'stickers.display': 'Display',
        'stickers.assignment': 'Product Assignment',
        'stickers.preview': 'Preview',
        'stickers.type': 'Assignment type',
        'stickers.manual': 'Manual',
        'stickers.dynamic': 'Dynamic',
        'stickers.style': 'Visual style',
        'stickers.text': 'Display text',
        'stickers.image': 'Image',
        'stickers.image_help': 'PNG, JPEG or WebP, up to 5 MB.',
        'stickers.products': 'Products',
        'stickers.conditions': 'Conditions',
        'stickers.minimum': 'Minimum',
        'stickers.maximum': 'Maximum',
        'stickers.add_condition': 'Add condition',
        'stickers.condition_price': 'Price range',
        'stickers.condition_in_stock': 'In stock',
        'stickers.condition_category': 'Category membership',
        'stickers.category': 'Category',
        'stickers.match': 'Condition logic',
        'stickers.all': 'All (AND)',
        'stickers.any': 'Any (OR)',
        'stickers.remove': 'Remove',
        'stickers.delete': 'Delete sticker',
        'stickers.save': 'Save',
        'stickers.create': 'Create sticker',
        'stickers.short_text': 'Short text',
        'stickers.alt': 'Image alternative text',
        'stickers.language': 'Content language',
        'promotions.select': 'Select…',
        'promotions.remove': 'Remove',
        'promotions.search_choices': 'Find products',
      },
    },
  };
});
const select = (label: string, option: string) => {
  fireEvent.click(screen.getByRole('combobox', { name: label }));
  fireEvent.click(screen.getByRole('option', { name: option }));
};
it('uses the configured default content language and keeps language inputs independent', () => {
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  expect(screen.getByRole('button', { name: 'Ελληνικά' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  fireEvent.change(screen.getByLabelText('Display text'), {
    target: { value: 'Νέο' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('Display text')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('Display text'), {
    target: { value: 'New' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Display text')).toHaveValue('Νέο');
});
it('selects and removes products and switches to live conditions without permanent assignments', () => {
  render(<Form {...props} />);
  expect(
    screen.queryByRole('combobox', { name: 'Products' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'Product Assignment' }));
  select('Products', 'Lamp · SKU-1');
  expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
  expect(
    screen.queryByRole('button', { name: 'Remove' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  select('Assignment type', 'Dynamic');
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  expect(
    screen.queryByRole('combobox', { name: 'Products' }),
  ).not.toBeInTheDocument();
  expect(screen.getByLabelText('Minimum')).toBeInTheDocument();
  select('Condition logic', 'Any (OR)');
  select('Add condition', 'In stock');
  fireEvent.click(screen.getByRole('button', { name: 'Add condition' }));
  expect(screen.getByRole('heading', { name: 'In stock' })).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: 'Remove' })).toHaveLength(2);
});
it('chooses category conditions in a dialog without losing range values or match logic', () => {
  render(
    <Form
      {...props}
      options={{ ...props.options, categories: [{ id: '3', name: 'Bakery' }] }}
    />,
  );
  select('Assignment type', 'Dynamic');
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  fireEvent.change(screen.getByLabelText('Minimum'), {
    target: { value: '10.50' },
  });
  fireEvent.change(screen.getByLabelText('Maximum'), {
    target: { value: '50' },
  });
  select('Condition logic', 'Any (OR)');
  select('Add condition', 'Category membership');
  fireEvent.click(screen.getByRole('button', { name: 'Add condition' }));
  fireEvent.click(screen.getByRole('button', { name: 'Category' }));
  expect(screen.getByRole('dialog', { name: 'Category' })).toBeInTheDocument();
  select('Category', 'Bakery');
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Bakery' })).toBeInTheDocument();
  expect(screen.getByLabelText('Minimum')).toHaveValue('10.50');
  expect(screen.getByLabelText('Maximum')).toHaveValue('50');
  expect(
    screen.getByRole('combobox', { name: 'Condition logic' }),
  ).toHaveTextContent('Any (OR)');
});

it('only shows image fields for image stickers', () => {
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  expect(screen.getByLabelText('Short text')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  select('Visual style', 'Image');
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  expect(screen.queryByLabelText('Short text')).not.toBeInTheDocument();
  expect(screen.getByLabelText('Image alternative text')).toBeInTheDocument();
  expect(screen.getByText(/PNG/)).toBeInTheDocument();
});
it('provides a real deletion dialog on the list and respects view-only access', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  const row = {
    id: '1',
    name: 'Sale badge',
    version: 4,
    type: 'manual',
    style: 'text',
    status: 'active',
    state: 'active',
    assigned_count: 2,
    starts_at: '',
    ends_at: '',
    priority: '10',
    updated_at: '',
    conditions: [],
  } as unknown as StickerDetail;
  render(
    <Index
      navigation={[]}
      breadcrumbs={[]}
      filters={{}}
      limits={{ version: 1, card_limit: 3, detail_limit: 5 }}
      stickers={{
        data: [row],
        current_page: 1,
        last_page: 1,
        total: 1,
        prev_page_url: null,
        next_page_url: null,
      }}
    />,
  );
  fireEvent.click(
    screen.getByRole('button', { name: 'Delete sticker: Sale badge' }),
  );
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: /delete/i,
    }),
  );
  expect(remove).toHaveBeenCalledWith(
    '/admin/stickers/1',
    expect.objectContaining({ data: { version: 4 } }),
  );
});
it('makes the form read-only without manage permission', () => {
  shared.props.capabilities['stickers.manage'] = false;
  render(<Form {...props} />);
  expect(
    screen.queryByRole('button', { name: 'Save' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  expect(screen.getByLabelText('Display text')).toBeDisabled();
});

const listProps = {
  navigation: [],
  breadcrumbs: [],
  filters: {},
  limits: { version: 1, card_limit: 3, detail_limit: 5 },
  stickers: {
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
  },
};

it('keeps display limits in a separate URL-persisted settings tab without losing edits', () => {
  render(<Index {...listProps} />);
  expect(screen.getByRole('table')).toBeInTheDocument();
  expect(
    screen.queryByLabelText('Maximum per product card'),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
  expect(window.location.search).toBe('?tab=settings');
  fireEvent.change(screen.getByLabelText('Maximum per product card'), {
    target: { value: '7' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Stickers' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
  expect(screen.getByLabelText('Maximum per product card')).toHaveValue(7);
});

it('opens settings directly and hides it from staff without manage permission', () => {
  window.history.replaceState(null, '', '/admin/stickers?tab=settings');
  const view = render(<Index {...listProps} />);
  expect(screen.getByRole('tab', { name: 'Settings' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  view.unmount();
  shared.props.capabilities['stickers.manage'] = false;
  render(<Index {...listProps} />);
  expect(
    screen.queryByRole('tab', { name: 'Settings' }),
  ).not.toBeInTheDocument();
  expect(screen.getByRole('table')).toBeInTheDocument();
  expect(
    screen.queryByLabelText('Maximum per product card'),
  ).not.toBeInTheDocument();
});

it('escapes untrusted display text in the admin appearance preview', () => {
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  fireEvent.change(screen.getByLabelText('Display text'), {
    target: { value: '<script>alert(1)</script>' },
  });
  expect(screen.getByLabelText('Preview')).toHaveTextContent(
    '<script>alert(1)</script>',
  );
  expect(screen.getByLabelText('Preview').querySelector('script')).toBeNull();
});

it('keeps selected assignments when switching editor tabs', () => {
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Product Assignment' }));
  select('Products', 'Lamp · SKU-1');
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  expect(
    screen.queryByRole('button', { name: 'Remove' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'Product Assignment' }));
  expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument();
});
