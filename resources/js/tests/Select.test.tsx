import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import Select from '../components/admin/Select';

const fruit = [
  { value: '', label: 'Any' },
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana', disabled: true },
  { value: 'cherry', label: 'Cherry' },
];
function Field() {
  const [value, setValue] = useState('');
  return (
    <>
      <label htmlFor="fruit">Fruit</label>
      <Select id="fruit" value={value} options={fruit} onChange={setValue} />
      <output>{value}</output>
    </>
  );
}

it('is named by its label and chooses an option with the mouse', () => {
  render(<Field />);
  const select = screen.getByRole('combobox', { name: 'Fruit' });
  expect(select).toHaveTextContent('Any');
  fireEvent.click(select);
  expect(screen.getByRole('listbox', { name: 'Fruit' })).toBeVisible();
  fireEvent.click(screen.getByRole('option', { name: 'Cherry' }));
  expect(screen.getByRole('status')).toHaveTextContent('cherry');
  expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  expect(select).toHaveFocus();
});

it('supports keyboard selection, skips disabled options and closes on Escape', () => {
  render(<Field />);
  const select = screen.getByRole('combobox', { name: 'Fruit' });
  fireEvent.keyDown(select, { key: 'ArrowDown' });
  expect(select).toHaveAttribute('aria-expanded', 'true');
  fireEvent.keyDown(select, { key: 'ArrowDown' });
  fireEvent.keyDown(select, { key: 'ArrowDown' });
  expect(
    document.getElementById(select.getAttribute('aria-activedescendant')!),
  ).toHaveTextContent('Cherry');
  fireEvent.keyDown(select, { key: 'Enter' });
  expect(screen.getByRole('status')).toHaveTextContent('cherry');
  fireEvent.keyDown(select, { key: 'ArrowDown' });
  fireEvent.keyDown(select, { key: 'Escape' });
  expect(select).toHaveAttribute('aria-expanded', 'false');
  expect(screen.getByRole('status')).toHaveTextContent('cherry');
});

it('jumps to an option by typing and closes on an outside click', () => {
  render(<Field />);
  const select = screen.getByRole('combobox', { name: 'Fruit' });
  fireEvent.keyDown(select, { key: 'a' });
  fireEvent.keyDown(select, { key: 'p' });
  expect(
    document.getElementById(select.getAttribute('aria-activedescendant')!),
  ).toHaveTextContent('Apple');
  fireEvent.pointerDown(document.body);
  expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  expect(screen.getByRole('status')).toHaveTextContent('');
});

it('opens in place without scrolling the page', () => {
  const scroll = vi.fn();
  Element.prototype.scrollIntoView = scroll;
  render(<Field />);
  const select = screen.getByRole('combobox', { name: 'Fruit' });
  fireEvent.click(select);
  expect(screen.getByRole('listbox')).toHaveStyle({ position: 'fixed' });
  fireEvent.keyDown(select, { key: 'End' });
  expect(scroll).not.toHaveBeenCalled();
});

it('filters searchable options by name or code without accents or case and keeps focus while typing', () => {
  const changed = vi.fn();
  render(
    <Select
      aria-label="Country"
      value="GR"
      options={[
        { value: 'GR', label: 'Ελλάδα' },
        { value: 'US', label: 'United States' },
      ]}
      onChange={changed}
      search={{ label: 'Search countries', emptyMessage: 'No countries found' }}
    />,
  );
  fireEvent.click(screen.getByRole('combobox', { name: 'Country' }));
  const search = screen.getByRole('combobox', { name: 'Search countries' });
  expect(search).toHaveFocus();
  fireEvent.change(search, { target: { value: 'ελλα' } });
  expect(screen.getByRole('option', { name: 'Ελλάδα' })).toBeVisible();
  expect(
    screen.queryByRole('option', { name: 'United States' }),
  ).not.toBeInTheDocument();
  expect(changed).not.toHaveBeenCalled();
  fireEvent.change(search, { target: { value: 'us' } });
  fireEvent.keyDown(search, { key: 'Enter' });
  expect(changed).toHaveBeenCalledExactlyOnceWith('US');
  expect(screen.getByRole('combobox', { name: 'Country' })).toHaveFocus();
});

it('shows no matches, cancels without changing the value, and resets search on reopening', () => {
  const changed = vi.fn();
  render(
    <Select
      aria-label="Fruit"
      value="apple"
      options={fruit}
      onChange={changed}
      search={{ label: 'Find fruit', emptyMessage: 'No matches' }}
    />,
  );
  const trigger = screen.getByRole('combobox', { name: 'Fruit' });
  fireEvent.click(trigger);
  const search = screen.getByRole('combobox', { name: 'Find fruit' });
  fireEvent.change(search, { target: { value: 'not found' } });
  expect(screen.getByRole('status')).toHaveTextContent('No matches');
  fireEvent.keyDown(search, { key: 'Enter' });
  expect(changed).not.toHaveBeenCalled();
  fireEvent.keyDown(search, { key: 'Escape' });
  expect(trigger).toHaveFocus();
  expect(trigger).toHaveTextContent('Apple');
  fireEvent.click(trigger);
  expect(screen.getByRole('combobox', { name: 'Find fruit' })).toHaveValue('');
  expect(screen.getAllByRole('option')).toHaveLength(4);
  fireEvent.keyDown(screen.getByRole('combobox', { name: 'Find fruit' }), {
    key: 'ArrowDown',
  });
  fireEvent.keyDown(screen.getByRole('combobox', { name: 'Find fruit' }), {
    key: 'Enter',
  });
  expect(changed).toHaveBeenCalledExactlyOnceWith('cherry');
});

it('renders its list inside an open modal dialog so the top layer does not hide it', () => {
  render(
    <dialog open>
      <Field />
    </dialog>,
  );
  fireEvent.click(screen.getByRole('combobox', { name: 'Fruit' }));
  expect(
    screen.getByRole('listbox', { name: 'Fruit' }).closest('dialog'),
  ).not.toBeNull();
});

it('shows status colors while keeping text labels and keyboard selection', () => {
  const onChange = vi.fn();
  render(
    <Select
      aria-label="Order status"
      value="paid"
      onChange={onChange}
      options={[
        { value: 'paid', label: 'Paid', color: '#008000' },
        { value: 'cancelled', label: 'Cancelled', color: '#ff0000' },
      ]}
    />,
  );
  const trigger = screen.getByRole('combobox', { name: 'Order status' });
  expect(trigger).toHaveTextContent('Paid');
  expect(trigger.querySelector('[aria-hidden="true"][style]')).toHaveStyle({
    backgroundColor: '#008000',
  });
  fireEvent.click(trigger);
  const cancelled = screen.getByRole('option', { name: 'Cancelled' });
  expect(cancelled.querySelector('[aria-hidden="true"][style]')).toHaveStyle({
    backgroundColor: '#ff0000',
  });
  fireEvent.keyDown(trigger, { key: 'ArrowDown' });
  fireEvent.keyDown(trigger, { key: 'Enter' });
  expect(onChange).toHaveBeenCalledWith('cancelled');
});
