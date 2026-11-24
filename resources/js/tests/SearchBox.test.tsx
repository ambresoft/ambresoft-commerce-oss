import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SearchBox, {
  SUGGEST_DELAY,
} from '../storefront/themes/Default/SearchBox';

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({
  router: { visit: (url: string) => visit(url) },
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, params?: Record<string, unknown>) =>
    params ? `${key} ${JSON.stringify(params)}` : key,
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};

const reply = (names: string[], ok = true) => ({
  ok,
  json: async () => ({
    state: ok ? 'results' : 'unavailable',
    total: names.length,
    currency_format: format,
    items: names.map((name, n) => ({
      id: String(n + 1),
      name,
      url: `/p/${n + 1}`,
      price: '10.00',
      image: null,
    })),
  }),
});

beforeEach(() => {
  vi.useFakeTimers();
  visit.mockReset();
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

// Existing query tests keep their search response mock separate from optional history.
function suggestionsFetch(
  mock: (url: string, options?: RequestInit) => unknown,
) {
  vi.stubGlobal('fetch', (url: string, options?: RequestInit) =>
    url === '/search/recently-viewed'
      ? Promise.resolve({ ok: true, json: async () => ({ items: [] }) })
      : mock(url, options),
  );
}

function field() {
  return screen.getByRole('combobox');
}

async function type(value: string) {
  fireEvent.focus(field());
  fireEvent.change(field(), { target: { value } });
  await act(async () => {
    await vi.advanceTimersByTimeAsync(SUGGEST_DELAY + 10);
  });
}

it('asks once the shopper pauses, shows the products and keeps only the latest answer', async () => {
  const fetchMock = vi.fn().mockResolvedValue(reply(['Wireless mouse']));
  suggestionsFetch(fetchMock);
  render(<SearchBox limit={6} minLength={2} icon={null} />);

  // Below the minimum never asks; quick typing asks once, for the whole word.
  fireEvent.focus(field());
  fireEvent.change(field(), { target: { value: 'm' } });
  fireEvent.change(field(), { target: { value: 'mo' } });
  fireEvent.change(field(), { target: { value: 'mou' } });
  await act(async () => {
    await vi.advanceTimersByTimeAsync(SUGGEST_DELAY + 10);
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  expect(fetchMock.mock.calls[0][0]).toBe('/search/suggest?q=mou&limit=6');
  expect(screen.getByRole('option', { name: /Wireless mouse/ })).toBeTruthy();
  expect(field().getAttribute('aria-expanded')).toBe('true');

  // A newer word aborts the older request.
  await type('mouse');
  const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
  expect(signal.aborted).toBe(true);
});

it('moves through suggestions with the keyboard and opens the chosen one', async () => {
  suggestionsFetch(vi.fn().mockResolvedValue(reply(['Mouse A', 'Mouse B'])));
  const assign = vi.fn();
  vi.stubGlobal('location', { ...window.location, assign, pathname: '/' });
  render(<SearchBox limit={6} icon={null} />);
  await type('mouse');

  fireEvent.keyDown(field(), { key: 'ArrowDown' });
  fireEvent.keyDown(field(), { key: 'ArrowDown' });
  const options = screen.getAllByRole('option');
  expect(field().getAttribute('aria-activedescendant')).toBe(options[1].id);
  // Past the last option ("see all results") the focus returns to the field.
  fireEvent.keyDown(field(), { key: 'ArrowDown' });
  fireEvent.keyDown(field(), { key: 'ArrowDown' });
  expect(field().getAttribute('aria-activedescendant')).toBeNull();
  fireEvent.keyDown(field(), { key: 'ArrowUp' });
  expect(field().getAttribute('aria-activedescendant')).toBe(options[2].id);

  fireEvent.keyDown(field(), { key: 'ArrowUp' });
  fireEvent.submit(field().closest('form')!);
  expect(assign).toHaveBeenCalledWith('/p/2');
});

it('opens the results page on Enter and closes the list on Escape', async () => {
  suggestionsFetch(vi.fn().mockResolvedValue(reply(['Mouse A'])));
  render(<SearchBox limit={6} icon={null} />);
  await type('wireless mouse');
  fireEvent.keyDown(field(), { key: 'Escape' });
  expect(field().getAttribute('aria-expanded')).toBe('false');
  expect((field() as HTMLInputElement).value).toBe('wireless mouse');
  fireEvent.submit(field().closest('form')!);
  expect(visit).toHaveBeenCalledWith('/search?q=wireless%20mouse');
});

it('says when suggestions are unavailable instead of claiming there are none', async () => {
  suggestionsFetch(vi.fn().mockResolvedValue(reply([], false)));
  render(<SearchBox limit={6} icon={null} />);
  await type('mouse');
  expect(screen.getByText('storefront.search_suggestions_error')).toBeTruthy();
  expect(screen.queryByText(/search_no_suggestions/)).toBeNull();
});

it('does not fetch query suggestions when they are switched off', async () => {
  const fetchMock = vi.fn();
  suggestionsFetch(fetchMock);
  render(<SearchBox limit={0} icon={null} />);
  await type('mouse');
  expect(fetchMock).not.toHaveBeenCalled();
});

it('refuses a search shorter than the minimum with a message, without asking or leaving', async () => {
  const fetchMock = vi.fn();
  suggestionsFetch(fetchMock);
  render(<SearchBox limit={6} minLength={3} icon={null} />);
  await type('mo');
  expect(fetchMock).not.toHaveBeenCalled();
  fireEvent.submit(field().closest('form')!);
  expect(visit).not.toHaveBeenCalled();
  expect(screen.getByRole('alert').textContent).toBe(
    'storefront.search_too_short {"count":3}',
  );
  // An empty field is refused too; typing clears the message.
  await type('');
  fireEvent.submit(field().closest('form')!);
  expect(visit).not.toHaveBeenCalled();
  expect(screen.getByRole('alert')).toBeTruthy();
  fireEvent.change(field(), { target: { value: 'mou' } });
  expect(screen.queryByRole('alert')).toBeNull();
});

async function focusEmpty() {
  fireEvent.focus(field());
  await act(async () => {
    await vi.advanceTimersByTimeAsync(SUGGEST_DELAY + 10);
  });
}

it('shows viewing history on empty focus and keeps it above typed suggestions', async () => {
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(reply(['Latest product', 'Earlier product']))
    .mockResolvedValue(reply(['Matching product']));
  vi.stubGlobal('fetch', fetchMock);
  const { container } = render(<SearchBox limit={6} icon={null} />);
  expect(fetchMock).not.toHaveBeenCalled();
  await focusEmpty();
  expect(fetchMock.mock.calls[0][0]).toBe('/search/recently-viewed');
  expect(
    screen.getByRole('heading', { name: 'storefront.recently_viewed' }),
  ).toBeInTheDocument();
  expect(screen.getAllByRole('option').map((e) => e.textContent)).toEqual([
    'Latest product',
    'Earlier product',
  ]);
  expect(
    screen.getByRole('option', { name: 'Latest product' }),
  ).toHaveAttribute('href', '/p/1');
  expect(
    container.querySelector('.sf-search-recent .sf-carousel'),
  ).toBeInTheDocument();
  expect(
    container.querySelector('.sf-search-recent .sf-hide-arrows-mobile'),
  ).toBeInTheDocument();
  expect(screen.queryByText(/search_view_all/)).not.toBeInTheDocument();
  fireEvent.keyDown(field(), { key: 'ArrowDown' });
  expect(
    screen.getByRole('option', { name: 'Latest product' }),
  ).toHaveAttribute('aria-selected', 'true');
  expect(field()).toHaveAttribute(
    'aria-activedescendant',
    screen.getByRole('option', { name: 'Latest product' }).id,
  );
  await type('match');
  expect(
    screen.getByRole('heading', { name: 'storefront.recently_viewed' }),
  ).toBeInTheDocument();
  expect(container.querySelector('.sf-suggest')?.firstElementChild).toHaveClass(
    'sf-search-recent',
  );
  expect(
    screen.getByRole('option', { name: /Matching product/ }),
  ).toBeInTheDocument();
  expect(fetchMock.mock.calls[1][0]).toBe('/search/suggest?q=match&limit=6');
});

it('hides empty or unavailable history, refreshes on reopen and works when typed suggestions are disabled', async () => {
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(reply([]))
    .mockResolvedValueOnce(reply(['Viewed product']))
    .mockRejectedValueOnce(new Error('offline'));
  vi.stubGlobal('fetch', fetchMock);
  render(<SearchBox limit={0} icon={null} />);
  await focusEmpty();
  expect(field()).toHaveAttribute('aria-expanded', 'false');
  expect(
    screen.queryByRole('heading', { name: 'storefront.recently_viewed' }),
  ).not.toBeInTheDocument();
  fireEvent.blur(field());
  await focusEmpty();
  expect(
    screen.getByRole('option', { name: 'Viewed product' }),
  ).toBeInTheDocument();
  fireEvent.keyDown(field(), { key: 'Escape' });
  expect(field()).toHaveAttribute('aria-expanded', 'false');
  await focusEmpty();
  expect(field()).toHaveAttribute('aria-expanded', 'false');
  expect(fetchMock).toHaveBeenCalledTimes(3);
});

it('does not refetch on rerenders and adds a late history reply above the current matches', async () => {
  let finish: ((value: ReturnType<typeof reply>) => void) | undefined;
  const fetchMock = vi
    .fn()
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    )
    .mockResolvedValue(reply(['Current match']));
  vi.stubGlobal('fetch', fetchMock);
  const { rerender } = render(<SearchBox limit={6} icon={null} />);
  await focusEmpty();
  const historySignal = fetchMock.mock.calls[0][1].signal as AbortSignal;
  rerender(<SearchBox limit={6} icon={<span>Search</span>} />);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(SUGGEST_DELAY + 10);
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  await type('current');
  expect(historySignal.aborted).toBe(false);
  await act(async () => {
    finish?.(reply(['Old history']));
  });
  expect(
    screen.getByRole('option', { name: 'Old history' }),
  ).toBeInTheDocument();
  expect(
    screen.getByRole('option', { name: /Current match/ }),
  ).toBeInTheDocument();
  fireEvent.blur(field());
  expect(historySignal.aborted).toBe(true);
});
