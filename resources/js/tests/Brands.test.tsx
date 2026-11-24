import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import BrandForm from '../pages/Admin/Brands/Form';
import BrandIndex from '../pages/Admin/Brands/Index';
import BlockDialog from '../pages/Admin/Layouts/BlockDialog';
import DefaultTheme from '../storefront/themes/Default';
import BrandSelect from '../components/admin/BrandSelect';
import type { BrandEditor } from '../types/brands';
import type { BlockDefinition, Placement } from '../types/layouts';
import type { SharedProps } from '../types/page';
import type { PublicBlock } from '../storefront/types';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/brands' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

it('keeps the product brand field compact and searches the server inside the dropdown', async () => {
  vi.useFakeTimers();
  try {
    const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
    const change = vi.fn();
    const view = render(
      <BrandSelect
        id="brand"
        value="1"
        options={[{ id: '1', name: 'Apple' }]}
        path="/admin/products/7/edit"
        onChange={change}
      />,
    );
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Brand' })).toHaveTextContent(
      'Apple',
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Brand' }));
    fireEvent.change(
      screen.getByRole('combobox', { name: 'Search brands by name or code' }),
      { target: { value: 'sam' } },
    );
    await act(() => vi.advanceTimersByTimeAsync(300));
    expect(get).toHaveBeenCalledWith(
      '/admin/products/7/edit',
      { kind: 'brands', search: 'sam', selected: ['1'] },
      expect.objectContaining({ only: ['options'], preserveState: true }),
    );
    view.rerender(
      <BrandSelect
        id="brand"
        value="1"
        options={[
          { id: '1', name: 'Apple' },
          { id: '2', name: 'Samsung' },
        ]}
        path="/admin/products/7/edit"
        onChange={change}
      />,
    );
    fireEvent.keyDown(
      screen.getByRole('combobox', { name: 'Search brands by name or code' }),
      { key: 'Enter' },
    );
    expect(change).toHaveBeenCalledWith('2');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  } finally {
    vi.useRealTimers();
  }
});
const brand: BrandEditor = {
  id: '1',
  name: 'Apple',
  code: 'apple',
  status: 'active',
  position: 0,
  products_count: 2,
  version: 3,
  image: null,
  banner_image: null,
  banner_link: '',
  translations: {
    en: {
      name: 'Apple',
      description: 'English description',
      image_alt: 'Apple',
      banner_alt: 'Apple summer banner',
    },
    el: {
      name: 'Apple EL',
      description: 'Ελληνική περιγραφή',
      image_alt: 'Σήμα',
      banner_alt: 'Καλοκαιρινό μπάνερ Apple',
    },
  },
};
const labels = {
  attachments: 'Attachments',
  search: 'Search brands by name or code',
  none: 'No brand',
  title: 'Brands',
  brand: 'Brand',
  create: 'New brand',
  back: 'Back to brands',
  general: 'General',
  image: 'Image',
  language: 'Content language',
  code: 'Code',
  position: 'Position',
  name: 'Name',
  description: 'Description',
  image_alt: 'Image alternative text',
  banner_image: 'Landing page banner',
  banner_link: 'Banner link (optional)',
  banner_alt: 'Banner alternative text',
  banner_language: 'Banner text language',
  delete_title: 'Delete :name',
  edit_title: 'Edit :name',
  toggle: 'Enable or disable :name',
  assigned_products: ':count assigned products',
  delete_used: 'Remove assignments or disable the brand.',
};
it('saves attachment edits with the brand while keeping display-only file metadata out of the request', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <BrandForm
      brand={{
        ...brand,
        files: [
          {
            id: '4',
            display_name: 'Sizes',
            original_name: 'sizes.pdf',
            byte_size: 1024,
            url: '/admin/brands/1/files/4',
          },
        ],
      }}
      navigation={[]}
      breadcrumbs={[]}
      defaultLocale="en"
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Attachments' }));
  fireEvent.change(screen.getByDisplayValue('Sizes'), {
    target: { value: 'Shoe size guide' },
  });
  const pdf = new File(['%PDF'], 'manual.pdf', { type: 'application/pdf' });
  fireEvent.change(
    screen.getByLabelText('Attachments', { selector: 'input' }),
    { target: { files: [pdf] } },
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = visit.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data.files).toEqual([{ id: '4', display_name: 'Shoe size guide' }]);
  expect(data.file_uploads).toEqual([pdf]);
  expect(data.files_submitted).toBe(true);
});
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: { user: null, canAccessAdmin: true },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: { 'brands.view': true, 'brands.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings: {
        ...english,
        ...Object.fromEntries(
          Object.entries(labels).map(([k, v]) => ['brands.' + k, v]),
        ),
        'layouts.general': 'General',
        'layouts.content': 'Content',
        'layouts.apply': 'Apply',
        'layouts.configure_block': 'Configure block',
        'layouts.template': 'Template',
        'layouts.block_name': 'Block name',
        'layouts.template_product_carousel': 'Product carousel',
        'layouts.source': 'Source',
        'layouts.source_manual': 'Manual',
        'layouts.source_bestsellers': 'Best sellers',
        'layouts.items': 'Products',
        'layouts.limit': 'Limit',
        'promotions.select': 'Select…',
        'promotions.search_choices': 'Search choices',
        'storefront.home': 'Home',
        'storefront.brand_bestsellers': 'Best sellers',
        'storefront.brand_ad': 'Ad',
        'storefront.brand_ad_tooltip':
          'This brand placement is a paid advertisement.',
      },
    },
  } as SharedProps;
});

it('keeps each language independent and submits brand content through the existing form contract', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <BrandForm
      brand={brand}
      defaultLocale="el"
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  expect(screen.getByLabelText('Name')).toHaveValue('Apple EL');
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Μήλο' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('Name')).toHaveValue('Apple');
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/brands/1',
    expect.objectContaining({
      version: 3,
      code: 'apple',
      translations: expect.objectContaining({
        en: expect.objectContaining({ name: 'Apple' }),
        el: expect.objectContaining({ name: 'Μήλο' }),
      }),
    }),
    expect.anything(),
  );
});

it('uses Delete, Edit, Switch order and preserves versions when changing status', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
  const row = { ...brand, image: null, updated_at: '2026-10-21 10:00' };
  render(
    <BrandIndex
      brands={{
        data: [row],
        current_page: 1,
        last_page: 1,
        total: 1,
        prev_page_url: null,
        next_page_url: null,
      }}
      filters={{}}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  const tr = screen.getByRole('row', { name: /Apple apple/ });
  const actionCell = within(tr).getAllByRole('cell').at(-1)!;
  const actions = Array.from(actionCell.querySelectorAll('button,a'));
  expect(actions[0]).toHaveAccessibleName('Delete Apple');
  expect(actions[1]).toHaveAccessibleName('Edit Apple');
  expect(actions[2]).toHaveRole('switch');
  fireEvent.click(actions[2]);
  expect(patch).toHaveBeenCalledWith(
    '/admin/brands/1/status',
    { version: 3, status: 'disabled' },
    expect.objectContaining({ preserveScroll: true }),
  );
  fireEvent.click(actions[0]);
  expect(screen.getByRole('alertdialog')).toBeInTheDocument();
});

it('changes the brand, clears incompatible manual products and scopes the existing product selector', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
  const apply = vi.fn();
  const placement: Placement = {
    id: 'p',
    grid_id: 'g',
    position: 1,
    enabled: true,
    mobile: true,
    tablet: true,
    desktop: true,
    block: {
      id: 'b',
      type: 'brand_products',
      name: 'Popular Apple',
      template: 'product_carousel',
      css_class: '',
      settings: { source: 'manual', limit: 8 },
      selections: { brand: ['1'], items: ['10'] },
      translations: {
        en: { title: '', html: '' },
        el: { title: '', html: '' },
      },
    },
  };
  const definition: BlockDefinition = {
    label: 'brands.brand',
    fields: [
      {
        key: 'brand',
        kind: 'selection',
        source: 'brands',
        single: true,
        label: 'brands.brand',
      },
      {
        key: 'source',
        kind: 'select',
        label: 'layouts.source',
        options: ['manual'],
      },
      {
        key: 'items',
        kind: 'selection',
        source: 'products',
        label: 'layouts.items',
      },
    ],
    defaults: { source: 'manual', limit: 8 },
    templates: ['product_carousel'],
    pages: ['homepage'],
    policy: { move: true, delete: true, configure: true, disable: true },
    canManage: true,
  };
  render(
    <BlockDialog
      placement={placement}
      definition={definition}
      grids={[
        {
          id: 'g',
          width: 16,
          parent_id: null,
          region: 'content',
          row: 1,
          position: 1,
        },
      ]}
      options={{
        brands: [
          { id: '1', name: 'Apple' },
          { id: '2', name: 'Samsung' },
        ],
        products: [
          { id: '10', name: 'iPhone', brand_id: '1' },
          { id: '20', name: 'Galaxy', brand_id: '2' },
        ],
      }}
      path="/admin/layouts/homepage"
      languages={shared.props.i18n.locales}
      defaultLocale="en"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  fireEvent.click(screen.getByRole('combobox', { name: 'Products' }));
  expect(
    screen.queryByRole('option', { name: 'Galaxy' }),
  ).not.toBeInTheDocument();
  fireEvent.keyDown(screen.getByRole('combobox', { name: 'Products' }), {
    key: 'Escape',
  });
  fireEvent.click(screen.getByRole('combobox', { name: 'Brand' }));
  fireEvent.click(screen.getByRole('option', { name: 'Samsung' }));
  expect(get).toHaveBeenCalledWith(
    '/admin/layouts/homepage',
    { kind: 'products', brand_id: '2', selected: [] },
    expect.objectContaining({ only: ['options'], preserveState: true }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[0][0].block.selections).toEqual({
    brand: ['2'],
    items: [],
  });
});

it('offers an optional brand for bestsellers while manual selection keeps brand logos', () => {
  const apply = vi.fn();
  const placement: Placement = {
    id: 'p',
    grid_id: 'g',
    position: 1,
    enabled: true,
    mobile: true,
    tablet: true,
    desktop: true,
    block: {
      id: 'b',
      type: 'brands',
      name: 'Brands',
      template: 'brand_carousel',
      css_class: '',
      settings: { source: 'manual', limit: 8 },
      selections: { items: ['2'] },
      translations: { en: { title: '', html: '' } },
    },
  };
  const definition: BlockDefinition = {
    label: 'brands.title',
    fields: [
      {
        key: 'source',
        kind: 'select',
        label: 'layouts.source',
        options: ['manual', 'bestsellers'],
      },
      {
        key: 'brand',
        kind: 'selection',
        source: 'brands',
        single: true,
        label: 'brands.brand',
        when: { source: 'bestsellers' },
      },
      {
        key: 'items',
        kind: 'selection',
        source: 'brands',
        label: 'brands.items',
      },
    ],
    defaults: { source: 'manual', limit: 8 },
    templates: ['brand_carousel'],
    pages: ['homepage'],
    policy: { move: true, delete: true, configure: true, disable: true },
    canManage: true,
  };
  render(
    <BlockDialog
      placement={placement}
      definition={definition}
      grids={[]}
      options={{
        brands: [
          { id: '1', name: 'Apple' },
          { id: '2', name: 'Samsung' },
        ],
      }}
      path="/admin/layouts/homepage"
      languages={shared.props.i18n.locales}
      defaultLocale="en"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(
    screen.queryByRole('combobox', { name: 'Brand' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('combobox', { name: 'Source' }));
  fireEvent.click(screen.getByRole('option', { name: 'Best sellers' }));
  expect(screen.getByRole('combobox', { name: 'Brand' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[0][0].block.selections.brand).toBeUndefined();
  fireEvent.click(screen.getByRole('combobox', { name: 'Brand' }));
  fireEvent.click(screen.getByRole('option', { name: 'Apple' }));
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[1][0].block.selections).toEqual({
    items: ['2'],
    brand: ['1'],
  });
});

it('renders brand carousels and the chosen brand heading above unchanged product cards', () => {
  const make = (
    id: string,
    template: string,
    content: PublicBlock['content'],
  ): PublicBlock => ({
    id,
    template,
    title: 'Best sellers',
    css_class: '',
    mobile: true,
    tablet: true,
    desktop: true,
    content,
  });
  const { container } = render(
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          content: [
            {
              id: 'g',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                make('brands', 'brand_carousel', {
                  items: [
                    {
                      id: '1',
                      name: 'Apple',
                      image_alt: 'Apple logo',
                      url: '/apple',
                      image: { url: '/apple.png', width: 100, height: 100 },
                    },
                  ],
                }),
                make('products', 'brand_carousel', {
                  paid_ad: true,
                  is_bestsellers: true,
                  brand: {
                    id: '1',
                    name: 'Apple',
                    image: null,
                    image_alt: 'Apple',
                    url: '/apple',
                  },
                  display: { show_add_to_cart: false, show_favorites: false },
                  items: [
                    {
                      id: '10',
                      name: 'iPhone',
                      image: null,
                      price: '100.00',
                      available: true,
                    },
                  ],
                }),
              ],
            },
          ],
        },
      }}
    />,
  );
  expect(
    container.querySelector('.sf-brand-items.sf-carousel'),
  ).toBeInTheDocument();
  expect(screen.getByAltText('Apple logo').closest('a')).toHaveAttribute(
    'href',
    '/apple',
  );
  expect(
    within(
      container.querySelector('.sf-brand-heading') as HTMLElement,
    ).getByRole('link', { name: 'Apple' }),
  ).toHaveAttribute('href', '/apple');
  expect(
    within(
      container.querySelector('.sf-brand-heading') as HTMLElement,
    ).getByRole('heading', { name: 'Apple' }),
  ).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'iPhone' })).toBeInTheDocument();
  expect(container.querySelector('.sf-brand-heading p')).toHaveTextContent(
    'Best sellers',
  );
  const ad = screen.getByRole('button', { name: 'Ad' });
  expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  fireEvent.focus(ad);
  expect(screen.getByRole('tooltip')).toHaveTextContent(
    'This brand placement is a paid advertisement.',
  );
  expect(ad).toHaveAccessibleDescription(
    'This brand placement is a paid advertisement.',
  );
  fireEvent.keyDown(ad, { key: 'Escape' });
  expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  fireEvent.mouseEnter(ad.parentElement!);
  expect(screen.getByRole('tooltip')).toBeVisible();
  fireEvent.mouseLeave(ad.parentElement!);
  expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
});

it('edits the banner link and per-language alternative text without changing logo text', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <BrandForm
      brand={brand}
      defaultLocale="en"
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Image' }));
  expect(screen.getByText('Landing page banner')).toBeVisible();
  fireEvent.change(screen.getByLabelText('Banner alternative text'), {
    target: { value: 'Apple offers' },
  });
  fireEvent.change(screen.getByLabelText('Banner link (optional)'), {
    target: { value: '/offers' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Banner alternative text')).toHaveValue(
    'Καλοκαιρινό μπάνερ Apple',
  );
  fireEvent.change(screen.getByLabelText('Banner alternative text'), {
    target: { value: 'Προσφορές Apple' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/brands/1',
    expect.objectContaining({
      banner_link: '/offers',
      remove_banner_image: false,
      translations: {
        en: expect.objectContaining({
          banner_alt: 'Apple offers',
          image_alt: 'Apple',
        }),
        el: expect.objectContaining({
          banner_alt: 'Προσφορές Apple',
          image_alt: 'Σήμα',
        }),
      },
    }),
    expect.anything(),
  );
});
