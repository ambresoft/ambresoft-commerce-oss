import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { expect, it, vi } from 'vitest';
import FeatureForm from '../pages/Admin/Features/Form';
import type { Feature } from '../types/features';
import { choose } from './select';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
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
        capabilities: { 'features.delete': true, 'features.status': true },
        i18n: {
          locale: 'en',
          locales: [
            { code: 'en', name: 'English' },
            { code: 'el', name: 'Greek' },
          ],
          strings: {},
        },
      },
    }),
  };
});

const feature: Feature = {
  id: '7',
  version: 1,
  name: 'Connectivity',
  code: 'CONNECTIVITY',
  type: 'multiple',
  purpose: 'standard',
  status: 'active',
  incomplete: false,
  group_id: '1',
  unit: '',
  required: false,
  filterable: false,
  comparison: false,
  categories: [],
  translations: {},
  options: [],
};
const props = {
  brandLandingPages: false,
  navigation: [],
  breadcrumbs: [],
  defaultLocale: 'en',
  impactPreview: null,
  groups: [
    { id: '1', version: 1, name: 'General', protected: true, translations: {} },
  ],
  categories: [{ id: '1', name: 'Electronics' }],
  feature,
};
const brand: Feature = {
  ...feature,
  id: '9',
  name: 'Brand',
  code: 'BRAND',
  type: 'single',
  purpose: 'landing_pages',
  options: [
    {
      id: '31',
      version: 2,
      status: 'active',
      label: 'Apple',
      landing_eligible: true,
      image_url: null,
      translations: {
        en: {
          label: 'Apple',
          description: 'Apple products',
          seo_title: 'Apple',
          seo_description: '',
          meta_keywords: '',
          slug: 'apple',
        },
        el: {
          label: 'Apple',
          description: 'Προϊόντα Apple',
          seo_title: '',
          seo_description: '',
          meta_keywords: '',
          slug: 'apple-el',
        },
      },
    },
    {
      id: '32',
      version: 1,
      status: 'archived',
      label: 'Legacy',
      landing_eligible: false,
      image_url: null,
      translations: { en: { label: 'Legacy', slug: 'legacy' } },
    },
  ],
};

/** Opens the per-row actions menu of an option and returns the menu items' names. */
function openMenu(index: number) {
  fireEvent.click(
    screen.getAllByRole('button', { name: 'features.options_actions' })[index],
  );
}

it('preserves independent unsaved language values across feature sections and keeps save available', () => {
  render(<FeatureForm {...props} />);
  fireEvent.change(screen.getByLabelText('features.name'), {
    target: { value: 'Connections' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Greek' }));
  expect(screen.getByLabelText('features.name')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('features.name'), {
    target: { value: 'Συνδέσεις' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(
    screen.queryByRole('textbox', { name: 'features.name' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_general' }));
  expect(screen.getByLabelText('features.name')).toHaveValue('Συνδέσεις');
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('features.name')).toHaveValue('Connections');
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  expect(
    within(screen.getByRole('tabpanel')).getByText('features.add_option'),
  ).toBeInTheDocument();
  expect(
    screen.getAllByRole('button', { name: 'common.save' })[0],
  ).toHaveAttribute('form', 'feature-definition-form');
});

it('supports keyboard tab navigation and only offers predefined options for existing choice features', () => {
  render(
    <FeatureForm {...props} feature={{ ...props.feature, type: 'number' }} />,
  );
  expect(
    screen.queryByRole('tab', { name: 'features.tab_options' }),
  ).not.toBeInTheDocument();
  fireEvent.keyDown(screen.getByRole('tab', { name: 'features.tab_general' }), {
    key: 'ArrowRight',
  });
  expect(
    screen.getByRole('tab', { name: 'features.tab_settings' }),
  ).toHaveFocus();
  expect(screen.getByLabelText('features.unit')).toBeVisible();
  fireEvent.keyDown(
    screen.getByRole('tab', { name: 'features.tab_settings' }),
    { key: 'End' },
  );
  expect(
    screen.getByRole('tab', { name: 'features.tab_categories' }),
  ).toHaveAttribute('aria-selected', 'true');
  expect(
    screen.queryByRole('tab', { name: 'features.tab_seo' }),
  ).not.toBeInTheDocument();
});

it('offers Brand / Author Landing Pages only for Single choice features and defaults to Standard Attribute', () => {
  render(<FeatureForm {...props} feature={null} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  const standard = screen.getByRole('radio', {
    name: 'features.purpose_standard',
  });
  const landing = screen.getByRole('radio', {
    name: 'features.purpose_landing_pages',
  });
  expect(standard).toBeChecked();
  expect(landing).toBeDisabled();
  expect(screen.getByText('features.purpose_unavailable')).toBeVisible();
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_general' }));
  choose(screen.getByLabelText(/features.type/), 'features.type_single');
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(landing).toBeEnabled();
  fireEvent.click(landing);
  expect(landing).toBeChecked();
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_general' }));
  choose(screen.getByLabelText(/features.type/), 'features.type_multiple');
  expect(standard).toBeChecked();
  expect(landing).toBeDisabled();
});

it('hides variant landing-page fields for Standard Attribute options', () => {
  render(
    <FeatureForm
      {...props}
      feature={{
        ...brand,
        purpose: 'standard',
        options: [
          {
            id: '31',
            version: 2,
            status: 'active',
            label: 'Apple',
            translations: { en: { label: 'Apple' } },
          },
        ],
      }}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  openMenu(0);
  expect(
    screen.queryByRole('button', { name: 'features.options_details' }),
  ).not.toBeInTheDocument();
  expect(screen.queryByText('features.landing_page')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('features.slug')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('features.image')).not.toBeInTheDocument();
});

it('edits localized variant landing metadata independently and keeps archived variants read-only', () => {
  render(<FeatureForm {...props} feature={brand} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  // Archived options are read-only: no details editor is offered for them.
  openMenu(1);
  expect(
    screen.queryByRole('button', { name: 'features.options_details' }),
  ).not.toBeInTheDocument();
  openMenu(0);
  fireEvent.click(
    screen.getByRole('button', { name: 'features.options_details' }),
  );
  const apple = screen.getByRole('group', { name: 'features.landing_page' });
  expect(within(apple).getByText('features.landing_eligible')).toBeVisible();
  expect(within(apple).getByLabelText('features.slug')).toHaveValue('apple');
  fireEvent.change(within(apple).getByLabelText('features.seo_title'), {
    target: { value: 'Apple store' },
  });
  const appleForm = apple.closest('form') as HTMLFormElement;
  const english = within(appleForm).getByRole('button', { name: 'English' });
  const greek = within(appleForm).getByRole('button', { name: 'Greek' });
  expect(english).toHaveAttribute('aria-pressed', 'true');
  expect(greek).toHaveAttribute('aria-pressed', 'false');
  fireEvent.click(within(appleForm).getByRole('button', { name: 'Greek' }));
  expect(greek).toHaveAttribute('aria-pressed', 'true');
  expect(english).toHaveAttribute('aria-pressed', 'false');
  expect(within(apple).getByLabelText('features.slug')).toHaveValue('apple-el');
  expect(within(apple).getByLabelText('features.seo_title')).toHaveValue('');
  expect(
    within(apple).getByLabelText('features.landing_description'),
  ).toHaveTextContent('Προϊόντα Apple');
  fireEvent.click(within(appleForm).getByRole('button', { name: 'English' }));
  expect(within(apple).getByLabelText('features.seo_title')).toHaveValue(
    'Apple store',
  );
  expect(within(appleForm).getByLabelText('features.label')).toHaveValue(
    'Apple',
  );
  expect(screen.getByRole('switch', { name: 'Legacy' })).not.toBeChecked();
});

it('warns that switching back to Standard Attribute hides but preserves variant metadata', () => {
  render(<FeatureForm {...props} feature={brand} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(screen.queryByText('features.purpose_downgrade')).toBeNull();
  fireEvent.click(
    screen.getByRole('radio', { name: 'features.purpose_standard' }),
  );
  expect(screen.getByText('features.purpose_downgrade')).toBeVisible();
});

const shoeColor: Feature = {
  ...feature,
  id: '12',
  name: 'Color',
  code: 'COLOR',
  type: 'single',
  purpose: 'variations',
  variation_style: 'color',
  variation_groups: 2,
  options: [
    {
      id: '51',
      version: 1,
      status: 'active',
      label: 'Red',
      translations: { en: { label: 'Red' } },
      swatch_color: '#FF0000',
      swatch_image_url: null,
    },
    {
      id: '52',
      version: 1,
      status: 'active',
      label: 'White',
      translations: { en: { label: 'White' } },
      swatch_color: null,
      swatch_image_url: null,
    },
    {
      id: '53',
      version: 1,
      status: 'archived',
      label: 'Gold',
      translations: { en: { label: 'Gold' } },
      swatch_color: null,
      swatch_image_url: null,
    },
  ],
};

it('offers Variations as separate products only for Single choice features', () => {
  render(<FeatureForm {...props} feature={null} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(
    screen.getByRole('radio', { name: 'features.purpose_variations' }),
  ).toBeDisabled();
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_general' }));
  choose(screen.getByLabelText(/features.type/), 'features.type_single');
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  const variations = screen.getByRole('radio', {
    name: 'features.purpose_variations',
  });
  expect(variations).toBeEnabled();
  fireEvent.click(variations);
  // Text buttons is the default selector style.
  expect(
    screen.getByRole('combobox', { name: 'features.variation_style' }),
  ).toHaveTextContent('features.style_text');
});

it('shows the shared selector style, swatch warnings and blocked purpose changes for variation features', () => {
  render(<FeatureForm {...props} feature={shoeColor} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(
    screen.getByRole('combobox', { name: 'features.variation_style' }),
  ).toHaveTextContent('features.style_color');
  expect(screen.getByText('features.variation_groups_using')).toBeVisible();
  // Only active options without a color are listed; archived ones are ignored.
  const warning = screen
    .getByText('features.swatch_missing_color')
    .closest('div')!;
  expect(warning).toHaveTextContent('White');
  expect(warning).not.toHaveTextContent('Gold');
  expect(warning).not.toHaveTextContent('Red');
  fireEvent.click(
    screen.getByRole('radio', { name: 'features.purpose_standard' }),
  );
  expect(screen.getByText('features.purpose_variations_in_use')).toBeVisible();
});

it('edits swatch colors only for variation features in Color style', () => {
  const { unmount } = render(<FeatureForm {...props} feature={shoeColor} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  openMenu(0);
  fireEvent.click(
    screen.getByRole('button', { name: 'features.options_details' }),
  );
  expect(screen.getByLabelText('features.swatch_color')).toHaveValue('#FF0000');
  unmount();
  render(
    <FeatureForm {...props} feature={{ ...brand, options: brand.options }} />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  expect(
    screen.queryByLabelText('features.swatch_color'),
  ).not.toBeInTheDocument();
});

it('collects inline option edits and new rows, then saves them together or discards them', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<FeatureForm {...props} feature={brand} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  fireEvent.click(screen.getAllByRole('button', { name: 'Apple' })[0]);
  fireEvent.change(screen.getByLabelText('Apple · English'), {
    target: { value: 'Apple Inc.' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'features.add_option' }));
  fireEvent.change(screen.getByLabelText('features.add_option · English'), {
    target: { value: 'Orion' },
  });
  const bar = screen.getByRole('status');
  expect(bar).toHaveTextContent('features.options_pending');
  fireEvent.click(
    within(bar).getByRole('button', { name: 'features.options_save' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/features/9/options/batch',
    {
      feature_version: brand.version,
      changes: [
        {
          id: 31,
          version: 2,
          translations: {
            en: { label: 'Apple Inc.' },
            el: { label: 'Apple' },
          },
        },
        {
          translations: { en: { label: 'Orion' }, el: { label: '' } },
        },
      ],
    },
    expect.objectContaining({ preserveScroll: true }),
  );
  fireEvent.click(
    within(bar).getByRole('button', { name: 'features.options_discard' }),
  );
  expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

it('filters options by status and searches across languages', () => {
  render(<FeatureForm {...props} feature={brand} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  fireEvent.click(screen.getByRole('tab', { name: /features.archived/ }));
  expect(screen.getByText('Legacy')).toBeInTheDocument();
  expect(screen.queryByText('Apple')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: /features.all/ }));
  fireEvent.change(screen.getByLabelText('features.options_search'), {
    target: { value: 'zzz' },
  });
  expect(screen.getByText('features.options_empty')).toBeInTheDocument();
});

it('archives and restores an option through its switch with the fields the server requires', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<FeatureForm {...props} feature={brand} />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  fireEvent.click(screen.getByRole('switch', { name: 'Apple' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/features/9/options/31',
    expect.objectContaining({
      action: 'archive',
      version: 2,
      feature_version: brand.version,
      translations: brand.options[0].translations,
    }),
    expect.anything(),
  );
});

it('asks for impact confirmation in a dialog and repeats the delete once confirmed', () => {
  const post = vi.fn();
  const { rerender } = render(<FeatureForm {...props} feature={brand} />);
  // The server answers the first attempt with an impact preview; the dialog confirms and repeats it.
  rerender(
    <FeatureForm
      {...props}
      feature={brand}
      impactPreview={{
        impact: { assigned_products: 3 },
        token: 'a'.repeat(64),
      }}
    />,
  );
  expect(
    screen.getByRole('alertdialog', { name: 'features.impact' }),
  ).toBeInTheDocument();
  expect(screen.getByText('3')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'common.cancel' }));
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  void post;
});

it('deletes a feature through its own dialog that requires typing the code, never a browser popup', () => {
  const confirm = vi.spyOn(window, 'confirm');
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<FeatureForm {...props} feature={{ ...brand, products: 49 }} />);
  fireEvent.click(screen.getByRole('button', { name: 'common.actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'features.delete' }));
  expect(
    screen.getByRole('alertdialog', { name: 'features.delete_feature_title' }),
  ).toBeInTheDocument();
  const del = within(screen.getByRole('alertdialog')).getByRole('button', {
    name: 'features.delete',
  });
  expect(del).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/features.type_to_confirm/), {
    target: { value: brand.code },
  });
  expect(del).toBeEnabled();
  expect(confirm).not.toHaveBeenCalled();
  void post;
});

it('disables feature landing fields in brand mode while keeping variations available', () => {
  render(<FeatureForm {...props} feature={brand} brandLandingPages />);
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_settings' }));
  expect(
    screen.getByRole('radio', { name: 'features.purpose_landing_pages' }),
  ).toBeDisabled();
  expect(
    screen.getByRole('radio', { name: 'features.purpose_variations' }),
  ).toBeEnabled();
  fireEvent.click(screen.getByRole('tab', { name: 'features.tab_options' }));
  openMenu(0);
  fireEvent.click(
    screen.getByRole('button', { name: 'features.options_details' }),
  );
  expect(screen.queryByLabelText('features.slug')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('features.image')).not.toBeInTheDocument();
  expect(screen.queryByText('features.landing_page')).not.toBeInTheDocument();
});

it.each([
  ['active', 'features.archived', 'archive'],
  ['archived', 'features.active', 'restore'],
])(
  'changes %s features through the header status without bypassing lifecycle confirmation',
  (status, label, action) => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    render(<FeatureForm {...props} feature={{ ...feature, status }} />);
    choose(screen.getByRole('combobox', { name: 'common.status' }), label);
    expect(post).toHaveBeenCalledWith(
      '/admin/features/7/action',
      expect.objectContaining({
        version: feature.version,
        action,
        confirmed: false,
        impact_token: null,
      }),
      expect.anything(),
    );
  },
);
