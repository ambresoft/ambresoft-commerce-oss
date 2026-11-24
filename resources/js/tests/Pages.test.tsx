import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import PageForm from '../pages/Admin/Pages/Form';
import PagesIndex from '../pages/Admin/Pages/Index';
import { sanitizePageContent } from '../lib/sanitizePageContent';
import type { PageEditor, PageNode } from '../types/pages';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
const form = vi.hoisted(() => ({ submit: vi.fn() }));
vi.mock('@inertiajs/react', async (original) => {
  const actual = await original<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ props: shared.props, url: '/admin/pages' }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
    useForm: (initial: Record<string, unknown>) => {
      const result = actual.useForm(initial as never);
      type Data = typeof result.data;
      let transform: ((data: Data) => object) | null = null;
      const send = (method: string) => (url: string) =>
        form.submit(
          method,
          url,
          transform ? transform(result.data) : result.data,
        );
      return {
        ...result,
        transform: (fn: (data: Data) => object) => {
          transform = fn;
        },
        post: send('post'),
        put: send('put'),
      };
    },
  };
});

// pages.* and editor.* keys render as the key without prefix.
const strings = new Proxy(
  {
    ...english,
    'common.actions': 'Actions',
    'common.selected': ':count selected',
  } as Record<string, string>,
  {
    get: (target, key: string) =>
      key in target
        ? target[key]
        : /^(pages|editor)\./.test(key)
          ? key.replace(/^(pages|editor)\./, '')
          : undefined,
    has: (target, key: string) =>
      key in target || /^(pages|editor)\./.test(String(key)),
  },
);

const tree: PageNode[] = [
  {
    id: '1',
    parent_id: null,
    name: 'Company',
    slug: 'company',
    status: 'active',
    position: 0,
    depth: 0,
    state: 'live',
    version: 1,
    children: 1,
  },
  {
    id: '2',
    parent_id: '1',
    name: 'Our team',
    slug: 'our-team',
    status: 'hidden',
    position: 0,
    depth: 1,
    state: 'hidden',
    version: 3,
    children: 0,
  },
  {
    id: '3',
    parent_id: null,
    name: 'Privacy policy',
    slug: 'privacy-policy',
    status: 'active',
    position: 5,
    depth: 0,
    state: 'live',
    version: 2,
    children: 0,
  },
];
const editorProps = {
  navigation: [],
  breadcrumbs: [],
  tree,
  customerGroups: [{ id: '5', name: 'Wholesale' }],
  facebookTypes: {
    activities: ['activity', 'sport'],
    websites: ['website', 'article'],
  },
  defaultLocale: 'en',
};

beforeEach(() => {
  vi.restoreAllMocks();
  form.submit.mockReset();
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
    capabilities: { 'pages.view': true, 'pages.manage': true },
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

it('mirrors the server page-content policy in the browser', () => {
  expect(
    sanitizePageContent(
      '<h1 style="text-align:center;position:fixed">T</h1><p onclick="x">a<script>b</script></p><a href="javascript:1">j</a><a href="/returns" target="_blank">r</a><iframe src="https://evil.example"></iframe><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>',
    ),
  ).toBe(
    '<h1 style="text-align: center">T</h1><p>a</p><a>j</a><a href="/returns" target="_blank" rel="noopener noreferrer">r</a><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>',
  );
});

it('lists pages as an indented tree with states and bulk actions', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<PagesIndex navigation={[]} breadcrumbs={[]} pages={tree} />);
  const team = screen.getByText('Our team').closest('tr')!;
  expect(within(team).getByText('state_hidden')).toBeVisible();
  expect(within(team).getByText('/our-team')).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'create_child: Company' }),
  ).toHaveAttribute('href', '/admin/pages/create?parent=1');
  fireEvent.change(screen.getByLabelText('search'), {
    target: { value: 'privacy' },
  });
  expect(screen.queryByText('Company')).toBeNull();
  fireEvent.click(screen.getByLabelText('select_all'));
  fireEvent.click(screen.getByRole('button', { name: 'bulk_hidden' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/pages/bulk');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'post',
    data: { ids: ['3'], action: 'hidden' },
  });
});

it('creates a page with per-language texts, status and the page editor toolbar', () => {
  render(<PageForm {...editorProps} page={null} initialParent="1" />);
  expect(
    screen.getByRole('button', { name: 'align_center' }),
  ).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'table' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'video' })).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/^name · en/), {
    target: { value: 'Returns' },
  });
  fireEvent.click(screen.getByRole('combobox', { name: 'status' }));
  fireEvent.click(screen.getByRole('option', { name: 'status_hidden' }));
  fireEvent.click(screen.getByRole('button', { name: 'Greek' }));
  fireEvent.change(screen.getByLabelText(/^name · el/), {
    target: { value: 'Επιστροφές' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'tab_additional' }));
  fireEvent.change(screen.getByLabelText(/^slug · el/), {
    target: { value: 'Epistrofes' },
  });
  fireEvent.change(screen.getByLabelText('facebook_type'), {
    target: { value: 'article' },
  });
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  const [method, url, data] = form.submit.mock.calls[0];
  expect([method, url]).toEqual(['post', '/admin/pages']);
  expect(data.parent_id).toBe('1');
  expect(data.status).toBe('hidden');
  expect(data.facebook_type).toBe('article');
  expect(data.translations.en.name).toBe('Returns');
  expect(data.translations.el).toMatchObject({
    name: 'Επιστροφές',
    slug: 'epistrofes',
  });
  expect(data.available_from).toBe('');
});

const stored: PageEditor = {
  id: '1',
  version: 4,
  parent_id: null,
  position: 0,
  status: 'active',
  created_on: '2026-10-01',
  facebook_type: 'website',
  available_from: null,
  available_until: null,
  state: 'live',
  translations: {
    en: {
      name: 'Company',
      content: '<p>About us</p>',
      slug: 'company',
      seo_title: '',
      meta_description: '',
      meta_keywords: '',
    },
  },
  customer_groups: [],
  form: null,
};

it('updates a stored page, excludes its own branch from parents and respects read-only staff', () => {
  const { unmount } = render(
    <PageForm {...editorProps} page={stored} initialParent={null} />,
  );
  const parentOptions = screen.getByRole('combobox', { name: /parent/ });
  fireEvent.click(parentOptions);
  expect(screen.queryByRole('option', { name: /Our team/ })).toBeNull();
  expect(
    screen.getByRole('option', { name: /Privacy policy/ }),
  ).toBeInTheDocument();
  fireEvent.keyDown(parentOptions, { key: 'Escape' });
  expect(screen.getByLabelText('customer_groups_all')).toBeChecked();
  // The compact page switcher replaces the old side list.
  expect(screen.queryByRole('navigation', { name: 'pages_list' })).toBeNull();
  expect(
    screen.getByRole('combobox', { name: 'switch_page' }),
  ).toHaveTextContent('Company');
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  const [method, url, data] = form.submit.mock.calls[0];
  expect([method, url, data.version]).toEqual(['put', '/admin/pages/1', 4]);
  expect(data.translations.en.content).toBe('<p>About us</p>');
  unmount();

  shared.props.capabilities = { 'pages.view': true };
  render(<PageForm {...editorProps} page={stored} initialParent={null} />);
  expect(screen.queryByRole('button', { name: 'save' })).toBeNull();
  expect(screen.getByLabelText(/^name · en/)).toBeDisabled();
  expect(screen.getByText('read_only')).toBeVisible();
});

it('builds a contact form on the page with fields, options, subject and recipients', () => {
  render(<PageForm {...editorProps} page={stored} initialParent={null} />);
  fireEvent.click(screen.getByRole('tab', { name: 'tab_form' }));
  fireEvent.click(screen.getByLabelText('form_enabled'));
  fireEvent.click(screen.getByLabelText('placement_before'));
  fireEvent.change(screen.getByLabelText(/^form_recipients/), {
    target: { value: 'info@example.test' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'field_add' }));
  fireEvent.change(screen.getByLabelText('field_name · en: 1'), {
    target: { value: 'Name' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'field_add' }));
  fireEvent.change(screen.getByLabelText('field_name · en: 2'), {
    target: { value: 'Topic' },
  });
  fireEvent.change(screen.getByLabelText('field_type: Topic'), {
    target: { value: 'select' },
  });
  // Choosing a choice type opens its options with one empty row.
  fireEvent.change(screen.getByLabelText('variant_label · en: 1'), {
    target: { value: 'Order' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'variant_clone' }));
  expect(screen.getByLabelText('variant_label · en: 2')).toHaveValue('Order');
  const subject = screen.getByLabelText('form_subject');
  const nameOption = within(subject).getByRole('option', { name: 'Name' });
  fireEvent.change(subject, {
    target: { value: (nameOption as HTMLOptionElement).value },
  });
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  const [, , data] = form.submit.mock.calls[0];
  expect(data.form).toMatchObject({
    enabled: true,
    placement: 'before',
    recipients: 'info@example.test',
    subject_mode: 'field',
  });
  expect(data.form.fields).toHaveLength(2);
  expect(data.form.subject_field).toBe(data.form.fields[0].key);
  expect(data.form.fields[1]).toMatchObject({
    type: 'select',
    names: { en: 'Topic' },
  });
  expect(
    data.form.fields[1].variants.map(
      (v: { labels: Record<string, string> }) => v.labels.en,
    ),
  ).toEqual(['Order', 'Order']);
});

it('sends no form for a page that never had one', () => {
  render(<PageForm {...editorProps} page={stored} initialParent={null} />);
  fireEvent.click(screen.getAllByRole('button', { name: 'save' })[0]);
  expect(form.submit.mock.calls[0][2].form).toBeUndefined();
});
