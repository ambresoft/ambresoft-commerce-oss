import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Ordering from '../pages/Admin/Features/Ordering';
import type { Feature, FeatureGroup } from '../types/features';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const strings = {
  'features.title': 'Features',
  'features.ordering': 'Ordering',
  'features.ordering_help': 'Drag from the handle',
  'features.ordering_changes': 'Order changes',
  'features.ordering_unsaved': 'Not saved yet.',
  'features.ordering_no_changes': 'No changes yet.',
  'features.ordering_above': 'above',
  'features.ordering_below': 'below',
  'features.ordering_position': ':group · position :position',
  'features.ordering_group_moved': 'Group · position :position',
  'features.ordering_moving': 'Move in progress…',
  'features.ordering_changed': 'Changed',
  'features.ordering_undo': 'Undo',
  'features.ordering_save': 'Save order',
  'features.ordering_discard': 'Discard changes',
  'features.ordering_atomic': 'One transaction',
  'features.ordering_keyboard_label': 'With the keyboard:',
  'features.ordering_keyboard': 'press :up or :down to move.',
  'features.move_to_group': 'Move to group',
  'features.drop_here': 'Drop here',
  'features.drag_handle': 'Drag to reorder: :name',
  'features.up': 'Move up',
  'features.down': 'Move down',
  'features.archived': 'Archived',
};
const shell = { navigation: [], breadcrumbs: [] };
const group = (id: string, name: string): FeatureGroup => ({
  id,
  version: 1,
  name,
  protected: false,
  translations: {},
});
const feature = (
  id: string,
  name: string,
  group_id: string,
  status = 'active',
): Feature =>
  ({ id, name, code: name.toLowerCase(), group_id, status }) as Feature;
const groups = [
  group('1', 'General'),
  group('2', 'Specs'),
  group('3', 'Empty'),
];
const features = [
  feature('10', 'Color', '1'),
  feature('11', 'Brand', '1'),
  feature('12', 'Battery', '2'),
  feature('13', 'Screen', '2'),
  feature('14', 'Legacy', '2', 'archived'),
];

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
    capabilities: { 'features.order': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

const view = () =>
  render(
    <Ordering
      {...shell}
      groups={groups}
      features={features}
      catalogVersion={7}
    />,
  );
const order = (name: string) =>
  Array.from(
    screen.getByRole('region', { name }).querySelectorAll('[data-feature]'),
  ).map((li) => li.textContent?.replace(/Move to group.*$/, '').trim());
const panel = () =>
  within(screen.getByRole('complementary', { name: 'Order changes' }));

it('shows every group and feature in saved order with nothing to save yet', () => {
  view();
  expect(order('General')).toEqual(['1Color', '2Brand']);
  expect(order('Specs')).toEqual(['1Battery', '2Screen', '3LegacyArchived']);
  expect(order('Empty')).toEqual([]);
  expect(screen.getByText('No changes yet.')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Save order' })).toBeDisabled();
  expect(
    screen.getByRole('button', { name: 'Discard changes' }),
  ).toBeDisabled();
});

it('records a move, marks the feature as changed and can undo or discard it', () => {
  view();
  fireEvent.click(screen.getByRole('button', { name: 'Move up: Brand' }));
  expect(order('General')).toEqual(['1Brand', '2Color']);
  expect(screen.getAllByRole('img', { name: 'Changed' })).toHaveLength(2);
  expect(panel().getByText('Brand')).toBeVisible();
  expect(panel().getByText(/above/)).toBeVisible();
  expect(panel().getByText('General · position 1')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Save order' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
  expect(order('General')).toEqual(['1Color', '2Brand']);
  expect(screen.getByRole('button', { name: 'Save order' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Move down: Color' }));
  fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
  expect(order('General')).toEqual(['1Color', '2Brand']);
  expect(screen.getByText('No changes yet.')).toBeVisible();
});

it('moves a feature with the keyboard from its handle', () => {
  view();
  const handle = screen.getByRole('button', {
    name: 'Drag to reorder: Battery',
  });
  fireEvent.keyDown(handle, { key: 'ArrowDown' });
  expect(order('Specs').slice(0, 2)).toEqual(['1Screen', '2Battery']);
  fireEvent.keyDown(handle, { key: 'ArrowUp' });
  expect(order('Specs').slice(0, 2)).toEqual(['1Battery', '2Screen']);
  // The first row cannot move above its group, and the layout is back to the saved order.
  fireEvent.keyDown(handle, { key: 'ArrowUp' });
  expect(order('Specs').slice(0, 2)).toEqual(['1Battery', '2Screen']);
  expect(screen.getByRole('button', { name: 'Save order' })).toBeDisabled();
});

it('appends a feature to the chosen group and keeps archived features in theirs', () => {
  view();
  fireEvent.click(
    screen.getByRole('button', { name: 'Move to group: Battery' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Empty' }));
  expect(order('Empty')).toEqual(['1Battery']);
  expect(order('Specs').slice(0, 2)).toEqual([
    '1Screen',
    '2Legacy' + 'Archived',
  ]);
  expect(
    screen.queryByRole('button', { name: 'Move to group: Legacy' }),
  ).not.toBeInTheDocument();
  expect(panel().getByText('Empty · position 1')).toBeVisible();
});

it('reorders groups with the group arrows', () => {
  view();
  const specs = screen.getByRole('region', { name: 'Specs' });
  fireEvent.click(within(specs).getAllByRole('button', { name: 'Move up' })[0]);
  const names = screen
    .getAllByRole('region')
    .map((r) => r.getAttribute('aria-label'));
  expect(names).toEqual(['Specs', 'General', 'Empty']);
  expect(panel().getByText('Group · position 1')).toBeVisible();
});

it('moves a feature to another group by dragging it onto the drop zone', () => {
  view();
  const row = screen
    .getByRole('region', { name: 'General' })
    .querySelector('[data-feature="10"]')!;
  fireEvent.dragStart(row);
  expect(screen.getByText('Move in progress…')).toBeVisible();
  const zones = screen.getAllByText('Drop here');
  fireEvent.dragOver(zones[2]);
  fireEvent.drop(zones[2]);
  expect(order('Empty')).toEqual(['1Color']);
  expect(order('General')).toEqual(['1Brand']);
  expect(screen.queryByText('Drop here')).not.toBeInTheDocument();
});

it('submits the whole order with the catalog version it was loaded at', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  view();
  fireEvent.click(screen.getByRole('button', { name: 'Move down: Color' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save order' }));
  expect(request.mock.calls[0][0]).toBe('/admin/features/ordering');
  expect(request.mock.calls[0][1]?.data).toEqual({
    catalog_version: 7,
    groups: [
      { id: '1', features: ['11', '10'] },
      { id: '2', features: ['12', '13', '14'] },
      { id: '3', features: [] },
    ],
  });
});
