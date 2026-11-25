import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import BannerForm from '../pages/Admin/Banners/Form';
import BannersIndex from '../pages/Admin/Banners/Index';
import { sanitizeBannerTitle } from '../lib/banners';
import type { Pagination as Page } from '../types/admin';
import type { BannerEditor, BannerRow, Layout } from '../types/banners';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
const form = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', async (original) => {
  const actual = await original<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: shared.props, url: '/admin/banners' }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
    // Real form state; submissions are captured with the transformed payload.
    useForm: (initial: Record<string, unknown>) => {
      const result = actual.useForm(initial as never);
      type Data = typeof result.data;
      let transform: ((data: Data) => object) | null = null;
      return {
        ...result,
        transform: (fn: (data: Data) => object) => {
          transform = fn;
        },
        post: (url: string, options: object) =>
          form.post(
            url,
            options,
            transform ? transform(result.data) : result.data,
          ),
      };
    },
  };
});

// Every banners.* key renders as the key itself, with placeholders appended.
const strings = new Proxy(
  {
    ...english,
    'common.all': 'All',
    'common.actions': 'Actions',
    'common.selected': ':count selected',
  } as Record<string, string>,
  {
    get: (target, key: string) =>
      key in target
        ? target[key]
        : key.startsWith('banners.')
          ? key.slice(8)
          : key.startsWith('ai.')
            ? key.slice(3)
            : undefined,
    has: (target, key: string) =>
      key in target ||
      String(key).startsWith('banners.') ||
      String(key).startsWith('ai.'),
  },
);

const layoutDefaults: Layout = {
  valign: 'center',
  halign: 'center',
  paddings: '10px 20px 20px 20px',
  full_width: false,
  title_size: 22,
  title_shadow: false,
  description_size: 14,
  background_type: 'image',
  background_size: 'cover',
  background_valign: 'center',
  button_enabled: false,
  button_text_color: null,
  button_color: null,
  mask_type: 'none',
  mask_position: 'banner',
  mask_opacity: 50,
  mask_direction: 'auto',
  mask_color: null,
  css_classes: null,
};
const editorProps = {
  navigation: [],
  breadcrumbs: [],
  groups: [{ id: '3', name: 'Homepage', banners: 2 }],
  customerGroups: [{ id: '5', name: 'Wholesale' }],
  candidates: [],
  defaultLocale: 'en',
  layoutDefaults,
  cssPresets: ['banner-button-bottom', 'banner-enlarge-image'],
  videoMaxBytes: 16777216,
};

beforeEach(() => {
  vi.restoreAllMocks();
  form.post.mockReset();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'a@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: { 'banners.view': true, 'banners.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings,
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Greek' },
      ],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as unknown as SharedProps;
});

it('sanitizes preview titles like the server policy', () => {
  expect(
    sanitizeBannerTitle(
      'A <i>b</i><script>x</script><span style="position:fixed" class="banner-title-above">c</span><span style="color: #E11D48">d</span><a href="javascript:1">e</a>',
    ),
  ).toBe(
    'A <i>b</i><span class="banner-title-above">c</span><span style="color: #e11d48">d</span>e',
  );
});

it('creates a banner with names, layout, texts and tablet overrides sent as form data', () => {
  render(<BannerForm {...editorProps} banner={null} />);
  fireEvent.change(screen.getByLabelText(/^name · en/), {
    target: { value: 'Summer' },
  });
  fireEvent.change(screen.getByLabelText('paddings'), {
    target: { value: '5% 10%' },
  });
  fireEvent.change(screen.getByLabelText('title_text'), {
    target: { value: 'Sale' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'preset_thin' }));
  // The tablet tab appears only after enabling own tablet settings.
  expect(screen.queryByRole('tab', { name: 'tab_tablet' })).toBeNull();
  fireEvent.click(screen.getByLabelText('tablet_custom'));
  fireEvent.click(screen.getByRole('tab', { name: 'tab_tablet' }));
  fireEvent.click(screen.getAllByLabelText(/override_label/)[2]);
  fireEvent.change(screen.getByLabelText('paddings'), {
    target: { value: '0px' },
  });
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  expect(form.post).toHaveBeenCalledTimes(1);
  const [url, options, data] = form.post.mock.calls[0];
  expect(url).toBe('/admin/banners');
  expect(options).toMatchObject({ forceFormData: true });
  expect(data.names).toEqual({ en: 'Summer', el: '' });
  expect(data.layouts.pc.paddings).toBe('5% 10%');
  expect(data.layouts.tablet.paddings).toBe('0px');
  expect(data.layouts.tablet.valign).toBeNull();
  expect(data.contents.pc.en.title).toBe('Sale<i>Text</i>');
  expect(data.tablet_custom).toBe(true);
  expect(data.available_from).toBe('');
  // Buttons start without a hover effect and with a 0.3 second change.
  expect(data.button_hover).toBe('none');
  expect(data.button_transition).toBe(0.3);
});

it('applies each AI reply to the editor, sends the conversation and can undo the last change', async () => {
  const reply = {
    reply: 'A dark sale banner. Upload a photo in General.',
    draft: {
      names: { en: 'Black Friday', el: 'Black Friday' },
      settings: { color_scheme: 'dark', title_color: '#ffffff', url: '/sale' },
      layout: { title_size: 48, button_enabled: true },
      contents: {
        en: { title: '30% off', description: 'Shoes', button_text: 'Shop' },
        el: {
          title: '30% έκπτωση',
          description: 'Παπούτσια',
          button_text: 'Αγορά',
        },
      },
    },
    log_id: '1',
  };
  const fetch = vi.fn().mockResolvedValue({
    ok: true,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => reply,
  });
  vi.stubGlobal('fetch', fetch);
  const { unmount } = render(<BannerForm {...editorProps} banner={null} />);
  // Without the generate capability there is no assistant.
  expect(screen.queryByRole('tab', { name: 'ai_tab' })).toBeNull();
  unmount();
  shared.props.capabilities = {
    ...shared.props.capabilities,
    'banners.generate': true,
  };
  render(<BannerForm {...editorProps} banner={null} aiReady />);
  fireEvent.click(screen.getByRole('tab', { name: 'ai_tab' }));
  const box = screen.getByLabelText('ai_placeholder');
  fireEvent.change(box, { target: { value: 'A dark Black Friday banner' } });
  fireEvent.keyDown(box, { key: 'Enter' });
  expect(await screen.findByText(reply.reply)).toBeTruthy();
  expect(fetch.mock.calls[0][0]).toBe('/admin/banners/generate');
  const body = JSON.parse(fetch.mock.calls[0][1].body);
  expect(body.messages).toEqual([
    { role: 'user', content: 'A dark Black Friday banner' },
  ]);
  expect(body.current.layout.title_size).toBe(22);
  expect(body.current.contents.el).toEqual({
    title: '',
    description: '',
    button_text: '',
  });

  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  let data = form.post.mock.calls[0][2];
  expect(data.names).toEqual({ en: 'Black Friday', el: 'Black Friday' });
  expect(data.color_scheme).toBe('dark');
  expect(data.url).toBe('/sale');
  expect(data.layouts.pc.title_size).toBe(48);
  expect(data.layouts.pc.paddings).toBe('10px 20px 20px 20px');
  expect(data.contents.pc.el.title).toBe('30% έκπτωση');
  expect(data.contents.pc.en.main_image).toBeNull();

  fireEvent.click(screen.getByRole('button', { name: 'chat_undo' }));
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  data = form.post.mock.calls[1][2];
  expect(data.names.en).toBe('');
  expect(data.layouts.pc.title_size).toBe(22);
  expect(data.contents.pc.en.title).toBe('');
  await waitFor(() =>
    expect(screen.queryByRole('button', { name: 'chat_undo' })).toBeNull(),
  );
  vi.unstubAllGlobals();
});

it('keeps the request and shows the error when the assistant fails', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ errors: { ai: ['Not valid.'] } }),
    }),
  );
  shared.props.capabilities = {
    ...shared.props.capabilities,
    'banners.generate': true,
  };
  render(<BannerForm {...editorProps} banner={null} aiReady />);
  fireEvent.click(screen.getByRole('tab', { name: 'ai_tab' }));
  const box = screen.getByLabelText('ai_placeholder') as HTMLTextAreaElement;
  fireEvent.change(box, { target: { value: 'A summer banner' } });
  fireEvent.click(screen.getByRole('button', { name: 'chat_send' }));
  expect((await screen.findByRole('alert')).textContent).toBe('Not valid.');
  expect(box.value).toBe('A summer banner');
  vi.unstubAllGlobals();
});

it('offers the hover effect on desktop and asks for the transition time only once there is an effect', () => {
  render(<BannerForm {...editorProps} banner={null} />);
  expect(screen.getByText('button_hover')).toBeInTheDocument();
  expect(screen.queryByText('button_transition')).toBeNull();
});

it('previews the unsaved title and switches devices', () => {
  const { container } = render(<BannerForm {...editorProps} banner={null} />);
  fireEvent.change(screen.getByLabelText('title_text'), {
    target: { value: 'Hello <mark>world</mark><script>bad()</script>' },
  });
  const preview = container.querySelector('.banner-preview')!;
  expect(preview.querySelector('.banner-title')!.innerHTML).toBe(
    'Hello <mark>world</mark>',
  );
  expect(preview).toHaveStyle({ width: '1200px' });
  fireEvent.click(screen.getByRole('button', { name: 'tab_phone' }));
  expect(container.querySelector('.banner-preview')).toHaveStyle({
    width: '375px',
  });
});

it('previews the banner as tall as the store block will show it, on desktop and tablet only', () => {
  const { container } = render(<BannerForm {...editorProps} banner={null} />);
  const preview = () => container.querySelector('.banner-preview')!;
  const height = screen.getByLabelText('preview_height');
  expect(height).toHaveValue('100');
  expect(preview()).toHaveStyle({ height: '450px' });
  fireEvent.change(height, { target: { value: '200' } });
  expect(preview()).toHaveStyle({ width: '1200px', height: '900px' });
  fireEvent.click(screen.getByRole('button', { name: 'tab_tablet' }));
  expect(preview()).toHaveStyle({ width: '768px', height: '840px' });
  // Phones are never made taller.
  fireEvent.click(screen.getByRole('button', { name: 'tab_phone' }));
  expect(preview()).toHaveStyle({ width: '375px', height: '520px' });
  expect(height).toBeDisabled();
});

const stored: BannerEditor = {
  id: '9',
  version: 4,
  group_id: '3',
  position: 2,
  status: 'active',
  created_on: '2026-10-01',
  available_from: null,
  available_until: null,
  state: 'live',
  names: { en: 'Stored banner' },
  color_scheme: 'dark',
  object_type: 'image',
  youtube_autoplay: false,
  youtube_hide_controls: false,
  products_template: 'grid',
  products_columns: 4,
  products_rows: 1,
  object_valign: 'center',
  object_halign: 'center',
  background_color: null,
  background_halign: 'center',
  title_color: null,
  title_weight: 'normal',
  title_tag: 'div',
  description_color: null,
  description_background: null,
  button_style: 'standard',
  button_hover: 'none',
  button_transition: 0.3,
  url: null,
  new_window: false,
  tablet_custom: false,
  phone_custom: false,
  layouts: { pc: layoutDefaults },
  contents: {
    pc: {
      en: {
        title: 'Stored',
        description: null,
        button_text: null,
        youtube_video: null,
        main_image: null,
        background_image: null,
        background_video: null,
      },
    },
  },
  products: [],
  customer_groups: ['5'],
};

it('updates a stored banner with its version and keeps read-only staff out of edits', () => {
  const { unmount } = render(<BannerForm {...editorProps} banner={stored} />);
  expect(screen.getByLabelText('title_text')).toHaveValue('Stored');
  expect(screen.getByLabelText('Wholesale')).toBeChecked();
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  const [url, , data] = form.post.mock.calls[0];
  expect(url).toBe('/admin/banners/9');
  expect(data.version).toBe(4);
  expect(data.customer_groups).toEqual(['5']);
  unmount();

  shared.props.capabilities = { 'banners.view': true };
  render(<BannerForm {...editorProps} banner={stored} />);
  expect(screen.queryByRole('button', { name: 'save' })).toBeNull();
  expect(screen.getByLabelText('title_text')).toBeDisabled();
  expect(screen.getByText('read_only')).toBeVisible();
});

const rows: Page<BannerRow> = {
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
  total: 3,
  data: [
    {
      id: '1',
      name: 'Hero',
      position: 0,
      status: 'active',
      state: 'live',
      available_from: null,
      available_until: null,
      object_type: 'image',
      version: 1,
      group: { id: '3', name: 'Homepage' },
      thumbnail: null,
    },
    {
      id: '2',
      name: 'Second',
      position: 1,
      status: 'active',
      state: 'scheduled',
      available_from: '2026-11-01T10:00:00+00:00',
      available_until: null,
      object_type: 'products',
      version: 1,
      group: { id: '3', name: 'Homepage' },
      thumbnail: null,
    },
    {
      id: '3',
      name: 'Loose',
      position: 0,
      status: 'disabled',
      state: 'disabled',
      available_from: null,
      available_until: null,
      object_type: 'youtube',
      version: 2,
      group: null,
      thumbnail: null,
    },
  ],
};

it('lists banners under group headers with states and runs bulk and row actions', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  render(
    <BannersIndex
      navigation={[]}
      breadcrumbs={[]}
      banners={rows}
      groups={[{ id: '3', name: 'Homepage', banners: 2 }]}
      filters={{}}
    />,
  );
  const headers = screen
    .getAllByRole('columnheader')
    .filter((h) => h.getAttribute('scope') === 'colgroup');
  expect(headers.map((h) => h.textContent)).toEqual(['Homepage', 'group_none']);
  expect(
    within(screen.getByText('Second').closest('tr')!).getByText(
      'state_scheduled',
    ),
  ).toBeVisible();
  fireEvent.click(screen.getAllByLabelText('select_row')[0]);
  fireEvent.click(screen.getByRole('button', { name: 'bulk_activate' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/banners/bulk');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'post',
    data: { ids: ['1'], action: 'activate' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'clone: Loose' }));
  expect(visit.mock.calls[1][0]).toBe('/admin/banners/3/clone');
  fireEvent.click(screen.getByRole('button', { name: 'delete: Loose' }));
  expect(visit.mock.calls[2][1]).toMatchObject({
    method: 'delete',
    data: { version: 2 },
  });
});

it('sends the availability window as instants in the store timezone and shows stored instants in that timezone', () => {
  render(
    <BannerForm
      {...editorProps}
      banner={{
        ...stored,
        available_from: '2026-11-26T22:00:00Z',
        available_until: null,
      }}
    />,
  );
  const from = screen.getByLabelText('available_from');
  expect(from).toHaveValue('2026-11-26T22:00');
  fireEvent.change(screen.getByLabelText('available_until'), {
    target: { value: '2026-11-28T00:00' },
  });
  expect(screen.getByText(/^timezone_note/)).toBeVisible();
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  const data = form.post.mock.calls[0][2];
  expect(data.available_from).toBe('2026-11-26T22:00:00.000Z');
  expect(data.available_until).toBe('2026-11-28T00:00:00.000Z');
});
