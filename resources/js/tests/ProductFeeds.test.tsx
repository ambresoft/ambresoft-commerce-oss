import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductFeeds, {
  type ProductFeedsProps,
} from '../../../modules/ambresoft/product-feeds/resources/js/ProductFeeds';
import { choose } from './select';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
  usePage: () => ({
    props: { errors: {}, preferences: {} },
    url: '/admin/addons/ambresoft/product-feeds/create',
  }),
}));

const ui = (key: string) => 'cms-product-feeds::ui.' + key;
const feed: NonNullable<ProductFeedsProps['feed']> = {
  id: null,
  name: '',
  channel: 'google',
  cron_password: 'secret-cron-1',
  status: 'active',
  scope: 'all',
  categories: [],
  locale_id: null,
  id_source: 'id',
  description_source: 'full',
  description_limit: null,
  title_for_empty_description: true,
  empty_description: '',
  apply_promotions: true,
  use_list_price: false,
  discount_label: false,
  show_categories: true,
  additional_images: false,
  in_stock_only: false,
  min_price: null,
  show_weight: false,
  attributes: [{ attribute: 'brand', feature: 'Brand', fallback: '' }],
};
const props: ProductFeedsProps = {
  navigation: [],
  breadcrumbs: [],
  canManage: true,
  feed,
  urls: null,
  categories: [
    { id: '1', name: 'Clothing', parentId: null },
    { id: '2', name: 'Shirts', parentId: '1' },
  ],
  languages: [{ id: 3, name: 'Ελληνικά' }],
  attributes: ['brand', 'color'],
};
beforeEach(() => vi.restoreAllMocks());

it('creates a feed with selected categories, a mapped field and typed limits', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<ProductFeeds {...props} />);
  fireEvent.change(screen.getByRole('textbox', { name: ui('name') }), {
    target: { value: 'Meta' },
  });
  choose(
    screen.getByRole('combobox', { name: ui('channel') }),
    ui('channel_meta'),
  );
  choose(
    screen.getByRole('combobox', { name: ui('scope') }),
    ui('scope_categories'),
  );
  fireEvent.click(screen.getByRole('checkbox', { name: 'Shirts' }));
  // Values typed on one tab survive switching to another.
  fireEvent.click(screen.getByRole('tab', { name: ui('products') }));
  fireEvent.change(screen.getByLabelText(ui('description_limit')), {
    target: { value: '500' },
  });
  fireEvent.click(screen.getByRole('tab', { name: ui('mapping') }));
  fireEvent.click(screen.getByRole('button', { name: ui('add_attribute') }));
  fireEvent.change(
    screen.getAllByRole('textbox', { name: ui('fallback') })[1],
    {
      target: { value: 'Blue' },
    },
  );
  fireEvent.click(screen.getByRole('button', { name: ui('save') }));
  expect(post).toHaveBeenCalledWith(
    '/admin/addons/ambresoft/product-feeds',
    expect.objectContaining({
      name: 'Meta',
      channel: 'meta',
      scope: 'categories',
      categories: ['2'],
      description_limit: 500,
      min_price: null,
      attributes: [
        { attribute: 'brand', feature: 'Brand', fallback: '' },
        { attribute: 'color', feature: '', fallback: 'Blue' },
      ],
    }),
    expect.anything(),
  );
  expect(
    screen.queryByRole('button', { name: ui('add_attribute') }),
  ).not.toBeInTheDocument();
});

it('shows a saved feed URL and cron command, and viewers cannot save', () => {
  render(
    <ProductFeeds
      {...props}
      canManage={false}
      feed={{ ...feed, id: 'f1', name: 'Google', cron_password: '' }}
      urls={{
        feed: 'https://store.example/storefront/product-feeds/f1.xml',
        cron: 'https://store.example/storefront/product-feeds/f1/generate',
      }}
    />,
  );
  expect(screen.getByLabelText(ui('feed_url'))).toHaveValue(
    'https://store.example/storefront/product-feeds/f1.xml',
  );
  expect(screen.queryByLabelText(ui('cron'))).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: ui('save') }),
  ).not.toBeInTheDocument();
});
