import { choose } from './select';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import LayoutEditor from '../pages/Admin/Layouts/Edit';
import BlockDialog from '../pages/Admin/Layouts/BlockDialog';
import DefaultTheme from '../storefront/themes/Default';
import type { BlockDefinition, Layout, Placement } from '../types/layouts';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/layouts/homepage' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const pageSettings = {
  version: 1,
  custom: false,
  page_id: null,
  position: 0,
  indexable: true,
  custom_html: '',
  translations: {
    en: { name: '', page_title: '', meta_description: '', meta_keywords: '' },
  },
};
const words = {
  collapse_pages: 'Collapse layout pages',
  expand_pages: 'Expand layout pages',
  page_settings: 'Layout page settings',
  new_layout: 'New layout page',
  add_layout: 'Add layout page…',
  save_settings: 'Save settings',
  create_layout: 'Create',
  page_title: 'Page title',
  meta_keywords: 'Meta keywords',
  copy_to_others: 'Copy to other layouts',
  custom_html: 'Custom HTML',
  linked_page: 'Linked page',
  layout_name: 'Name',
  page_none: 'Not linked to a page',
  indexable: 'Allow search engines to index this page',
  drag_grid: 'Move grid :name (drag or use arrow keys)',
  drag_block: 'Move :name (drag or use arrow keys)',
  create_new: 'Create new block',
  add_help: 'Choose content and presentation',
  find_block: 'Find a block',
  use_existing: 'Use existing block',
  template_product_carousel: 'Carousel',
  title: 'Layouts',
  default: 'Default',
  homepage: 'Homepage',
  save: 'Save layout',
  general: 'General',
  content: 'Content',
  configure_block: 'Configure block',
  block_name: 'Block name',
  display_title: 'Display title',
  template: 'Template',
  css_class: 'CSS class',
  grid: 'Grid',
  visibility: 'Visibility',
  enabled: 'Enabled',
  disabled: 'Disabled',
  mobile: 'Mobile',
  tablet: 'Tablet',
  desktop: 'Desktop',
  apply: 'Apply',
  source: 'Source',
  items: 'Products',
  limit: 'Maximum items',
  type_products: 'Products',
  source_manual: 'Manual',
  source_newest: 'Newest',
  filter_categories: 'Only from categories',
  template_product_single: 'Single product',
  delete_block: 'Delete block',
  duplicate: 'Duplicate',
  move_up: 'Move up',
  move_down: 'Move down',
  edit_grid: 'Edit grid',
  delete_grid: 'Delete grid',
  add_grid: 'Add grid',
  add_block: 'Add block',
  block_type: 'Block type',
  region_content: 'Main content',
  position: 'Position',
  row: 'Row',
  width: 'Columns',
  parent: 'Parent',
  root: 'Region root',
};
const definition: BlockDefinition = {
  label: 'layouts.type_products',
  fields: [
    {
      key: 'source',
      kind: 'select',
      label: 'layouts.source',
      options: ['manual', 'newest'],
    },
    {
      key: 'items',
      kind: 'selection',
      label: 'layouts.items',
      source: 'products',
    },
    { key: 'limit', kind: 'number', label: 'layouts.limit', min: 1, max: 48 },
  ],
  defaults: { source: 'newest', limit: 8 },
  templates: ['product_single', 'product_carousel'],
  pages: ['homepage', 'default'],
  policy: { configure: true, move: true, disable: true, delete: true },
  canManage: true,
};
const placement: Placement = {
  id: 'placement-one',
  grid_id: 'grid-one',
  position: 1,
  enabled: true,
  mobile: true,
  tablet: true,
  desktop: true,
  block: {
    id: 'block-one',
    type: 'products',
    name: 'New arrivals',
    template: 'product_single',
    css_class: '',
    settings: { source: 'manual', limit: 8 },
    selections: { items: ['1', '2'] },
    translations: {
      en: { title: 'New arrivals', html: '' },
      el: { title: 'Νέα προϊόντα', html: '' },
    },
  },
};
const layout: Layout = {
  id: '1',
  page_type: 'homepage',
  version: 1,
  grids: [
    {
      id: 'grid-one',
      region: 'content',
      parent_id: null,
      width: 16,
      row: 1,
      position: 1,
    },
  ],
  placements: [placement],
  overrides: [],
};
const options = {
  products: [
    { id: '1', name: 'Lamp' },
    { id: '2', name: 'Chair' },
  ],
};
const languages = [
  { code: 'en', name: 'English' },
  { code: 'el', name: 'Ελληνικά' },
];
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'Ambre' },
    auth: { user: null, canAccessAdmin: true },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: { 'layouts.view': true, 'layouts.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: languages,
      strings: {
        ...english,
        ...Object.fromEntries(
          Object.entries(words).map(([key, value]) => [
            `layouts.${key}`,
            value,
          ]),
        ),
        'common.cancel': 'Cancel',
        'promotions.select': 'Select…',
        'promotions.remove': 'Remove',
        'promotions.search_choices': 'Search products',
        'storefront.account': 'Account',
        'storefront.available': 'Available',
        'storefront.home': 'Home',
        'storefront.back_to_top': 'Back to top',
      },
    },
  };
});
const select = (label: string, option: string) => {
  fireEvent.click(screen.getByRole('combobox', { name: label }));
  fireEvent.click(screen.getByRole('option', { name: option }));
};
it('preserves ordered selections when changing template and source, and opens the configured content language', () => {
  const apply = vi.fn();
  render(
    <BlockDialog
      placement={placement}
      definition={definition}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/homepage"
      languages={languages}
      defaultLocale="el"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  select('Template', 'Carousel');
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.getByRole('button', { name: 'Ελληνικά' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  expect(screen.getByLabelText('Display title')).toHaveValue('Νέα προϊόντα');
  fireEvent.click(screen.getAllByRole('button', { name: 'Move down' })[0]);
  select('Source', 'Newest');
  expect(
    screen.queryByRole('combobox', { name: 'Products' }),
  ).not.toBeInTheDocument();
  select('Source', 'Manual');
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  const saved = apply.mock.calls[0][0] as Placement;
  expect(saved.block.template).toBe('product_carousel');
  expect(saved.block.selections.items).toEqual(['2', '1']);
  expect(saved.block.translations.en.title).toBe('New arrivals');
});
it('offers the category filter only for automatic product sources and keeps its choice', () => {
  Object.assign(shared.props.i18n.strings, {
    'layouts.source_recently_viewed': 'Recently viewed',
  });
  const apply = vi.fn();
  const filtered: BlockDefinition = {
    ...definition,
    fields: [
      {
        ...definition.fields[0],
        options: ['manual', 'newest', 'recently_viewed'],
      },
      definition.fields[1],
      {
        key: 'filter_categories',
        kind: 'selection',
        label: 'layouts.filter_categories',
        source: 'categories',
      },
      ...definition.fields.slice(2),
    ],
  };
  render(
    <BlockDialog
      placement={{
        ...placement,
        block: {
          ...placement.block,
          selections: { items: ['1'], filter_categories: ['7'] },
        },
      }}
      definition={filtered}
      grids={layout.grids}
      options={{ ...options, categories: [{ id: '7', name: 'Smartphones' }] }}
      path="/admin/layouts/homepage"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.queryByText('Only from categories')).not.toBeInTheDocument();
  select('Source', 'Newest');
  expect(screen.getByText('Only from categories')).toBeInTheDocument();
  select('Source', 'Recently viewed');
  expect(screen.getByText('Only from categories')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  const saved = apply.mock.calls[0][0] as Placement;
  expect(saved.block.settings.source).toBe('recently_viewed');
  expect(saved.block.selections.filter_categories).toEqual(['7']);
});

it('stages duplicate and visibility changes and submits the complete persisted-layout contract', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <LayoutEditor
      layout={structuredClone(layout)}
      sharedLayout={null}
      definitions={{ products: definition }}
      tabs={[
        {
          key: 'homepage',
          label: 'layouts.homepage',
          translate: true,
          page_id: null,
          custom: false,
        },
        {
          key: 'default',
          label: 'layouts.default',
          translate: true,
          page_id: null,
          custom: false,
        },
      ]}
      pageDefinition={{ regions: ['content'], custom: false }}
      pageKind="homepage"
      pageSettings={pageSettings}
      pageOptions={[]}
      theme={{ name: 'Default' }}
      defaultLocale="en"
      options={options}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  expect(
    within(screen.getByRole('navigation', { name: 'Layouts' })).getAllByRole(
      'link',
    ),
  ).toHaveLength(2);
  expect(
    screen.queryByRole('button', { name: 'Collapse layout pages' }),
  ).toBeNull();
  const layoutLinks = within(
    screen.getByRole('navigation', { name: 'Layouts' }),
  ).getAllByRole('link');
  expect(layoutLinks.every((link) => !link.querySelector('svg'))).toBe(true);
  expect(screen.getByRole('link', { name: 'Homepage' })).toHaveAttribute(
    'aria-current',
    'page',
  );
  expect(
    screen.getByRole('button', { name: 'Layout page settings' }),
  ).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));
  expect(screen.getAllByRole('switch')).toHaveLength(2);
  fireEvent.click(screen.getAllByRole('switch')[1]);
  fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
  const data = put.mock.calls[0][1] as unknown as {
    placements: Placement[];
    grids: Layout['grids'];
    version: number;
  };
  expect(data.version).toBe(1);
  expect(data.placements).toHaveLength(2);
  expect(data.placements[1].block.id).not.toBe(data.placements[0].block.id);
  expect(data.placements[1].enabled).toBe(false);
  expect(data.grids[0].width).toBe(16);
});
it('renders nested public grids, safe text, responsive flags and the configured template', () => {
  const { container } = render(
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          content: [
            {
              id: 'outer',
              width: 16,
              row: 1,
              blocks: [],
              children: [
                {
                  id: 'inner',
                  width: 8,
                  row: 1,
                  children: [],
                  blocks: [
                    {
                      id: 'public',
                      template: 'product_carousel',
                      title: '<script>plain text</script>',
                      css_class: 'merchant-section',
                      mobile: false,
                      tablet: true,
                      desktop: true,
                      content: {
                        items: [
                          {
                            id: '1',
                            name: 'Lamp',
                            price: '75.00',
                            base_price: '100.00',
                            currency: 'EUR',
                            available: true,
                            image: null,
                          },
                        ],
                      },
                    },
                  ],
                },
              ],
            },
          ],
        },
      }}
    />,
  );
  const block = container.querySelector('.sf-block')!;
  expect(block).toHaveClass('sf-hide-mobile', 'merchant-section');
  expect(container.querySelectorAll('.sf-grid')).toHaveLength(2);
  expect(container.querySelector('.sf-carousel')).toBeInTheDocument();
  expect(container.querySelector('script')).not.toBeInTheDocument();
  expect(within(block as HTMLElement).getByText('Lamp')).toBeInTheDocument();
  expect(screen.getByLabelText('€75.00')).toBeInTheDocument();
  expect(container.querySelector('.sf-no-image')).toBeInTheDocument();
});

it('drags a block between grids and reorders grid rows with the existing native drag interaction', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  const data = structuredClone(layout);
  data.grids.push({ ...data.grids[0], id: 'grid-two', row: 2 });
  const { container } = render(
    <LayoutEditor
      layout={data}
      sharedLayout={null}
      definitions={{ products: definition }}
      tabs={[
        {
          key: 'homepage',
          label: 'layouts.homepage',
          translate: true,
          page_id: null,
          custom: false,
        },
      ]}
      pageDefinition={{ regions: ['content'], custom: false }}
      pageKind="homepage"
      pageSettings={pageSettings}
      pageOptions={[]}
      theme={{ name: 'Default' }}
      defaultLocale="en"
      options={options}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  const transfer = { setData: vi.fn(), effectAllowed: '', dropEffect: '' };
  const card = container.querySelector('[data-placement-id="placement-one"]')!;
  const second = container.querySelector('[data-grid-id="grid-two"]')!;
  fireEvent.dragStart(card, { dataTransfer: transfer });
  fireEvent.dragOver(second, { dataTransfer: transfer });
  fireEvent.drop(second, { dataTransfer: transfer });
  expect(
    second.querySelector('[data-placement-id="placement-one"]'),
  ).toBeInTheDocument();
  fireEvent.dragStart(second, { dataTransfer: transfer });
  const first = container.querySelector('[data-grid-id="grid-one"]')!;
  fireEvent.dragOver(first, { dataTransfer: transfer });
  fireEvent.drop(first, { dataTransfer: transfer });
  fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
  const submitted = put.mock.calls[0][1] as unknown as {
    placements: Placement[];
    grids: Layout['grids'];
  };
  expect(submitted.placements[0].grid_id).toBe('grid-two');
  expect(submitted.grids.find((g) => g.id === 'grid-two')?.row).toBe(1);
  expect(submitted.grids.find((g) => g.id === 'grid-one')?.row).toBe(2);
  expect(transfer.setData).toHaveBeenCalledWith('text/plain', 'placement-one');
});
it('supports arrow-key reordering from the drag grip', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  const data = structuredClone(layout);
  const second = structuredClone(placement);
  second.id = 'second';
  second.block.id = 'second-block';
  second.block.name = 'Second';
  second.position = 2;
  data.placements.push(second);
  render(
    <LayoutEditor
      layout={data}
      sharedLayout={null}
      definitions={{ products: definition }}
      tabs={[
        {
          key: 'homepage',
          label: 'layouts.homepage',
          translate: true,
          page_id: null,
          custom: false,
        },
      ]}
      pageDefinition={{ regions: ['content'], custom: false }}
      pageKind="homepage"
      pageSettings={pageSettings}
      pageOptions={[]}
      theme={{ name: 'Default' }}
      defaultLocale="en"
      options={options}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.keyDown(
    screen.getByRole('button', {
      name: 'Move Second (drag or use arrow keys)',
    }),
    { key: 'ArrowUp' },
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
  const submitted = put.mock.calls[0][1] as unknown as {
    placements: Placement[];
  };
  expect(submitted.placements.map((p) => p.id)).toEqual([
    'second',
    'placement-one',
  ]);
});

it('allows removing an unavailable extension placement without breaking the editor', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <LayoutEditor
      layout={structuredClone(layout)}
      sharedLayout={null}
      definitions={{}}
      tabs={[
        {
          key: 'homepage',
          label: 'layouts.homepage',
          translate: true,
          page_id: null,
          custom: false,
        },
      ]}
      pageDefinition={{ regions: ['content'], custom: false }}
      pageKind="homepage"
      pageSettings={pageSettings}
      pageOptions={[]}
      theme={{ name: 'Default' }}
      defaultLocale="en"
      options={options}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Delete block' }));
  fireEvent.submit(screen.getByRole('alertdialog'));
  fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
  expect(
    (put.mock.calls[0][1] as unknown as { placements: Placement[] }).placements,
  ).toEqual([]);
});

it('opens a create/existing chooser and explicitly selects a single-item presentation', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <LayoutEditor
      layout={structuredClone(layout)}
      sharedLayout={null}
      definitions={{ products: definition }}
      tabs={[
        {
          key: 'homepage',
          label: 'layouts.homepage',
          translate: true,
          page_id: null,
          custom: false,
        },
      ]}
      pageDefinition={{ regions: ['content'], custom: false }}
      pageKind="homepage"
      pageSettings={pageSettings}
      pageOptions={[]}
      theme={{ name: 'Default' }}
      defaultLocale="en"
      options={options}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  expect(
    screen.queryByRole('combobox', { name: 'Block type' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /Add block/ }));
  expect(screen.getByRole('tab', { name: 'Create new block' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  expect(
    screen.getByRole('tab', { name: 'Use existing block' }),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Single product' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.queryByLabelText('Maximum items')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
  const submitted = put.mock.calls[0][1] as unknown as {
    placements: Placement[];
  };
  expect(submitted.placements[1].block.template).toBe('product_single');
});

const editorProps = {
  layout: structuredClone(layout),
  sharedLayout: null,
  definitions: { products: definition },
  tabs: [
    {
      key: 'homepage',
      label: 'layouts.homepage',
      translate: true,
      page_id: null,
      custom: false,
    },
    {
      key: 'custom.abc',
      label: 'Gift cards',
      translate: false,
      page_id: '7',
      custom: true,
    },
  ],
  pageDefinition: { regions: ['content'], custom: false },
  pageKind: 'homepage',
  pageSettings,
  pageOptions: [{ id: '7', name: 'Delivery', depth: 0, layout: 'custom.abc' }],
  theme: { name: 'Default' },
  defaultLocale: 'en',
  options,
  navigation: [],
  breadcrumbs: [],
};
it('lists merchant-added layouts as tabs and saves the page settings with the copy choices', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<LayoutEditor {...editorProps} />);
  expect(screen.getByRole('link', { name: 'Gift cards' })).toHaveAttribute(
    'href',
    '/admin/layouts/custom.abc',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Layout page settings' }));
  fireEvent.change(screen.getByLabelText('Page title'), {
    target: { value: 'Welcome' },
  });
  fireEvent.change(screen.getByLabelText('Meta keywords', { exact: false }), {
    target: { value: 'shop, gifts' },
  });
  fireEvent.click(screen.getAllByLabelText('Copy to other layouts')[0]);
  fireEvent.click(
    screen.getByLabelText('Allow search engines to index this page'),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(put).toHaveBeenCalledWith(
    '/admin/layouts/homepage/settings',
    expect.objectContaining({
      version: 1,
      custom_html: '',
      copy_meta_keywords: true,
      copy_custom_html: false,
      translations: expect.objectContaining({
        en: expect.objectContaining({
          page_title: 'Welcome',
          meta_keywords: 'shop, gifts',
        }),
      }),
    }),
    expect.any(Object),
  );
  expect(screen.queryByLabelText('Linked page')).not.toBeInTheDocument();
});
it('adds a layout with a name and a linked page', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<LayoutEditor {...editorProps} />);
  fireEvent.click(screen.getByRole('button', { name: 'Add layout page…' }));
  expect(
    screen.getByRole('combobox', { name: 'Linked page' }),
  ).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Name', { selector: 'input' }), {
    target: { value: 'Delivery' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Create' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/layouts',
    expect.objectContaining({
      page_id: null,
      translations: expect.objectContaining({
        en: expect.objectContaining({ name: 'Delivery' }),
      }),
    }),
    expect.any(Object),
  );
});

it('marks the storefront for blended product pictures only when the setting is on', () => {
  const { container, rerender } = render(
    <DefaultTheme
      page={{ theme: 'default', regions: {}, blend_images: true }}
    />,
  );
  expect(container.querySelector('.sf-default')).toHaveClass('sf-blend');
  rerender(
    <DefaultTheme
      page={{ theme: 'default', regions: {}, blend_images: false }}
    />,
  );
  expect(container.querySelector('.sf-default')).not.toHaveClass('sf-blend');
});

it('shows product cards in the showcase style when the general setting asks for it', () => {
  const product = (card_style: 'classic' | 'showcase' | 'signature') => (
    <DefaultTheme
      page={{
        theme: 'default',
        card_style,
        regions: {
          content: [
            {
              id: 'grid',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'cards',
                  template: 'product_carousel',
                  title: '',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: {
                    items: [
                      {
                        id: '1',
                        name: 'Lamp',
                        price: '75.00',
                        base_price: '100.00',
                        discount_percent: 25,
                        currency: 'EUR',
                        available: true,
                        image: null,
                      },
                    ],
                  },
                },
              ],
            },
          ],
        },
      }}
    />
  );
  const { container, rerender } = render(product('classic'));
  expect(container.querySelector('.sf-product-showcase')).toBeNull();
  expect(container.querySelector('.sf-cart')).not.toBeNull();
  rerender(product('showcase'));
  const card = container.querySelector('.sf-product-showcase')!;
  expect(card).not.toBeNull();
  expect(card.querySelector('.sf-showcase-cart')).not.toBeNull();
  expect(card.querySelector('.sf-showcase-badge')).toHaveTextContent('-25%');
  expect(card.querySelector('del')).toHaveTextContent('€100.00');
  // Both styles show the heart and the shadow unless the product block switches them off.
  expect(card.querySelector('.sf-card-heart')).not.toBeNull();
  expect(card).not.toHaveClass('sf-no-shadow');
  rerender(product('signature'));
  const signature = container.querySelector('.sf-product-signature')!;
  expect(signature).not.toBeNull();
  expect(signature.querySelector('.sf-showcase-cart')).toBeNull();
  expect(
    signature.querySelector('.sf-signature-cart .sf-card-cart-label'),
  ).not.toBeNull();
  expect(signature.querySelector('.sf-showcase-badge')).toHaveTextContent(
    '-25%',
  );
  expect(signature.querySelector('.sf-showcase-prices')?.textContent).toMatch(
    /75.*25.*100/,
  );
});

it('hides the heart and the card shadow when the product block says so, in both styles', () => {
  const page = (card_style: 'classic' | 'showcase' | 'signature') => (
    <DefaultTheme
      page={{
        theme: 'default',
        card_style,
        regions: {
          content: [
            {
              id: 'grid',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'cards',
                  template: 'product_carousel',
                  title: '',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: {
                    display: { show_favorites: false, card_shadow: false },
                    items: [
                      {
                        id: '1',
                        name: 'Lamp',
                        price: '75.00',
                        available: true,
                        image: null,
                      },
                    ],
                  },
                },
              ],
            },
          ],
        },
      }}
    />
  );
  for (const style of ['classic', 'showcase', 'signature'] as const) {
    const { container, unmount } = render(page(style));
    expect(container.querySelector('.sf-card-heart')).toBeNull();
    expect(container.querySelector('.sf-product')).toHaveClass('sf-no-shadow');
    unmount();
  }
});

it('shows stars and the review count under the name only when the product block asks for them', () => {
  const page = (
    show_rating: boolean,
    rating: { average: string; count: number } | null,
  ) => (
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          content: [
            {
              id: 'grid',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'cards',
                  template: 'product_carousel',
                  title: '',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: {
                    display: { show_rating },
                    items: [
                      {
                        id: '1',
                        name: 'Lamp',
                        price: '75.00',
                        available: true,
                        image: null,
                        rating,
                      },
                      {
                        id: '2',
                        name: 'Desk',
                        price: '90.00',
                        available: true,
                        image: null,
                        rating: null,
                      },
                    ],
                  },
                },
              ],
            },
          ],
        },
      }}
    />
  );
  const { container, rerender } = render(
    page(false, { average: '4.5', count: 12 }),
  );
  expect(container.querySelector('.sf-card-rating')).toBeNull();
  rerender(page(true, { average: '4.5', count: 12 }));
  // Only products with reviews show the row.
  expect(container.querySelectorAll('.sf-card-rating')).toHaveLength(1);
  expect(container.querySelector('.sf-card-rating')).toHaveTextContent('(12)');
  expect(
    container.querySelectorAll('.sf-card-rating .sf-stars svg'),
  ).toHaveLength(5);
});

it('colors the header icons only when the identity block sets an icon color', () => {
  const page = (icon_color: string) => (
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          header: [
            {
              id: 'grid',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'identity',
                  template: 'identity',
                  title: '',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: {
                    name: 'Shop',
                    display: { show_cart: true, icon_color },
                  },
                },
              ],
            },
          ],
        },
      }}
    />
  );
  const { container, rerender } = render(page(''));
  expect(
    container.querySelector('.sf-block')!.getAttribute('style') ?? '',
  ).not.toContain('--sf-icon-color');
  rerender(page('#ffd54f'));
  expect(container.querySelector('.sf-block')!.getAttribute('style')).toContain(
    '--sf-icon-color: #ffd54f',
  );
});

it('sets the saved site width independently of optional theme colors', () => {
  const { container, rerender } = render(
    <DefaultTheme page={{ theme: 'default', regions: {}, max_width: 1600 }} />,
  );
  expect(container.querySelector('.sf-default')).toHaveStyle(
    '--sf-max-width: 1600px',
  );
  rerender(<DefaultTheme page={{ theme: 'default', regions: {} }} />);
  expect(container.querySelector('.sf-default')).toHaveStyle(
    '--sf-max-width: 1320px',
  );
});

it.each(['category_single', 'category_carousel'])(
  'toggles category shadows in %s without changing the tile content',
  (template) => {
    const page = (shadow?: boolean) => (
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
                  {
                    id: 'c',
                    template,
                    title: 'Categories',
                    css_class: '',
                    mobile: true,
                    tablet: true,
                    desktop: true,
                    content: {
                      display: { card_shadow: shadow },
                      items: [
                        {
                          id: '1',
                          name: 'Computers',
                          url: '/computers',
                          image: null,
                        },
                      ],
                    },
                  },
                ],
              },
            ],
          },
        }}
      />
    );
    const { container, rerender } = render(page());
    expect(container.querySelector('.sf-category')).not.toHaveClass(
      'sf-card-shadow',
    );
    rerender(page(true));
    expect(container.querySelector('.sf-category')).toHaveClass(
      'sf-card-shadow',
    );
    expect(screen.getByRole('link', { name: 'Computers' })).toHaveAttribute(
      'href',
      '/computers',
    );
    rerender(page(false));
    expect(container.querySelector('.sf-category')).not.toHaveClass(
      'sf-card-shadow',
    );
  },
);

it('shows Back to top after scrolling, respects reduced motion, and follows the saved setting', () => {
  const scrollTo = vi.fn();
  const matchMedia = vi.fn().mockReturnValue({ matches: false });
  vi.stubGlobal('scrollTo', scrollTo);
  vi.stubGlobal('matchMedia', matchMedia);
  vi.stubGlobal('scrollY', 0);
  try {
    const { rerender } = render(
      <DefaultTheme page={{ theme: 'default', regions: {} }} />,
    );
    expect(
      screen.queryByRole('button', { name: 'Back to top' }),
    ).not.toBeInTheDocument();
    vi.stubGlobal('scrollY', 500);
    fireEvent.scroll(window);
    fireEvent.click(screen.getByRole('button', { name: 'Back to top' }));
    expect(scrollTo).toHaveBeenLastCalledWith({ top: 0, behavior: 'smooth' });
    matchMedia.mockReturnValue({ matches: true });
    fireEvent.click(screen.getByRole('button', { name: 'Back to top' }));
    expect(scrollTo).toHaveBeenLastCalledWith({ top: 0, behavior: 'instant' });
    rerender(
      <DefaultTheme
        page={{ theme: 'default', regions: {}, show_back_to_top: false }}
      />,
    );
    expect(
      screen.queryByRole('button', { name: 'Back to top' }),
    ).not.toBeInTheDocument();
    rerender(
      <DefaultTheme
        page={{ theme: 'default', regions: {}, show_back_to_top: true }}
      />,
    );
    expect(
      screen.getByRole('button', { name: 'Back to top' }),
    ).toBeInTheDocument();
    vi.stubGlobal('scrollY', 0);
    fireEvent.scroll(window);
    expect(
      screen.queryByRole('button', { name: 'Back to top' }),
    ).not.toBeInTheDocument();
  } finally {
    vi.unstubAllGlobals();
  }
});
it('shows the defaults of settings a block was saved without, and keeps saved choices', () => {
  shared.props.i18n.strings['layouts.show_shopping'] = 'Shopping options';
  shared.props.i18n.strings['layouts.show_brand'] = 'Show brand';
  const flags: BlockDefinition = {
    ...definition,
    fields: [
      ...definition.fields,
      {
        key: 'show_shopping',
        kind: 'boolean',
        label: 'layouts.show_shopping',
      },
      { key: 'show_brand', kind: 'boolean', label: 'layouts.show_brand' },
    ],
    defaults: {
      ...definition.defaults,
      show_shopping: true,
      show_brand: true,
    },
  };
  const apply = vi.fn();
  render(
    <BlockDialog
      placement={{
        ...placement,
        block: {
          ...placement.block,
          settings: { source: 'manual', limit: 8, show_brand: false },
        },
      }}
      definition={flags}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/homepage"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  for (const tab of ['General', 'Content']) {
    fireEvent.click(screen.getByRole('tab', { name: tab }));
    if (screen.queryByRole('checkbox', { name: 'Shopping options' })) break;
  }
  // Saved before the setting existed: its default (on), as the storefront applies it.
  expect(
    screen.getByRole('checkbox', { name: 'Shopping options' }),
  ).toBeChecked();
  // Saved off: stays off.
  expect(
    screen.getByRole('checkbox', { name: 'Show brand' }),
  ).not.toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect((apply.mock.calls[0][0] as Placement).block.settings).toMatchObject({
    show_shopping: true,
    show_brand: false,
  });
});

it('shows fashion header settings and applies drag and keyboard icon ordering', () => {
  Object.assign(shared.props.i18n.strings, {
    'layouts.icon_order_handle': 'Move :name',
    'layouts.identity_options': 'Options',
    Fashion: 'Fashion',
    Classic: 'Classic',
    'Header style': 'Header style',
    'layouts.template_menu': 'Menu',
    'Show logo': 'Show logo',
    'Icon order': 'Icon order',
    ...Object.fromEntries(
      ['language', 'search', 'account', 'favorites', 'cart'].map((key) => [
        key,
        key,
      ]),
    ),
  });
  const apply = vi.fn();
  const data = structuredClone(placement);
  data.block.type = 'identity';
  data.block.template = 'identity';
  const menuDefinition: BlockDefinition = {
    ...definition,
    templates: ['identity'],
    defaults: {
      header_style: 'classic',
      show_logo: true,
      icon_order: ['language', 'search', 'account', 'favorites', 'cart'],
    },
    fields: [
      {
        key: 'header_style',
        kind: 'select',
        label: 'Header style',
        options: ['classic', 'fashion'],
        option_labels: { classic: 'Classic', fashion: 'Fashion' },
      },
      {
        key: 'show_logo',
        tab: 'identity_options',
        kind: 'boolean',
        label: 'Show logo',
        when: { header_style: ['compact', 'fashion'] },
      },
      {
        key: 'icon_order',
        kind: 'order',
        label: 'Icon order',
        when: { header_style: ['compact', 'fashion'] },
        options: ['language', 'search', 'account', 'favorites', 'cart'],
      },
    ],
  };
  render(
    <BlockDialog
      placement={data}
      definition={menuDefinition}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/default"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={() => undefined}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.queryByRole('checkbox', { name: 'Show logo' })).toBeNull();
  choose(screen.getByRole('combobox', { name: 'Header style' }), 'Fashion');
  expect(screen.queryByRole('checkbox', { name: 'Show logo' })).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Options' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'Show logo' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  fireEvent.keyDown(screen.getByRole('button', { name: 'Move cart' }), {
    key: 'ArrowUp',
  });
  const dragData = { effectAllowed: '', setData: vi.fn() };
  fireEvent.dragStart(
    screen.getByRole('button', { name: 'Move search' }).closest('li')!,
    { dataTransfer: dragData },
  );
  fireEvent.drop(
    screen.getByRole('button', { name: 'Move language' }).closest('li')!,
    { dataTransfer: dragData },
  );
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[0][0].block.settings).toMatchObject({
    show_logo: false,
    icon_order: ['search', 'language', 'account', 'cart', 'favorites'],
  });
});

it('edits Fashion mobile links in their own tab and preserves each language', () => {
  Object.assign(shared.props.i18n.strings, {
    'layouts.mobile_links': 'Mobile menu links',
    'layouts.mobile_links_title': 'Section title',
    'layouts.mobile_link_label': 'Link :number label',
    'layouts.mobile_link_url': 'Link :number URL',
    'layouts.mobile_link_add': 'Add link',
    'layouts.mobile_link_remove': 'Remove link :number',
    'layouts.mobile_link_move': 'Move link :number',
  });
  const data = structuredClone(placement);
  data.block.type = 'identity';
  data.block.template = 'identity';
  data.block.settings.header_style = 'fashion';
  data.block.translations.en = {
    title: '',
    html: '',
    mobile_links: {
      title: 'My account',
      items: [
        { label: 'Account', url: '/account', new_window: false },
        { label: 'Cart', url: '/cart', new_window: false },
      ],
    },
  };
  data.block.translations.el = {
    title: '',
    html: '',
    mobile_links: {
      title: 'Λογαριασμός',
      items: [{ label: 'Καλάθι', url: '/cart', new_window: false }],
    },
  };
  const apply = vi.fn();
  render(
    <BlockDialog
      placement={data}
      definition={{ ...definition, templates: ['identity'] }}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/default"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={() => undefined}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Mobile menu links' }));
  fireEvent.click(screen.getByRole('button', { name: 'Remove link 1' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add link' }));
  fireEvent.change(screen.getByLabelText('Link 2 label'), {
    target: { value: 'Size guide' },
  });
  fireEvent.change(screen.getByLabelText('Link 2 URL'), {
    target: { value: '/sizes' },
  });
  fireEvent.keyDown(screen.getByRole('button', { name: 'Move link 2' }), {
    key: 'ArrowUp',
  });
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Section title')).toHaveValue('Λογαριασμός');
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  const saved = apply.mock.calls[0][0].block.translations;
  expect(
    saved.en.mobile_links.items.map((link: { url: string }) => link.url),
  ).toEqual(['/sizes', '/cart']);
  expect(saved.el.mobile_links.items).toEqual([
    { label: 'Καλάθι', url: '/cart', new_window: false },
  ]);
});

it('orders product sections with inline visibility and keeps fixed options in their own tab', () => {
  Object.assign(shared.props.i18n.strings, {
    'layouts.icon_order_handle': 'Move :name',
    'layouts.product_options': 'Options',
    Similar: 'Similar',
    Reviews: 'Reviews',
    Questions: 'Questions',
    Sections: 'Sections',
    Newest: 'Newest',
    'layouts.newest_same_category': 'Same category',
  });
  const apply = vi.fn();
  const data = structuredClone(placement);
  data.block.type = 'main_content';
  data.block.template = 'main_content';
  data.block.settings = { show_reviews: false };
  const productDefinition: BlockDefinition = {
    ...definition,
    defaults: {
      section_order: ['show_similar', 'show_reviews', 'show_newest'],
      show_similar: true,
      show_reviews: true,
      show_faqs: true,
      newest_same_category: false,
    },
    templates: ['main_content'],
    fields: [
      {
        key: 'show_newest',
        kind: 'boolean',
        label: 'Newest',
        pages: ['products'],
      },
      {
        key: 'newest_same_category',
        kind: 'boolean',
        label: 'Same category',
        tab: 'product_options',
        pages: ['products'],
      },
      {
        key: 'show_similar',
        kind: 'boolean',
        label: 'Similar',
        pages: ['products'],
      },
      {
        key: 'show_reviews',
        kind: 'boolean',
        label: 'Reviews',
        pages: ['products'],
      },
      {
        key: 'show_faqs',
        kind: 'boolean',
        label: 'Questions',
        tab: 'product_options',
        pages: ['products'],
      },
      {
        key: 'section_order',
        kind: 'order',
        label: 'Sections',
        options: ['show_similar', 'show_reviews', 'show_newest'],
        option_labels: {
          show_similar: 'Similar',
          show_reviews: 'Reviews',
          show_newest: 'Newest',
        },
        pages: ['products'],
      },
    ],
  };
  render(
    <BlockDialog
      placement={data}
      definition={productDefinition}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/products"
      languages={languages}
      defaultLocale="en"
      pageKind="products"
      onApply={apply}
      onClose={() => undefined}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(
    screen.getByRole('checkbox', { name: 'Same category' }),
  ).not.toBeChecked();
  expect(
    screen.getByRole('checkbox', { name: 'Same category' }).closest('li'),
  ).toHaveTextContent('Newest');
  fireEvent.click(screen.getByRole('checkbox', { name: 'Same category' }));
  expect(screen.queryByRole('checkbox', { name: 'Similar' })).toBeNull();
  expect(screen.queryByRole('checkbox', { name: 'Questions' })).toBeNull();
  expect(screen.getByRole('switch', { name: 'Reviews' })).not.toBeChecked();
  fireEvent.click(screen.getByRole('switch', { name: 'Reviews' }));
  fireEvent.keyDown(screen.getByRole('button', { name: 'Move Reviews' }), {
    key: 'ArrowUp',
  });
  expect(screen.getAllByRole('listitem')[0]).toHaveTextContent('Reviews');
  const dataTransfer = { effectAllowed: '', setData: vi.fn() };
  fireEvent.dragStart(
    screen.getByRole('button', { name: 'Move Similar' }).closest('li')!,
    { dataTransfer },
  );
  fireEvent.drop(
    screen.getByRole('button', { name: 'Move Reviews' }).closest('li')!,
    { dataTransfer },
  );
  expect(screen.getAllByRole('listitem')[0]).toHaveTextContent('Similar');
  fireEvent.click(screen.getByRole('switch', { name: 'Similar' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Options' }));
  expect(screen.queryByRole('checkbox', { name: 'Same category' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Move Reviews' })).toBeNull();
  expect(screen.getByRole('checkbox', { name: 'Questions' })).toBeChecked();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Questions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[0][0].block.settings).toMatchObject({
    section_order: ['show_similar', 'show_reviews', 'show_newest'],
    show_similar: false,
    show_reviews: true,
    show_faqs: false,
    newest_same_category: true,
  });
});

it('puts identity visibility in the icon rows and exposes the search icon only for Fashion', () => {
  const icons = ['language', 'search', 'account', 'call', 'favorites', 'cart'];
  const settings: Record<string, string> = {
    language: 'show_language_switcher',
    search: 'show_search',
    account: 'show_account',
    call: 'show_call_request',
    favorites: 'show_favorites',
    cart: 'show_cart',
  };
  Object.assign(shared.props.i18n.strings, {
    'layouts.icon_order_handle': 'Move :name',
    'Header style': 'Header style',
    'layouts.identity_options': 'Options',
    Classic: 'Classic',
    Compact: 'Compact',
    Fashion: 'Fashion',
    ...Object.fromEntries(
      icons.flatMap((key) => [
        [key, key],
        [settings[key], settings[key]],
      ]),
    ),
  });
  const apply = vi.fn();
  const data = structuredClone(placement);
  data.block.type = 'identity';
  data.block.template = 'identity';
  data.block.settings = {};
  const config: BlockDefinition = {
    ...definition,
    templates: ['identity'],
    defaults: {
      header_style: 'classic',
      icon_order: icons,
      ...Object.fromEntries(
        icons.map((key) => [settings[key], key !== 'call']),
      ),
    },
    fields: [
      {
        key: 'header_style',
        kind: 'select',
        label: 'Header style',
        options: ['classic', 'compact', 'fashion'],
        option_labels: {
          classic: 'Classic',
          compact: 'Compact',
          fashion: 'Fashion',
        },
      },
      { key: 'icon_order', kind: 'order', label: 'Icon order', options: icons },
      ...icons.map((key) => ({
        key: settings[key],
        kind: 'boolean' as const,
        ...(key === 'search' ? { tab: 'identity_options' as const } : {}),
        label: settings[key],
      })),
    ],
  };
  render(
    <BlockDialog
      placement={data}
      definition={config}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/default"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={() => undefined}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.queryByRole('button', { name: 'Move search' })).toBeNull();
  expect(screen.queryByRole('switch', { name: 'search' })).toBeNull();
  for (const key of icons.filter((key) => key !== 'search')) {
    expect(screen.getByRole('switch', { name: key })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: settings[key] })).toBeNull();
  }
  expect(screen.getByRole('switch', { name: 'call' })).not.toBeChecked();
  fireEvent.click(screen.getByRole('switch', { name: 'account' }));
  expect(screen.queryByRole('checkbox', { name: 'show_search' })).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Options' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'show_search' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  fireEvent.keyDown(screen.getByRole('button', { name: 'Move cart' }), {
    key: 'ArrowUp',
  });
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[0][0].block.settings).toMatchObject({
    icon_order: ['language', 'search', 'account', 'call', 'cart', 'favorites'],
    show_account: false,
    show_search: false,
  });
  choose(screen.getByRole('combobox', { name: 'Header style' }), 'Fashion');
  expect(
    screen.getByRole('button', { name: 'Move search' }),
  ).toBeInTheDocument();
  expect(screen.getByRole('switch', { name: 'search' })).not.toBeChecked();
  expect(screen.queryByRole('checkbox', { name: 'show_search' })).toBeNull();
  fireEvent.click(screen.getByRole('switch', { name: 'search' }));
  choose(screen.getByRole('combobox', { name: 'Header style' }), 'Compact');
  expect(screen.queryByRole('button', { name: 'Move search' })).toBeNull();
  expect(screen.queryByRole('checkbox', { name: 'show_search' })).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Options' }));
  expect(screen.getByRole('checkbox', { name: 'show_search' })).toBeChecked();
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(screen.getByRole('switch', { name: 'account' })).not.toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(apply.mock.calls[1][0].block.settings).toMatchObject({
    icon_order: ['language', 'search', 'account', 'call', 'cart', 'favorites'],
    show_search: true,
    show_account: false,
  });
});

it.each([
  {
    key: 'mobile_products_per_row',
    values: ['1', '2'],
    selected: '1',
    label: 'One',
  },
  {
    key: 'tablet_products_per_row',
    values: ['2', '3'],
    selected: '3',
    label: 'Three',
  },
])(
  'uses a constrained dropdown for $key',
  ({ key, values, selected, label }) => {
    Object.assign(shared.props.i18n.strings, {
      'Mobile columns': 'Mobile columns',
      One: 'One',
      Two: 'Two',
      Three: 'Three',
    });
    const apply = vi.fn();
    const data = structuredClone(placement);
    data.block.type = 'main_content';
    data.block.template = 'main_content';
    data.block.settings = {};
    render(
      <BlockDialog
        placement={data}
        definition={{
          ...definition,
          defaults: { [key]: '2' },
          templates: ['main_content'],
          fields: [
            {
              key,
              kind: 'select',
              label: 'Mobile columns',
              options: values,
              option_labels: { '1': 'One', '2': 'Two', '3': 'Three' },
              pages: ['category'],
            },
          ],
        }}
        grids={layout.grids}
        options={options}
        path="/admin/layouts/category"
        pageKind="category"
        languages={languages}
        defaultLocale="en"
        onApply={apply}
        onClose={() => undefined}
      />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
    const dropdown = screen.getByRole('combobox', { name: 'Mobile columns' });
    expect(dropdown).toHaveTextContent('Two');
    fireEvent.click(dropdown);
    expect(screen.getAllByRole('option')).toHaveLength(2);
    fireEvent.click(screen.getByRole('option', { name: label }));
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(apply.mock.calls[0][0].block.settings[key]).toBe(selected);
  },
);

it.each(['coupons', 'offers'])(
  'configures %s with an ordered manual picker and optional heading icon',
  (type) => {
    Object.assign(shared.props.i18n.strings, {
      'layouts.pick_deals': 'Choose rewards',
      'layouts.title_icon': 'Title icon',
      'layouts.icon_none': 'None',
      'layouts.icon_ticket': 'Ticket',
      'layouts.icon_gift': 'Gift',
    });
    const apply = vi.fn();
    const source = type === 'coupons' ? 'coupons' : 'promotions';
    const template = type === 'coupons' ? 'coupon_carousel' : 'offer_carousel';
    render(
      <BlockDialog
        placement={{
          ...placement,
          block: {
            ...placement.block,
            type,
            template,
            settings: { title_icon: 'ticket' },
            selections: { items: ['1', '2'] },
          },
        }}
        definition={{
          ...definition,
          defaults: { title_icon: 'ticket' },
          templates: [template],
          fields: [
            {
              key: 'items',
              kind: 'selection',
              source,
              label: 'layouts.pick_deals',
            },
            {
              key: 'title_icon',
              kind: 'select',
              label: 'layouts.title_icon',
              options: ['none', 'ticket', 'gift'],
              option_labels: {
                none: 'layouts.icon_none',
                ticket: 'layouts.icon_ticket',
                gift: 'layouts.icon_gift',
              },
            },
          ],
        }}
        grids={layout.grids}
        options={{
          [source]: [
            { id: '1', name: 'First reward' },
            { id: '2', name: 'Second reward' },
          ],
        }}
        path="/admin/layouts/homepage"
        languages={languages}
        defaultLocale="en"
        onApply={apply}
        onClose={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
    expect(
      screen.getByRole('combobox', { name: 'Choose rewards' }),
    ).toBeVisible();
    fireEvent.click(screen.getAllByRole('button', { name: 'Move down' })[0]);
    select('Title icon', 'Gift');
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    const saved = apply.mock.calls[0][0] as Placement;
    expect(saved.block.selections.items).toEqual(['2', '1']);
    expect(saved.block.settings.title_icon).toBe('gift');
  },
);

it('renders an optional icon with the public coupon block heading', () => {
  const { container, rerender } = render(
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          content: [
            {
              id: 'rewards',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'coupons',
                  title: 'Earn rewards',
                  template: 'coupon_carousel',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: { items: [], display: { title_icon: 'trophy' } },
                },
              ],
            },
          ],
        },
      }}
    />,
  );
  expect(
    screen.getByRole('heading', { name: 'Earn rewards' }).querySelector('svg'),
  ).toHaveAttribute('aria-hidden', 'true');
  expect(container.querySelector('.sf-title-icon')).toBeInTheDocument();
  rerender(
    <DefaultTheme
      page={{
        theme: 'default',
        regions: {
          content: [
            {
              id: 'rewards',
              width: 16,
              row: 1,
              children: [],
              blocks: [
                {
                  id: 'coupons',
                  title: 'Earn rewards',
                  template: 'coupon_carousel',
                  css_class: '',
                  mobile: true,
                  tablet: true,
                  desktop: true,
                  content: { items: [], display: { title_icon: 'none' } },
                },
              ],
            },
          ],
        },
      }}
    />,
  );
  expect(container.querySelector('.sf-title-icon')).not.toBeInTheDocument();
});

it('edits the mobile arrow setting only for carousel templates and preserves it when switching templates', () => {
  shared.props.i18n.strings['layouts.hide_arrows_mobile'] =
    'Hide carousel arrows on mobile';
  const apply = vi.fn();
  render(
    <BlockDialog
      placement={{
        ...placement,
        block: { ...placement.block, template: 'product_carousel' },
      }}
      definition={{
        ...definition,
        fields: [
          ...definition.fields,
          {
            key: 'hide_arrows_mobile',
            kind: 'boolean',
            label: 'layouts.hide_arrows_mobile',
            carousel_only: true,
          },
        ],
        defaults: { ...definition.defaults, hide_arrows_mobile: false },
      }}
      grids={layout.grids}
      options={options}
      path="/admin/layouts/homepage"
      languages={languages}
      defaultLocale="en"
      onApply={apply}
      onClose={vi.fn()}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  const setting = screen.getByRole('checkbox', {
    name: 'Hide carousel arrows on mobile',
  });
  expect(setting).not.toBeChecked();
  fireEvent.click(setting);
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  select('Template', 'Single product');
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(
    screen.queryByRole('checkbox', { name: 'Hide carousel arrows on mobile' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  select('Template', 'Carousel');
  fireEvent.click(screen.getByRole('tab', { name: 'Content' }));
  expect(
    screen.getByRole('checkbox', { name: 'Hide carousel arrows on mobile' }),
  ).toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
  expect(
    (apply.mock.calls[0][0] as Placement).block.settings.hide_arrows_mobile,
  ).toBe(true);
});
