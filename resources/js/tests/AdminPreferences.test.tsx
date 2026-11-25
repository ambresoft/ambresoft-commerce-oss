import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, afterEach, expect, it, vi } from 'vitest';
import Notifications from '../components/Notifications';
import DateInput, { parseCalendarDate } from '../components/admin/DateInput';
import StoreClosed from '../pages/StoreClosed';
import { useDirtyForm, useUnsavedWarning } from '../lib/useUnsavedChanges';
import { defaultPreferences } from '../lib/usePreferences';
import { formatDate } from '../lib/useDateDisplay';
import { toInstant, toLocalInput } from '../lib/zonedDateTime';
import { english } from './translations';
import type { SharedProps } from '../types/page';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
}));

beforeEach(() => {
  shared.props = {
    app: { name: 'CMS' },
    auth: { user: null, canAccessAdmin: false },
    capabilities: {},
    links: { logout: '/logout' } as SharedProps['links'],
    preferences: { ...defaultPreferences },
    flash: { status: 'Saved successfully' },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [],
      strings: {
        ...english,
        'settings.calendar_open': 'Open calendar',
        'settings.calendar_choose': 'Choose a date',
        'settings.unsaved_confirm': 'Discard unsaved changes?',
      },
    },
  };
});
afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

it('dismisses success messages after the saved duration and keeps duration zero visible', () => {
  vi.useFakeTimers();
  shared.props.preferences!.notification_duration = 2;
  const view = render(<Notifications />);
  act(() => {
    vi.advanceTimersByTime(1999);
  });
  expect(screen.getByRole('status')).toBeVisible();
  act(() => {
    vi.advanceTimersByTime(1);
  });
  expect(screen.queryByRole('status')).toBeNull();
  shared.props.flash = { status: 'Saved again' };
  shared.props.preferences!.notification_duration = 0;
  view.rerender(<Notifications />);
  act(() => {
    vi.advanceTimersByTime(600000);
  });
  expect(screen.getByRole('status')).toHaveTextContent('Saved again');
  fireEvent.click(screen.getByRole('button', { name: 'Close' }));
  expect(screen.queryByRole('status')).toBeNull();
});

function Guard({
  dirty,
  processing = false,
}: {
  dirty: boolean;
  processing?: boolean;
}) {
  useDirtyForm({ isDirty: dirty, processing });
  useUnsavedWarning();
  return null;
}

it('guards dirty navigation and unload, allows saves, and respects the disabled preference', () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  const view = render(<Guard dirty />);
  const navigation = () => {
    const event = new CustomEvent('inertia:before', {
      cancelable: true,
      detail: { visit: { method: 'get' } },
    });
    document.dispatchEvent(event);
    return event;
  };
  expect(navigation().defaultPrevented).toBe(true);
  expect(confirm).toHaveBeenCalledWith('Discard unsaved changes?');
  const save = new CustomEvent('inertia:before', {
    cancelable: true,
    detail: { visit: { method: 'put' } },
  });
  document.dispatchEvent(save);
  expect(save.defaultPrevented).toBe(false);
  const unload = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(unload);
  expect(unload.defaultPrevented).toBe(true);
  view.rerender(<Guard dirty processing />);
  expect(navigation().defaultPrevented).toBe(false);
  shared.props.preferences!.warn_unsaved_changes = false;
  view.rerender(<Guard dirty />);
  expect(navigation().defaultPrevented).toBe(false);
});

it('searches picker options without discarding edits and still guards navigation away', () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<Guard dirty />);
  const lookup = (path: string, preserveState = true) => {
    const event = new CustomEvent('inertia:before', {
      cancelable: true,
      detail: {
        visit: {
          method: 'get',
          url: new URL(path, window.location.href),
          only: ['options'],
          preserveState,
        },
      },
    });
    document.dispatchEvent(event);
    return event;
  };
  expect(lookup(window.location.pathname).defaultPrevented).toBe(false);
  expect(confirm).not.toHaveBeenCalled();
  expect(lookup('/another-editor').defaultPrevented).toBe(true);
  expect(lookup(window.location.pathname, false).defaultPrevented).toBe(true);
});

it('renders the calendar with the selected week start and submits an ISO date', () => {
  shared.props.preferences!.calendar_week_start = 0;
  const change = vi.fn();
  render(
    <>
      <label htmlFor="release">Release date</label>
      <DateInput id="release" value="2026-10-12" onChange={change} />
    </>,
  );
  expect(screen.getByLabelText('Release date')).toHaveValue('12/10/2026');
  fireEvent.click(screen.getByRole('button', { name: 'Open calendar' }));
  const dialog = screen.getByRole('dialog');
  expect(
    within(dialog).getAllByText(/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat)$/)[0],
  ).toHaveTextContent('Sun');
  fireEvent.click(within(dialog).getByRole('gridcell', { name: '15/10/2026' }));
  expect(change).toHaveBeenCalledWith('2026-10-15');
  fireEvent.change(screen.getByLabelText('Release date'), {
    target: { value: '25/10/2026' },
  });
  expect(change).toHaveBeenLastCalledWith('2026-10-25');
  expect(parseCalendarDate('31/02/2026', 'd/m/Y')).toBe('31/02/2026');
});

it('uses the store timezone for date display and scheduled instants across daylight saving', () => {
  expect(
    formatDate(new Date('2026-10-11T22:30:00Z'), 'd/m/Y', 'Europe/Athens'),
  ).toBe('12/10/2026');
  expect(toLocalInput('2026-07-01T12:30:00Z', 'Europe/Athens')).toBe(
    '2026-07-01T15:30',
  );
  expect(toInstant('2026-07-01T15:30', 'Europe/Athens')).toBe(
    '2026-07-01T12:30:00.000Z',
  );
  expect(toInstant('2026-01-01T15:30', 'Europe/Athens')).toBe(
    '2026-01-01T13:30:00.000Z',
  );
  expect(toInstant('2026-03-29T03:30', 'Europe/Athens')).toBe('invalid');
});

it('displays the closed-store message as plain text', () => {
  render(
    <StoreClosed
      storeName="Shop"
      logo={null}
      colors={{ primary: '#05ab7d', background: '#f8f8f8', text: '#333333' }}
      message={'Development <script>alert(1)</script>'}
    />,
  );
  expect(
    screen.getByText('Development <script>alert(1)</script>'),
  ).toBeVisible();
  expect(document.querySelector('script')).toBeNull();
});
