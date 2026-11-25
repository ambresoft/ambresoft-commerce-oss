import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import {
  ImageUploader,
  SingleImageUploader,
} from '../components/admin/ImageUploader';

vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () =>
    (key: string, replacements: Record<string, string | number> = {}) =>
      Object.entries(replacements).reduce(
        (text, [name, value]) => text + ' ' + name + '=' + value,
        key,
      ),
}));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: { i18n: { locale: 'en' } } }),
}));

const png = (name: string, size = 1000) =>
  new File([new Uint8Array(size)], name, { type: 'image/png' });

function Gallery({ progress = null }: { progress?: number | null }) {
  const [items, setItems] = useState(['1', '2', '3']);
  const [primary, setPrimary] = useState('1');
  const [files, setFiles] = useState<File[]>([]);
  return (
    <>
      <ImageUploader
        title="Images"
        items={items.map((id) => ({
          id,
          url: '/img/' + id,
          meta: { size: 1258291, width: 1200, height: 800 },
        }))}
        primary={primary}
        onPrimary={setPrimary}
        onRemove={(id) => setItems(items.filter((item) => item !== id))}
        onReorder={setItems}
        files={files}
        onFiles={setFiles}
        maxNew={2}
        progress={progress}
      />
      <output data-testid="state">
        {JSON.stringify({ items, primary, files: files.map((f) => f.name) })}
      </output>
    </>
  );
}
const fileInput = () =>
  document.querySelector<HTMLInputElement>('input[type="file"]')!;
const state = () => JSON.parse(screen.getByTestId('state').textContent!);
const upload = (files: File[]) =>
  fireEvent.change(fileInput(), { target: { files } });

it('marks the main image, changes it and deletes only after confirmation', () => {
  render(<Gallery />);
  const tiles = screen.getAllByRole('listitem');
  expect(within(tiles[0]).getByText('media.main')).toBeVisible();
  expect(tiles[0]).toHaveTextContent('1.2 MB · 1200 × 800');
  fireEvent.click(
    within(tiles[1]).getByRole('button', { name: /media.set_main/ }),
  );
  expect(state().primary).toBe('2');
  fireEvent.click(
    within(tiles[2]).getByRole('button', { name: /media.delete_named/ }),
  );
  const dialog = within(tiles[2]).getByRole('alertdialog');
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'common.cancel' }),
  );
  expect(state().items).toEqual(['1', '2', '3']);
  fireEvent.click(
    within(tiles[2]).getByRole('button', { name: /media.delete_named/ }),
  );
  fireEvent.click(
    within(tiles[2]).getByRole('button', { name: 'media.delete' }),
  );
  expect(state().items).toEqual(['1', '2']);
});

it('reorders with the keyboard handle', () => {
  render(<Gallery />);
  const handle = screen.getByRole('button', {
    name: 'media.move name=media.image_number number=2',
  });
  fireEvent.keyDown(handle, { key: 'ArrowRight' });
  expect(state().items).toEqual(['1', '3', '2']);
  fireEvent.keyDown(
    screen.getByRole('button', {
      name: 'media.move name=media.image_number number=3',
    }),
    { key: 'ArrowLeft' },
  );
  expect(state().items).toEqual(['1', '2', '3']);
});

it('accepts images, rejects wrong types, oversize files and the count limit, and removes new files', () => {
  render(<Gallery />);
  upload([
    png('front.png'),
    new File(['x'], 'notes.pdf', { type: 'application/pdf' }),
    png('huge.png', 6 * 1024 * 1024),
  ]);
  expect(state().files).toEqual(['front.png']);
  const alert = screen.getByRole('alert');
  expect(alert).toHaveTextContent('media.invalid_type name=notes.pdf');
  expect(alert).toHaveTextContent('media.too_large name=huge.png');
  upload([png('side.png'), png('back.png')]);
  expect(state().files).toEqual(['front.png', 'side.png']);
  expect(screen.getByRole('alert')).toHaveTextContent('media.too_many count=2');
  expect(screen.getByText('media.pending_note')).toBeVisible();
  fireEvent.click(
    screen.getByRole('button', { name: 'media.remove_new name=front.png' }),
  );
  expect(state().files).toEqual(['side.png']);
});

it('shows upload progress on new images while saving', () => {
  const { rerender } = render(<Gallery />);
  upload([png('open.png', 1024 * 1024)]);
  rerender(<Gallery progress={68} />);
  expect(screen.getByRole('progressbar')).toHaveAttribute(
    'aria-valuenow',
    '68',
  );
  expect(screen.getByText('68%')).toBeVisible();
  expect(
    screen.queryByRole('button', { name: /media.remove_new/ }),
  ).not.toBeInTheDocument();
});

function Single({ withCurrent = true }: { withCurrent?: boolean }) {
  const [file, setFile] = useState<File | null>(null);
  const [removed, setRemoved] = useState(false);
  return (
    <>
      <SingleImageUploader
        title="Category image"
        current={
          withCurrent
            ? { url: '/cat.png', meta: { size: 2048, width: 300, height: 300 } }
            : null
        }
        alt="Shoes"
        file={file}
        onFile={setFile}
        removed={removed}
        onRemoved={setRemoved}
      />
      <output data-testid="state">
        {JSON.stringify({ file: file?.name ?? null, removed })}
      </output>
    </>
  );
}

it('replaces, removes and restores a single image', () => {
  render(<Single />);
  expect(screen.getByAltText('Shoes')).toBeVisible();
  expect(
    screen.getByRole('button', { name: 'media.choose_one' }),
  ).toBeVisible();
  // Removal needs confirmation and can be undone until saving.
  fireEvent.click(screen.getByRole('button', { name: /media.delete_named/ }));
  fireEvent.click(screen.getByRole('button', { name: 'media.delete' }));
  expect(state()).toEqual({ file: null, removed: true });
  expect(screen.queryByAltText('Shoes')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'media.undo' }));
  expect(state()).toEqual({ file: null, removed: false });
  // Choosing a file replaces the current image; only one is kept.
  fireEvent.change(fileInput(), {
    target: { files: [png('a.png'), png('b.png')] },
  });
  expect(state()).toEqual({ file: 'a.png', removed: false });
  expect(screen.queryByAltText('Shoes')).not.toBeInTheDocument();
  expect(screen.getByText('a.png')).toBeVisible();
  expect(fileInput()).not.toHaveAttribute('multiple');
});

it('starts empty when no image exists', () => {
  render(<Single withCurrent={false} />);
  expect(screen.queryByRole('list')).not.toBeInTheDocument();
  expect(screen.getByText('media.drop_one')).toBeVisible();
});
