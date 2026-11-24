import { act, fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useUrlTab } from '../lib/useUrlTab';

vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: {}, url: window.location.href }),
  };
});

const tabs = ['general', 'settings', 'options'] as const;
function Tabs() {
  const [tab, setTab] = useUrlTab(tabs);
  return (
    <div>
      {tabs.map((item) => (
        <button
          key={item}
          aria-pressed={tab === item}
          onClick={() => setTab(item)}
        >
          {item}
        </button>
      ))}
    </div>
  );
}
const selected = () =>
  screen.getByRole('button', { pressed: true }).textContent;

it('opens the tab named in the URL and ignores unknown tabs', () => {
  window.history.replaceState(null, '', '/editor?tab=settings');
  const { unmount } = render(<Tabs />);
  expect(selected()).toBe('settings');
  unmount();
  window.history.replaceState(null, '', '/editor?tab=missing');
  render(<Tabs />);
  expect(selected()).toBe('general');
  expect(window.location.search).toBe('');
});

it('writes the selected tab to the URL and keeps other parameters', () => {
  window.history.replaceState(null, '', '/editor?return_to=%2Flist');
  render(<Tabs />);
  fireEvent.click(screen.getByRole('button', { name: 'options' }));
  expect(new URLSearchParams(window.location.search).get('tab')).toBe(
    'options',
  );
  expect(new URLSearchParams(window.location.search).get('return_to')).toBe(
    '/list',
  );
  fireEvent.click(screen.getByRole('button', { name: 'general' }));
  expect(window.location.search).toBe('?return_to=%2Flist');
});

it('reopens the submitted tab when a save redirects back to the same editor', () => {
  window.history.replaceState(null, '', '/editor?tab=settings');
  const { unmount } = render(<Tabs />);
  const start = (method: string) =>
    act(() => {
      document.dispatchEvent(
        new CustomEvent('inertia:start', { detail: { visit: { method } } }),
      );
    });
  start('post');
  unmount();
  window.history.replaceState(null, '', '/editor');
  const second = render(<Tabs />);
  expect(selected()).toBe('settings');
  expect(window.location.search).toBe('?tab=settings');
  start('get');
  second.unmount();
  window.history.replaceState(null, '', '/editor');
  render(<Tabs />);
  expect(selected()).toBe('general');
});
