import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import RolePermissions from '../components/admin/RolePermissions';

vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () => (key: string, values?: Record<string, string | number>) => {
      const text: Record<string, string> = {
        'roles.permission_search': 'Search all permissions',
        'roles.permission_groups': 'Permission categories',
        'roles.permission_group_catalog': 'Catalog',
        'roles.permission_group_orders': 'Orders',
        'roles.permission_group_access': 'Staff & access',
        'roles.permission_group_other': 'Add-ons & other',
        'roles.permission_group_all': 'All permissions',
        'roles.permission_no_results': 'No matching permissions',
        'roles.permission_clear_search': 'Clear search',
        'roles.permission_summary': `${values?.shown} of ${values?.total} · ${values?.selected} selected`,
      };
      return text[key] ?? key;
    },
}));
const permissions = [
  { name: 'products.view', label: 'Προβολή προϊόντων' },
  { name: 'products.update', label: 'Επεξεργασία προϊόντων' },
  { name: 'orders.view', label: 'View orders' },
  { name: 'users.set-password', label: 'Set staff passwords' },
  { name: 'acme.reviews.moderate', label: 'Moderate add-on reviews' },
];
function Picker({
  initial = ['orders.view'],
  disabled = false,
}: {
  initial?: string[];
  disabled?: boolean;
}) {
  const [selected, setSelected] = useState(initial);
  return (
    <>
      <RolePermissions
        permissions={permissions}
        selected={selected}
        onChange={setSelected}
        disabled={disabled}
      />
      <output aria-label="Submitted permissions">{selected.join(',')}</output>
    </>
  );
}
it('splits permissions into categories and preserves selections across category switches', () => {
  render(<Picker initial={['orders.view', 'hidden.permission']} />);
  expect(screen.getByRole('tab', { name: 'Catalog' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  expect(
    screen.queryByRole('checkbox', { name: /View orders/ }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: /Προβολή προϊόντων/ }));
  fireEvent.click(screen.getByRole('tab', { name: 'Orders' }));
  expect(screen.getByRole('checkbox', { name: /View orders/ })).toBeChecked();
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  expect(
    screen.getByRole('checkbox', { name: /Προβολή προϊόντων/ }),
  ).toBeChecked();
  expect(screen.getByLabelText('Submitted permissions')).toHaveTextContent(
    'orders.view,hidden.permission,products.view',
  );
  expect(
    within(screen.getByRole('tab', { name: 'Catalog' })).getByText('1/2'),
  ).toBeVisible();
});
it('searches every category by translated label and permission code, ignoring accents', () => {
  render(<Picker />);
  fireEvent.change(
    screen.getByRole('searchbox', { name: 'Search all permissions' }),
    { target: { value: 'password' } },
  );
  expect(screen.getByRole('tab', { name: 'All permissions' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  expect(
    screen.getByRole('checkbox', { name: /Set staff passwords/ }),
  ).toBeVisible();
  expect(screen.getAllByRole('checkbox')).toHaveLength(1);
  fireEvent.change(screen.getByRole('searchbox'), {
    target: { value: 'ΠΡΟΒΟΛΗ' },
  });
  expect(
    screen.getByRole('checkbox', { name: /Προβολή προϊόντων/ }),
  ).toBeVisible();
  fireEvent.change(screen.getByRole('searchbox'), {
    target: { value: 'orders.view' },
  });
  expect(screen.getByRole('checkbox', { name: /View orders/ })).toBeChecked();
  expect(screen.getByLabelText('Submitted permissions')).toHaveTextContent(
    'orders.view',
  );
});
it('keeps unknown add-on permissions discoverable and handles empty results', () => {
  render(<Picker />);
  fireEvent.click(screen.getByRole('tab', { name: 'Add-ons & other' }));
  expect(
    screen.getByRole('checkbox', { name: /Moderate add-on reviews/ }),
  ).toBeVisible();
  fireEvent.change(screen.getByRole('searchbox'), {
    target: { value: 'not-present' },
  });
  expect(screen.getByText('No matching permissions')).toBeVisible();
  expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
  fireEvent.click(screen.getByRole('button', { name: 'Clear search' }));
  expect(screen.getAllByRole('checkbox')).toHaveLength(5);
});
it('supports arrow-key navigation and disables permission changes while saving', () => {
  render(<Picker disabled />);
  fireEvent.keyDown(screen.getByRole('tab', { name: 'Catalog' }), {
    key: 'ArrowRight',
  });
  expect(screen.getByRole('tab', { name: 'Orders' })).toHaveFocus();
  expect(screen.getByRole('tab', { name: 'Orders' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  expect(screen.getByRole('checkbox', { name: /View orders/ })).toBeDisabled();
});

it('does not submit the role form when Enter is pressed in live search', () => {
  render(<Picker />);
  const event = new KeyboardEvent('keydown', {
    key: 'Enter',
    bubbles: true,
    cancelable: true,
  });
  screen.getByRole('searchbox').dispatchEvent(event);
  expect(event.defaultPrevented).toBe(true);
});
