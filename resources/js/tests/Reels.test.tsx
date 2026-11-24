import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Reels from '../storefront/themes/Default/Reels';
import type { PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () =>
    (key: string, values: Record<string, string> = {}) =>
      key === 'reels.play' ? `Play ${values.name}` : key,
}));

const block: PublicBlock = {
  id: 'block',
  template: 'reel_carousel',
  title: 'Store videos',
  css_class: '',
  mobile: true,
  tablet: true,
  desktop: true,
  content: {
    display: { per_row: 6 },
    items: [
      {
        id: '1',
        name: 'First reel',
        image: { url: '/cover.png', width: 360, height: 640 },
      },
      {
        id: '2',
        name: 'Second reel',
        image: { url: '/cover2.png', width: 360, height: 640 },
      },
    ],
  },
};
const detail = (id = '1') => ({
  id,
  name: id === '1' ? 'First reel' : 'Second reel',
  image: { url: '/cover.png', width: 360, height: 640 },
  video: `/video/${id}`,
  view_token: 'signed-token',
  views: 10,
  products: [
    {
      id: '5',
      name: 'Linked phone',
      image: { url: '/phone.png', width: 100, height: 100 },
      url: '/phone',
      price: '25.50',
      rating: { average: '4.5', count: 8 },
    },
  ],
  currency_format: {
    code: 'EUR',
    symbol: '€',
    position: 'after',
    decimal: ',',
    thousands: '.',
    decimals: 2,
  },
});
const request = vi.fn();
beforeEach(() => {
  vi.stubGlobal('fetch', request);
  request.mockReset();
  request.mockImplementation((url: string, init?: RequestInit) =>
    Promise.resolve({
      ok: true,
      json: async () =>
        init?.method === 'POST'
          ? { status: 'ok' }
          : detail(url.endsWith('/2') ? '2' : '1'),
    }),
  );
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      disconnect() {}
    },
  );
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
});
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

it('loads no video until opened, shows linked products and navigates between reels', async () => {
  const { container } = render(<Reels block={block} />);
  expect(request).not.toHaveBeenCalled();
  expect(container.querySelector('video')).toBeNull();
  expect(screen.queryByText(/upload/i)).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Play First reel' }));
  const dialog = screen.getByRole('dialog');
  await within(dialog).findByText('Linked phone');
  expect(
    within(dialog).getByRole('link', { name: /Linked phone/ }),
  ).toHaveAttribute('href', '/phone');
  expect(container.querySelector('video')).toHaveAttribute('src', '/video/1');
  expect(
    within(dialog).getByRole('button', { name: 'reels.previous' }),
  ).toBeDisabled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'reels.next' }));
  await waitFor(() =>
    expect(container.querySelector('video')).toHaveAttribute('src', '/video/2'),
  );
  expect(
    within(dialog).getByRole('button', { name: 'reels.next' }),
  ).toBeDisabled();
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'storefront.close' }),
  );
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(container.querySelector('video')).toBeNull();
});

it('keeps a thumbnail placeholder and product link when the product has no image', async () => {
  request.mockResolvedValueOnce({
    ok: true,
    json: async () => ({
      ...detail(),
      products: detail().products.map((product) => ({
        ...product,
        image: null,
      })),
    }),
  });
  render(<Reels block={block} />);
  fireEvent.click(screen.getByRole('button', { name: 'Play First reel' }));
  await screen.findByText('Linked phone');
  const product = screen.getByRole('link', { name: /Linked phone/ });
  expect(product).toHaveAttribute('href', '/phone');
  expect(
    product.querySelector('.sf-reels-product-media .sf-no-image'),
  ).not.toBeNull();
  expect(product.querySelector('.sf-reels-product-copy')).toHaveTextContent(
    'Linked phone',
  );
});

it('counts playback once after three actual seconds and ignores seeking', async () => {
  const { container } = render(<Reels block={block} />);
  fireEvent.click(screen.getByRole('button', { name: 'Play First reel' }));
  await screen.findByText('Linked phone');
  const video = container.querySelector('video')!;
  Object.defineProperty(video, 'paused', { configurable: true, value: false });
  Object.defineProperty(video, 'seeking', {
    configurable: true,
    writable: true,
    value: false,
  });
  let now = 1000;
  vi.spyOn(performance, 'now').mockImplementation(() => now);
  fireEvent.playing(video);
  now += 500;
  fireEvent.timeUpdate(video);
  fireEvent.seeking(video);
  now += 10000;
  fireEvent.timeUpdate(video);
  expect(
    request.mock.calls.filter(([, init]) => init?.method === 'POST'),
  ).toHaveLength(0);
  for (let i = 0; i < 6; i++) {
    now += 500;
    await act(async () => fireEvent.timeUpdate(video));
  }
  expect(
    request.mock.calls.filter(([, init]) => init?.method === 'POST'),
  ).toHaveLength(1);
  now += 500;
  await act(async () => fireEvent.timeUpdate(video));
  expect(
    request.mock.calls.filter(([, init]) => init?.method === 'POST'),
  ).toHaveLength(1);
  expect(
    JSON.parse(
      request.mock.calls.find(([, init]) => init?.method === 'POST')![1].body,
    ),
  ).toEqual({ token: 'signed-token' });
});

it('shows a recoverable error and single template shows only one item', async () => {
  request.mockRejectedValueOnce(new Error('offline'));
  const { container } = render(
    <Reels block={{ ...block, template: 'reel_single' }} />,
  );
  expect(
    screen.queryByRole('button', { name: 'Play Second reel' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Play First reel' }));
  await screen.findByText('reels.load_error');
  fireEvent.click(screen.getByRole('button', { name: 'reels.retry' }));
  await waitFor(() =>
    expect(container.querySelector('video')).toHaveAttribute('src', '/video/1'),
  );
});
