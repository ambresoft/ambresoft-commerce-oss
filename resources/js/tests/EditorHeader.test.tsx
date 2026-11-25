import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import EditorHeader from '../components/admin/EditorHeader';
import EditorActionButton from '../components/admin/EditorActionButton';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

it('submits its owning form while preserving required-field validation outside the form', () => {
  const submit = vi.fn((event) => event.preventDefault());
  render(
    <>
      <EditorHeader
        heading="Edit category"
        title="Shoes"
        actions={
          <EditorActionButton form="category-form">Save</EditorActionButton>
        }
      />
      <form id="category-form" onSubmit={submit}>
        <input aria-label="Name" required />
      </form>
    </>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(submit).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Trainers' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(submit).toHaveBeenCalledTimes(1);
});

it('offers destructive actions only inside the menu and hides an empty menu', () => {
  const remove = vi.fn();
  const view = render(
    <EditorHeader
      heading="Page"
      actions={<EditorActionButton>Save</EditorActionButton>}
      menu={[
        false,
        <button key="delete" onClick={remove}>
          Delete page
        </button>,
      ]}
    />,
  );
  expect(screen.queryByRole('button', { name: 'Delete page' })).toBeNull();
  const more = screen.getByRole('button', { name: 'common.actions' });
  fireEvent.click(more);
  fireEvent.click(screen.getByRole('button', { name: 'Delete page' }));
  expect(remove).toHaveBeenCalledTimes(1);
  expect(more).toHaveAttribute('aria-expanded', 'false');
  view.rerender(<EditorHeader heading="Page" menu={[false, null]} />);
  expect(screen.queryByRole('button', { name: 'common.actions' })).toBeNull();
});

it('preserves a destructive form submission inside the menu', () => {
  const submit = vi.fn((event) => event.preventDefault());
  render(
    <EditorHeader
      heading="Currency"
      menu={
        <form onSubmit={submit}>
          <button>Delete currency</button>
        </form>
      }
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'common.actions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Delete currency' }));
  expect(submit).toHaveBeenCalledTimes(1);
});
