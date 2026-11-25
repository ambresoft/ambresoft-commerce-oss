import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import AttachmentUploader, {
  removeAttachmentUpload,
} from '../components/admin/AttachmentUploader';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: { i18n: { locale: 'en' } } }),
}));

it('reuses the drop area and offers rename, replace, order, remove and open controls', () => {
  const add = vi.fn(),
    name = vi.fn(),
    replace = vi.fn(),
    move = vi.fn(),
    remove = vi.fn(),
    cancel = vi.fn();
  const pending = new File(['pdf'], 'new.pdf', { type: 'application/pdf' });
  const { container } = render(
    <AttachmentUploader
      title="Attachments"
      items={[
        {
          file: {
            id: '4',
            display_name: 'Size guide',
            original_name: 'sizes.pdf',
            byte_size: 2048,
            url: '/admin/brands/1/files/4',
          },
          index: 2,
        },
      ]}
      pending={[{ file: pending, index: 1 }]}
      onAdd={add}
      onName={name}
      onReplace={replace}
      onMove={move}
      onRemove={remove}
      onRemovePending={cancel}
    />,
  );
  const input = screen.getByLabelText('Attachments', { selector: 'input' });
  expect(input).toHaveAttribute(
    'accept',
    '.pdf,.zip,.txt,.jpg,.jpeg,.png,.webp',
  );
  fireEvent.change(input, { target: { files: [pending] } });
  expect(add).toHaveBeenCalledWith([pending]);
  const drop = container.querySelector('.border-dashed')!;
  fireEvent.drop(drop, {
    dataTransfer: { types: ['Files'], files: [pending] },
  });
  expect(add).toHaveBeenCalledTimes(2);
  const card = screen.getByRole('article');
  expect(card).toHaveTextContent('2 KB');
  fireEvent.change(within(card).getByDisplayValue('Size guide'), {
    target: { value: 'Shoe sizes' },
  });
  expect(name).toHaveBeenCalledWith(2, 'Shoe sizes');
  expect(
    within(card).getByRole('link', { name: 'media.file_open' }),
  ).toHaveAttribute('href', '/admin/brands/1/files/4');
  fireEvent.change(within(card).getByLabelText('products.replace'), {
    target: { files: [pending] },
  });
  expect(replace).toHaveBeenCalledWith(2, pending);
  expect(
    within(card).getByRole('button', { name: 'products.up' }),
  ).toBeDisabled();
  expect(
    within(card).getByRole('button', { name: 'products.down' }),
  ).toBeDisabled();
  fireEvent.click(
    within(card).getByRole('button', { name: 'products.remove' }),
  );
  expect(remove).toHaveBeenCalledWith(2);
  fireEvent.click(
    screen.getAllByRole('button', { name: 'products.remove' })[1],
  );
  expect(cancel).toHaveBeenCalledWith(1);
});

it('removing queued files keeps replacement indexes and digital download purposes aligned', () => {
  const uploads = ['one.pdf', 'two.pdf', 'three.pdf'].map(
    (name) => new File(['x'], name),
  );
  const state = {
    files: [
      {
        id: '1',
        display_name: 'One',
        original_name: 'one.pdf',
        replacement: 0,
      },
      {
        id: '2',
        display_name: 'Two',
        original_name: 'two.pdf',
        replacement: 2,
      },
    ],
    file_uploads: uploads,
    file_upload_purposes: ['download', 'attachment', 'download'],
  };
  const next = removeAttachmentUpload(state, 0);
  expect(next.file_uploads.map((file) => file.name)).toEqual([
    'two.pdf',
    'three.pdf',
  ]);
  expect(next.file_upload_purposes).toEqual(['attachment', 'download']);
  expect(next.files.map((file) => file.replacement)).toEqual([undefined, 1]);
  expect(state.files[0].replacement).toBe(0);
});
