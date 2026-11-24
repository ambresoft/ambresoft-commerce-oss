import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import FacebookTypeField from '../components/admin/FacebookTypeField';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

const types = { products: ['book', 'product'], websites: ['website', 'blog'] };

it('offers the server-provided object types in groups and reports the choice', () => {
  const onChange = vi.fn();
  render(
    <FacebookTypeField value="product" types={types} onChange={onChange} />,
  );
  const select = screen.getByLabelText('pages.facebook_type');
  expect(select).toHaveValue('product');
  expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual([
    'book',
    'product',
    'website',
    'blog',
  ]);
  fireEvent.change(select, { target: { value: 'book' } });
  expect(onChange).toHaveBeenCalledWith('book');
});

it('cannot be changed when read-only and shows the server error', () => {
  render(
    <FacebookTypeField
      value="blog"
      types={types}
      onChange={() => {}}
      disabled
      error="Choose a valid option."
    />,
  );
  expect(screen.getByLabelText('pages.facebook_type')).toBeDisabled();
  expect(screen.getByText('Choose a valid option.')).toBeVisible();
});
