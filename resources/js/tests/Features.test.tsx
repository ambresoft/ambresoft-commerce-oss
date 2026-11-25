import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductFeatureFields from '../components/admin/ProductFeatureFields';
import FeatureImpact from '../components/admin/FeatureImpact';
import type { Feature, FeatureEditor, FeatureValue } from '../types/features';
import { choose, options } from './select';
const shared = vi.hoisted(() => ({
  props: {
    i18n: {
      locale: 'en',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings: {} as Record<string, string>,
    },
  },
}));
vi.mock('@inertiajs/react', () => ({ usePage: () => shared }));
beforeEach(() => {
  shared.props.i18n.strings = Object.fromEntries(
    [
      'yes',
      'no',
      'unset',
      'archived',
      'historical',
      'required_missing',
      'remediation_help',
      'archived_option_warning',
      'category_save_first',
      'confirm_impact',
      'impact_help',
      'assigned_products',
      'impact',
    ].map((k) => ['features.' + k, k]),
  );
});
const feature = (
  id: string,
  type: string,
  extra: Partial<Feature> = {},
): Feature => ({
  id,
  type,
  purpose: 'standard',
  name: id,
  code: id,
  version: 1,
  status: 'active',
  incomplete: false,
  group_id: '1',
  unit: 'g',
  required: false,
  filterable: false,
  comparison: false,
  categories: [],
  translations: {},
  options: [],
  ...extra,
});
const editor = (
  definitions: Feature[],
  values: Record<string, FeatureValue> = {},
): FeatureEditor => ({
  definitions,
  values,
  groups: [
    { id: '1', version: 1, name: 'General', protected: true, translations: {} },
  ],
  missing: [],
  catalog_version: 1,
});
function Harness({
  data,
  categoryChanged = false,
}: {
  data: FeatureEditor;
  categoryChanged?: boolean;
}) {
  const [values, setValues] = useState(data.values);
  return (
    <>
      <ProductFeatureFields
        editor={data}
        values={values}
        onChange={setValues}
        categoryChanged={categoryChanged}
      />
      <output data-testid="values">{JSON.stringify(values)}</output>
    </>
  );
}
it('keeps Yes, No and Unset distinct and preserves exact negative numeric input', () => {
  render(
    <Harness
      data={editor([
        feature('Wireless', 'boolean'),
        feature('Weight', 'number'),
      ])}
    />,
  );
  const wireless = screen.getByLabelText('Wireless');
  expect(wireless).toHaveTextContent('unset');
  expect(options(wireless).map((option) => option.textContent)).toEqual([
    'unset',
    'yes',
    'no',
  ]);
  choose(wireless, 'no');
  expect(screen.getByTestId('values')).toHaveTextContent('"boolean":"0"');
  choose(wireless, 'unset');
  expect(screen.getByTestId('values')).toHaveTextContent('"boolean":""');
  fireEvent.change(screen.getByLabelText(/^Weight/), {
    target: { value: '-0.000001' },
  });
  expect(screen.getByTestId('values')).toHaveTextContent(
    '"number":"-0.000001"',
  );
});
it('edits localized text independently and starts optional values empty', () => {
  render(<Harness data={editor([feature('Notes', 'text')])} />);
  fireEvent.change(screen.getByLabelText('English'), {
    target: { value: 'Windows and Linux' },
  });
  expect(screen.getByLabelText('Ελληνικά')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('Ελληνικά'), {
    target: { value: 'Windows και Linux' },
  });
  expect(screen.getByLabelText('English')).toHaveValue('Windows and Linux');
});
it('displays archived values read-only and prevents new selection of archived options', () => {
  const choice = feature('Material', 'single', {
    options: [
      {
        id: '10',
        version: 1,
        status: 'archived',
        label: 'Cotton',
        translations: {},
      },
      {
        id: '11',
        version: 1,
        status: 'active',
        label: 'Linen',
        translations: {},
      },
    ],
  });
  render(
    <Harness
      data={editor(
        [
          choice,
          feature('Old note', 'text', { status: 'archived', readonly: true }),
        ],
        {
          Material: {
            version: 1,
            number: '',
            boolean: '',
            texts: {},
            options: ['10'],
          },
        },
      )}
    />,
  );
  const material = screen.getByLabelText('Material');
  expect(material).toHaveTextContent('Cotton · archived');
  expect(
    options(material).find(
      (option) => option.textContent === 'Cotton · archived',
    ),
  ).toHaveAttribute('aria-disabled', 'true');
  expect(screen.getByText('archived_option_warning')).toBeInTheDocument();
  const old = screen.getByRole('group', { name: 'Old note · archived' });
  expect(within(old).getByLabelText('English')).toBeDisabled();
  choose(material, 'Linen');
  expect(options(material).map((option) => option.textContent)).toEqual([
    'unset',
    'Linen',
  ]);
});
it('requires saving a changed category before editing its refreshed feature set', () => {
  render(
    <Harness categoryChanged data={editor([feature('Wireless', 'boolean')])} />,
  );
  expect(screen.getByLabelText('Wireless')).toBeDisabled();
  expect(screen.getByText('category_save_first')).toBeInTheDocument();
});
it('shows concrete impact and requires an explicit unchecked confirmation', () => {
  const confirm = vi.fn();
  render(
    <FeatureImpact
      preview={{ impact: { assigned_products: 3 }, token: 'token' }}
      confirmed={false}
      onConfirm={confirm}
    />,
  );
  expect(screen.getByText('assigned_products: 3')).toBeInTheDocument();
  expect(screen.getByRole('checkbox')).not.toBeChecked();
  fireEvent.click(screen.getByRole('checkbox'));
  expect(confirm).toHaveBeenCalledWith(true);
});

it('lays features out in group cards with unit suffixes, language rows and selectable chips', () => {
  shared.props.i18n.strings['common.selected'] = ':count selected';
  render(
    <Harness
      data={editor([
        feature('Weight', 'number', { required: true }),
        feature('Notes', 'text'),
        feature('Connections', 'multiple', {
          unit: '',
          options: [
            {
              id: '7',
              version: 1,
              status: 'active',
              label: 'USB-C',
              translations: {},
            },
            {
              id: '8',
              version: 1,
              status: 'active',
              label: 'HDMI',
              translations: {},
            },
          ],
        }),
      ])}
    />,
  );
  const card = screen.getByRole('region', { name: 'General' });
  // Number: the unit is shown inside the field; required features are marked.
  expect(within(card).getByLabelText('Weight')).toBeVisible();
  expect(within(card).getByText('g')).toBeVisible();
  expect(within(card).getByRole('group', { name: 'Weight' })).toHaveTextContent(
    '*',
  );
  // Text: one row per language, each labelled by its language.
  expect(within(card).getByLabelText('English')).toHaveAttribute('lang', 'en');
  expect(within(card).getByLabelText('Ελληνικά')).toHaveAttribute('lang', 'el');
  // Multiple choice: chips are real checkboxes with a selection count.
  const usbc = within(card).getByRole('checkbox', { name: 'USB-C' });
  fireEvent.click(usbc);
  expect(usbc).toBeChecked();
  expect(within(card).getByText('1 selected')).toBeVisible();
  expect(screen.getByTestId('values')).toHaveTextContent('"options":["7"]');
  fireEvent.click(usbc);
  expect(usbc).not.toBeChecked();
});
