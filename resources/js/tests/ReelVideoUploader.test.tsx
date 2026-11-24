import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import VideoUploader from '../pages/Admin/Reels/VideoUploader';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
beforeEach(() => {
  vi.stubGlobal('URL', {
    createObjectURL: vi.fn(() => 'blob:video-preview'),
    revokeObjectURL: vi.fn(),
  });
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function Editor({ disabled = false }: { disabled?: boolean }) {
  const [file, setFile] = useState<File | null>(null);
  return (
    <VideoUploader
      file={file}
      current="/saved.mp4"
      maxBytes={1024}
      progress={null}
      disabled={disabled}
      onFile={setFile}
    />
  );
}

it('previews a selected video and removing the draft restores the saved video', async () => {
  const { container, unmount } = render(<Editor />);
  expect(container.querySelector('video')).toHaveAttribute('src', '/saved.mp4');
  const file = new File(['test video'], 'new-reel.mp4', { type: 'video/mp4' });
  fireEvent.change(
    screen.getByLabelText('reels.video', { selector: 'input' }),
    {
      target: { files: [file] },
    },
  );
  expect(screen.getByText('new-reel.mp4')).toBeInTheDocument();
  expect(container.querySelector('video')).toHaveAttribute(
    'src',
    'blob:video-preview',
  );
  fireEvent.click(screen.getByRole('button', { name: 'media.remove_new' }));
  expect(container.querySelector('video')).toHaveAttribute('src', '/saved.mp4');
  await waitFor(() =>
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:video-preview'),
  );
  unmount();
});

it('rejects unsupported or oversized files with visible feedback and keeps the saved video', () => {
  const { container } = render(<Editor />);
  const input = screen.getByLabelText('reels.video', { selector: 'input' });
  fireEvent.change(input, {
    target: {
      files: [new File(['image'], 'picture.png', { type: 'image/png' })],
    },
  });
  expect(screen.getByRole('alert')).toHaveTextContent('reels.video_invalid');
  fireEvent.change(input, {
    target: {
      files: [new File(['x'.repeat(1025)], 'large.mp4', { type: 'video/mp4' })],
    },
  });
  expect(screen.getByRole('alert')).toHaveTextContent('reels.video_invalid');
  expect(container.querySelector('video')).toHaveAttribute('src', '/saved.mp4');
});

it('accepts a video drop and disables choosing files during upload or read-only access', () => {
  const { container, rerender } = render(<Editor />);
  const section = container.querySelector('section')!;
  const file = new File(['video'], 'dropped.mp4', { type: 'video/mp4' });
  fireEvent.drop(section.querySelector('[class*="border-dashed"]')!, {
    dataTransfer: { types: ['Files'], files: [file] },
  });
  expect(screen.getByText('dropped.mp4')).toBeInTheDocument();
  rerender(<Editor disabled />);
  expect(
    screen.getByRole('button', { name: 'reels.video_replace' }),
  ).toBeDisabled();
  expect(
    screen.getByLabelText('reels.video', { selector: 'input' }),
  ).toBeDisabled();
});
