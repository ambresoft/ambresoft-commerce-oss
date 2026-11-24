import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import CodeEditor from '../pages/Admin/CodeEditor/Index';

const mocks = vi.hoisted(() => ({
  request: vi.fn(),
  before: null as
    | ((event: {
        preventDefault: () => void;
        detail: { visit: { url: string } };
      }) => void)
    | null,
  visit: vi.fn(),
}));
vi.mock('../lib/faqRequest', () => ({
  faqRequest: (...args: unknown[]) => mocks.request(...args),
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
  router: {
    on: (_name: string, callback: typeof mocks.before) => {
      mocks.before = callback;
      return () => {
        mocks.before = null;
      };
    },
    visit: (...args: unknown[]) => mocks.visit(...args),
  },
}));
// The interaction tests exercise editor state independently of CodeMirror's browser layout engine.
vi.mock('../components/admin/ThemeSourceEditor', () => ({
  default: ({
    initial,
    onChange,
    onSave,
  }: {
    initial: string;
    onChange: (v: string) => void;
    onSave: () => void;
  }) => (
    <input
      aria-label="Source"
      defaultValue={initial}
      onChange={(e) => onChange(e.target.value)}
      onKeyDown={(e) => {
        if (e.ctrlKey && e.key === 's') onSave();
      }}
    />
  ),
}));

const file = {
  path: 'theme.css',
  content: 'old{}',
  version: 'version-1',
  pending: false,
  conflict: false,
};
beforeEach(() => {
  mocks.request.mockReset();
  mocks.visit.mockReset();
  mocks.before = null;
  mocks.request.mockResolvedValue(file);
});
async function setup() {
  render(
    <CodeEditor
      files={['theme.css', 'Card.tsx']}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.click(screen.getByText('theme.css'));
  await screen.findByLabelText('Source');
}
it('saves a draft only after server confirmation and keeps edits made during save', async () => {
  await setup();
  fireEvent.change(screen.getByLabelText('Source'), {
    target: { value: 'new{}' },
  });
  let resolve!: (v: typeof file) => void;
  mocks.request.mockReturnValueOnce(
    new Promise((r) => {
      resolve = r;
    }),
  );
  fireEvent.keyDown(screen.getByLabelText('Source'), {
    key: 's',
    ctrlKey: true,
  });
  expect(screen.queryByText('code_editor.saved')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Source'), {
    target: { value: 'newer{}' },
  });
  resolve({ ...file, content: 'new{}', pending: true });
  await screen.findByText('code_editor.saved');
  expect(screen.getByLabelText('Source')).toHaveValue('newer{}');
  expect(screen.getByText(/code_editor.unsaved/)).toBeInTheDocument();
  expect(mocks.request).toHaveBeenLastCalledWith('/admin/code-editor/file', {
    path: 'theme.css',
    content: 'new{}',
    version: 'version-1',
  });
});
it('retains unsaved source on failure and prevents overlapping requests', async () => {
  await setup();
  fireEvent.change(screen.getByLabelText('Source'), {
    target: { value: 'new{}' },
  });
  mocks.request.mockRejectedValueOnce(new Error('Conflict: reopen file'));
  fireEvent.click(screen.getByText('common.save'));
  fireEvent.keyDown(screen.getByLabelText('Source'), {
    key: 's',
    ctrlKey: true,
  });
  await screen.findByRole('alert');
  expect(mocks.request).toHaveBeenCalledTimes(2);
  expect(screen.getByLabelText('Source')).toHaveValue('new{}');
  expect(screen.queryByText('code_editor.saved')).not.toBeInTheDocument();
});
it('requires confirmation to discard or switch files and restores the last saved draft', async () => {
  await setup();
  fireEvent.change(screen.getByLabelText('Source'), {
    target: { value: 'new{}' },
  });
  fireEvent.click(screen.getByText('Card.tsx'));
  expect(mocks.request).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByText('common.cancel'));
  expect(screen.getByLabelText('Source')).toHaveValue('new{}');
  fireEvent.click(screen.getAllByText('code_editor.discard')[0]);
  fireEvent.click(screen.getAllByText('code_editor.discard')[1]);
  expect(screen.getByLabelText('Source')).toHaveValue('old{}');
  expect(screen.queryByText(/code_editor.unsaved/)).not.toBeInTheDocument();
});
it('guards browser close and Inertia navigation while dirty', async () => {
  await setup();
  fireEvent.change(screen.getByLabelText('Source'), {
    target: { value: 'new{}' },
  });
  const unload = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(unload);
  expect(unload.defaultPrevented).toBe(true);
  const preventDefault = vi.fn();
  mocks.before?.({ preventDefault, detail: { visit: { url: '/admin' } } });
  expect(preventDefault).toHaveBeenCalled();
  fireEvent.click(screen.getAllByText('code_editor.discard')[1]);
  expect(mocks.visit).toHaveBeenCalledWith('/admin', { url: '/admin' });
});
it('preserves current content when opening another file fails', async () => {
  await setup();
  mocks.request.mockRejectedValueOnce(new Error('Missing file'));
  fireEvent.click(screen.getByText('Card.tsx'));
  await waitFor(() =>
    expect(screen.getByRole('alert')).toHaveTextContent('Missing file'),
  );
  expect(screen.getByLabelText('Source')).toHaveValue('old{}');
});
