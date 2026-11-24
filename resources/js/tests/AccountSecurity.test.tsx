import { act, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { LocalTime } from '../components/admin/AccountSecuritySummary';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
afterEach(() => vi.useRealTimers());

it('updates local time using the recorded timezone', () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-01-10T12:00:00Z'));
  render(<LocalTime timezone="UTC" />);
  expect(screen.getByText(/12:00/)).toHaveTextContent('UTC');
  act(() => {
    vi.advanceTimersByTime(60000);
  });
  expect(screen.getByText(/12:01/)).toBeInTheDocument();
});

it('handles unavailable and invalid timezone data without crashing the admin', () => {
  const view = render(<LocalTime timezone={null} />);
  expect(screen.getByText('account_security.unknown')).toBeInTheDocument();
  view.rerender(<LocalTime timezone="Invalid/Zone" />);
  expect(screen.getByText(/account_security.unknown/)).toBeInTheDocument();
});
