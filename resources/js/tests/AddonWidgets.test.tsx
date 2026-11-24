import { act, render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AddonFrame from '../storefront/components/AddonFrame';
import AddonPageLinks from '../storefront/components/AddonPageLinks';
import AddonWidgets, { SANDBOX } from '../storefront/components/AddonWidgets';
import { frameMessage } from '../storefront/components/frameProtocol';

const widget = {
  addon: 'acme/widget',
  src: 'https://widgets.example.test/w?store=public-id',
  title: 'Shop assistant',
};
const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

describe('AddonWidgets', () => {
  beforeEach(() => {
    document.head.innerHTML = '';
    page.props = {};
  });

  it('renders nothing without approved widgets', () => {
    const { container } = render(<AddonWidgets />);

    expect(container.innerHTML).toBe('');
  });

  it('shows a widget only in a sandboxed, titled frame', () => {
    page.props = { storefrontWidgets: [widget] };
    const { container } = render(<AddonWidgets />);

    const frame = container.querySelector('iframe');
    expect(frame?.getAttribute('src')).toBe(widget.src);
    expect(frame?.getAttribute('title')).toBe(widget.title);
    expect(frame?.getAttribute('referrerpolicy')).toBe('strict-origin');
    expect(frame?.getAttribute('sandbox')?.split(' ').sort()).toEqual([
      'allow-forms',
      'allow-same-origin',
      'allow-scripts',
    ]);
  });

  it('never loads a widget into the storefront document', () => {
    page.props = { storefrontWidgets: [widget] };
    render(<AddonWidgets />);

    expect(document.querySelector('script')).toBeNull();
  });

  it('removes the frame on a page without the widget', () => {
    page.props = { storefrontWidgets: [widget] };
    const { container, rerender } = render(<AddonWidgets />);
    page.props = { storefrontWidgets: [] };
    rerender(<AddonWidgets />);

    expect(container.querySelector('iframe')).toBeNull();
  });

  function post(data: unknown, origin: string, source: unknown) {
    act(() => {
      window.dispatchEvent(
        new MessageEvent('message', {
          data,
          origin,
          source: source as MessageEventSource,
        }),
      );
    });
  }

  it('resizes only for its own frame at its own origin', () => {
    page.props = { storefrontWidgets: [widget] };
    const { container } = render(<AddonWidgets />);
    const frame = container.querySelector('iframe') as HTMLIFrameElement;
    const open = { protocol: 'cms-widget/1', type: 'resize', state: 'open' };
    const origin = 'https://widgets.example.test';

    post(open, 'https://evil.example.test', frame.contentWindow);
    expect(frame.dataset.state).toBe('closed');
    post(open, origin, window);
    expect(frame.dataset.state).toBe('closed');
    post({ ...open, state: 'huge' }, origin, frame.contentWindow);
    expect(frame.dataset.state).toBe('closed');
    post(open, origin, frame.contentWindow);
    expect(frame.dataset.state).toBe('open');
  });
});

describe('frameMessage', () => {
  const navigate = (url: unknown) => ({
    protocol: 'cms-widget/1',
    type: 'navigate',
    url,
  });

  it('accepts a page of this store', () => {
    expect(frameMessage(navigate(`${window.location.origin}/shirt`))).toEqual({
      type: 'navigate',
      url: `${window.location.origin}/shirt`,
    });
  });

  it.each([
    ['https://evil.example.test/'],
    ['javascript:alert(1)'],
    ['//evil.example.test/'],
    [`${window.location.origin}/admin`],
    [`${window.location.origin}/admin/users`],
    [`${window.location.origin}/${'a'.repeat(2000)}`],
    [42],
  ])('refuses %s', (url) => {
    expect(frameMessage(navigate(url))).toBeNull();
  });

  it.each([
    [null],
    ['text'],
    [{ type: 'resize', state: 'open' }],
    [{ protocol: 'other', type: 'resize', state: 'open' }],
    [{ protocol: 'cms-widget/1', type: 'script', code: 'x' }],
  ])('refuses %j', (data) => {
    expect(frameMessage(data)).toBeNull();
  });
});

describe('AddonFrame', () => {
  it('is sandboxed like a widget', () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        disconnect() {}
      },
    );
    const { container } = render(<AddonFrame {...widget} />);

    const frame = container.querySelector('iframe');
    expect(frame?.getAttribute('src')).toBe(widget.src);
    expect(frame?.getAttribute('title')).toBe(widget.title);
    expect(frame?.getAttribute('referrerpolicy')).toBe('strict-origin');
    expect(frame?.getAttribute('sandbox')).toBe(SANDBOX);
    vi.unstubAllGlobals();
  });
});

describe('AddonPageLinks', () => {
  it('shows nothing without a page', () => {
    page.props = {};
    const { container } = render(<AddonPageLinks />);

    expect(container.innerHTML).toBe('');
  });

  it('links to the page by its title', () => {
    page.props = {
      storefrontPageLinks: [
        { addon: 'acme/page', url: '/shop-assistant', title: 'Shop Assistant' },
      ],
    };
    const { getByRole } = render(<AddonPageLinks />);

    expect(
      getByRole('link', { name: 'Shop Assistant' }).getAttribute('href'),
    ).toBe('/shop-assistant');
  });
});
