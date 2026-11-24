import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Index from '../pages/Admin/VariationGroups/Index';
import Show from '../pages/Admin/VariationGroups/Show';
import Create from '../pages/Admin/VariationGroups/Create';
import type { VariationGroupDetail } from '../types/variations';
import { choose } from './select';

const page = vi.hoisted(() => ({
  capabilities: {} as Record<string, boolean>,
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () =>
    (key: string, replacements: Record<string, string | number> = {}) =>
      Object.keys(replacements).length
        ? key + ' ' + JSON.stringify(replacements)
        : key,
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
    usePage: () => ({
      props: {
        capabilities: page.capabilities,
        errors: {},
        i18n: {
          strings: {},
          locales: [{ code: 'en', name: 'English' }],
          locale: 'en',
        },
      },
    }),
  };
});

beforeEach(() => {
  vi.restoreAllMocks();
  window.history.replaceState(null, '', '/admin/variation-groups/4');
  vi.spyOn(router, 'replace').mockImplementation(() => {});
  page.capabilities = {
    'products.view': true,
    'products.update': true,
    'products.create': true,
  };
});

const shell = { navigation: [], breadcrumbs: [] };
const group: VariationGroupDetail = {
  id: '4',
  name: 'Nike Jordan',
  version: 3,
  type: 'physical',
  category: ['Shoes', 'Sneakers'],
  features: [
    {
      id: '10',
      name: 'Color',
      code: 'COLOR',
      style: 'color',
      options: [
        { id: '100', label: 'Red', status: 'active' },
        { id: '101', label: 'White', status: 'active' },
      ],
    },
    {
      id: '11',
      name: 'Size',
      code: 'SIZE',
      style: 'text',
      options: [
        { id: '110', label: '40', status: 'active' },
        { id: '111', label: '43', status: 'active' },
      ],
    },
  ],
  members: [
    {
      id: '1',
      name: 'Jordan Red 40',
      sku: 'J-R-40',
      status: 'active',
      version: 5,
      values: {
        '10': { id: '100', label: 'Red' },
        '11': { id: '110', label: '40' },
      },
      problems: [],
    },
    {
      id: '2',
      name: 'Jordan White 43',
      sku: 'J-W-43',
      status: 'hidden',
      version: 2,
      values: {
        '10': { id: '101', label: 'White' },
        '11': { id: '111', label: '43' },
      },
      problems: [],
    },
  ],
  problems: [],
  readiness: { total: 2, available: 1, eligible: false },
  pending: [],
};
const showProps = {
  ...shell,
  group,
  candidates: [],
  targets: [{ id: '9', name: 'Air Max', version: 1 }],
  availableFeatures: [
    { id: '10', name: 'Color' },
    { id: '11', name: 'Size' },
    { id: '12', name: 'Material' },
  ],
  impactPreview: null,
  candidateSearch: '',
};

it('lists groups and applies server filters immediately', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  render(
    <Index
      {...shell}
      groups={{
        data: [
          {
            id: '4',
            name: 'Nike Jordan',
            members: 2,
            type: 'physical',
            category: ['Shoes'],
            features: ['Color', 'Size'],
          },
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
      filters={{}}
      features={[{ id: '10', name: 'Color' }]}
      categories={[{ id: '5', name: 'Shoes' }]}
    />,
  );
  const row = screen.getByRole('link', { name: 'Nike Jordan' }).closest('tr')!;
  expect(row).toHaveTextContent('Color');
  expect(row).toHaveTextContent('Size');
  expect(
    screen.getByRole('link', { name: /variations.create/ }),
  ).toHaveAttribute('href', '/admin/variation-groups/create');
  fireEvent.click(screen.getByRole('button', { name: /variations.members:/ }));
  fireEvent.click(
    screen.getByRole('button', { name: 'variations.members_multiple' }),
  );
  expect(get.mock.lastCall?.[1]).toMatchObject({ members: 'multiple' });
});

it('marks reserved combinations, including hidden members, and creates only a free one', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<Show {...showProps} />);
  const panel = screen.getByRole('region', {
    name: 'variations.create_variation',
  });
  // The source's own combination is reserved by itself.
  expect(within(panel).getByRole('alert')).toHaveTextContent('Jordan Red 40');
  choose(within(panel).getByLabelText('Color'), 'White');
  choose(within(panel).getByLabelText('Size'), '43');
  expect(within(panel).getByRole('alert')).toHaveTextContent('Jordan White 43');
  expect(
    within(panel).getByRole('button', {
      name: 'variations.create_variation_submit',
    }),
  ).toBeDisabled();
  choose(within(panel).getByLabelText('Size'), '40');
  expect(within(panel).getByText('variations.combination_free')).toBeVisible();
  fireEvent.click(
    within(panel).getByRole('button', {
      name: 'variations.create_variation_submit',
    }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/variation-groups/4/variations',
    {
      version: 3,
      source: '1',
      source_version: 5,
      options: { '10': '101', '11': '110' },
    },
    expect.any(Object),
  );
});

it('confirms removal and disbanding with the group name and member count', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<Show {...showProps} />);
  fireEvent.click(
    screen.getByRole('button', {
      name: /variations.remove_member.*Jordan White 43/,
    }),
  );
  expect(confirm.mock.lastCall?.[0]).toContain('Nike Jordan');
  expect(remove).not.toHaveBeenCalled();
  confirm.mockReturnValue(true);
  fireEvent.click(
    screen.getByRole('button', {
      name: /variations.remove_member.*Jordan White 43/,
    }),
  );
  expect(remove).toHaveBeenCalledWith(
    '/admin/variation-groups/4/members/2',
    expect.objectContaining({ data: { version: 3, product_version: 2 } }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'common.actions' }));
  fireEvent.click(screen.getByRole('button', { name: /variations.disband/ }));
  expect(confirm.mock.lastCall?.[0]).toContain('"count":2');
  expect(remove).toHaveBeenLastCalledWith('/admin/variation-groups/4', {
    data: { version: 3 },
  });
});

it('previews a defining-feature change and confirms it with the server token', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <Show
      {...showProps}
      impactPreview={{
        impact: {
          members: 2,
          added: ['Material'],
          removed: [],
          combinations: [
            {
              product: 'Jordan Red 40',
              values: 'Color: Red / Size: 40 / Material: Cotton',
            },
          ],
        },
        token: 't'.repeat(64),
        features: ['10', '11', '12'],
      }}
    />,
  );
  expect(screen.getByText(/Material: Cotton/)).toBeVisible();
  fireEvent.click(
    screen.getByRole('button', { name: 'variations.confirm_change' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/variation-groups/4/features',
    {
      version: 3,
      features: ['10', '11', '12'],
      confirmed: true,
      impact_token: 't'.repeat(64),
    },
    expect.any(Object),
  );
});

it('shows readiness to read-only staff without offering any change', () => {
  page.capabilities = { 'products.view': true };
  render(<Show {...showProps} />);
  expect(screen.getByText('variations.switcher_not_ready')).toBeVisible();
  expect(
    screen.queryByRole('button', { name: /variations.disband/ }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('region', { name: 'variations.create_variation' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: /variations.remove_member/ }),
  ).not.toBeInTheDocument();
});

it('guides creation through product selection, eligible features and the name', () => {
  const get = vi.spyOn(router, 'get').mockImplementation(() => {});
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <Create
      {...shell}
      filters={{ product_id: '1' }}
      creation={{
        products: [
          {
            id: '1',
            name: 'Jordan Red 40',
            sku: 'J-R-40',
            type: 'physical',
            category: ['Shoes'],
          },
        ],
        selected: {
          id: '1',
          name: 'Jordan Red 40',
          sku: 'J-R-40',
          type: 'physical',
          version: 5,
          category: ['Shoes'],
          grouped: false,
          problems: [],
          suggested_name: 'Jordan Red 40',
          features: [
            {
              id: '10',
              name: 'Color',
              value: 'Red',
              eligible: true,
              problem: null,
            },
            {
              id: '12',
              name: 'Material',
              value: null,
              eligible: false,
              problem: 'Needs exactly one value for Material.',
            },
          ],
        },
      }}
    />,
  );
  expect(
    screen.getByRole('button', { name: 'variations.selected' }),
  ).toHaveAttribute('aria-pressed', 'true');
  expect(screen.getByRole('checkbox', { name: /Material/ })).toBeDisabled();
  expect(
    screen.getByRole('button', { name: 'variations.create_submit' }),
  ).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: /Color/ }));
  expect(screen.getByLabelText('variations.name_hint')).toHaveValue(
    'Jordan Red 40',
  );
  fireEvent.click(
    screen.getByRole('button', { name: 'variations.create_submit' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/variation-groups',
    expect.objectContaining({
      product_id: '1',
      product_version: 5,
      features: ['10'],
      name: 'Jordan Red 40',
    }),
    expect.any(Object),
  );
  expect(get).not.toHaveBeenCalled();
});

it('keeps listing settings local until one save submits them together', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<Show {...showProps} group={{ ...group, card_features: ['11'] }} />);
  fireEvent.click(
    screen.getByRole('tab', { name: 'variations.single_listing' }),
  );
  const settings = screen.getByRole('form', {
    name: 'variations.single_listing',
  });
  const save = within(settings).getByRole('button', { name: 'common.save' });
  expect(save).toBeDisabled();
  fireEvent.click(within(settings).getByRole('checkbox', { name: 'Color' }));
  fireEvent.click(
    within(settings).getByRole('switch', { name: 'variations.view_product' }),
  );
  fireEvent.click(
    within(settings).getByRole('switch', {
      name: 'variations.single_search_surface',
    }),
  );
  fireEvent.change(
    within(settings).getByRole('textbox', {
      name: /variations.card_label.*Size/,
    }),
    { target: { value: 'Size' } },
  );
  choose(
    within(settings).getByLabelText('variations.color_display'),
    'variations.color_swatches',
  );
  fireEvent.click(
    within(settings).getByRole('switch', {
      name: 'variations.clickable_colors',
    }),
  );
  expect(post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('tab', { name: 'variations.members' }));
  expect(settings).not.toBeVisible();
  fireEvent.click(
    screen.getByRole('tab', { name: 'variations.single_listing' }),
  );
  expect(
    within(settings).getByRole('switch', { name: 'variations.view_product' }),
  ).toBeChecked();
  expect(save).toBeEnabled();
  fireEvent.click(save);
  expect(post).toHaveBeenCalledTimes(1);
  expect(post).toHaveBeenCalledWith(
    '/admin/variation-groups/4/listing',
    {
      version: 3,
      single_carousel: false,
      single_listing: false,
      single_search: true,
      view_product: true,
      color_swatches: true,
      clickable_colors: true,
      card_features: ['11', '10'],
      card_labels: { '11': { en: 'Size' } },
    },
    expect.any(Object),
  );
});

it('shows existing-product search in a separate tab and preserves picks when switching', () => {
  render(
    <Show
      {...showProps}
      candidates={[
        {
          id: '9',
          name: 'Candidate laptop',
          sku: 'C-9',
          status: 'active',
          version: 1,
          values: [],
          problems: [],
        },
      ]}
    />,
  );
  const candidate = screen.getByText('Candidate laptop');
  expect(candidate).not.toBeVisible();
  fireEvent.click(screen.getByRole('tab', { name: 'variations.add_products' }));
  expect(candidate).toBeVisible();
  const panel = screen.getByRole('tabpanel', {
    name: 'variations.add_products',
  });
  const checkbox = within(panel).getByRole('checkbox');
  fireEvent.click(checkbox);
  fireEvent.click(screen.getByRole('tab', { name: 'variations.members' }));
  expect(candidate).not.toBeVisible();
  fireEvent.keyDown(screen.getByRole('tab', { name: 'variations.members' }), {
    key: 'ArrowRight',
  });
  expect(candidate).toBeVisible();
  expect(checkbox).toBeChecked();
});
