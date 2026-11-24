import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import FaqForm from '../pages/Admin/Faqs/Form';
import BlogForm from '../pages/Admin/Blogs/Form';
import NewsletterForm from '../pages/Admin/Newsletters/Form';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
vi.mock('../components/admin/RichText', () => ({
  default: ({
    id,
    label,
    value,
    onChange,
  }: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
  }) => (
    <label htmlFor={id}>
      {label}
      <textarea
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  ),
}));

const strings: Record<string, string> = {
  ...english,
  'faqs.tab_general': 'General settings',
  'faqs.tab_content': 'Question & answer',
  'faqs.tab_ai': 'AI assistant',
  'faqs.question': 'Question',
  'faqs.answer': 'Answer',
  'faqs.ai_chat_placeholder': 'Describe the FAQ',
  'blogs.ai_tab': 'AI writer',
  'newsletters.ai_tab': 'Email writer',
  'newsletters.ai_placeholder': 'Describe the email',
  'blogs.ai_placeholder': 'Describe the post',
  'pages.tab_general': 'General',
  'pages.tab_additional': 'Additional',
  'pages.save': 'Save post',
  'common.save': 'Save changes',
  'ai.chat_send': 'Send',
  'ai.chat_examples': 'Example prompts',
  'faqs.ai_example_1': 'How long does delivery take?',
};

function reply(draft: object) {
  return vi.fn().mockResolvedValue({
    ok: true,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => ({ reply: 'Done, have a look.', draft, log_id: '1' }),
  });
}

async function ask(tab: string, label: string, text: string) {
  fireEvent.click(screen.getByRole('tab', { name: tab }));
  fireEvent.change(screen.getByLabelText(label), { target: { value: text } });
  fireEvent.click(screen.getByRole('button', { name: 'Send' }));
  expect(await screen.findByText('Done, have a look.')).toBeTruthy();
}

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    faqAiReady: true,
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Staff',
        email: 's@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    preferences: { timezone: 'UTC' },
    capabilities: {
      'faqs.manage': true,
      'faqs.generate': true,
      'blog.manage': true,
      'blog.generate': true,
      'newsletters.manage': true,
      'newsletters.generate': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings,
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as unknown as SharedProps;
});
afterEach(() => vi.unstubAllGlobals());

it('drafts a FAQ in every language from the chat and sends the answers as plain text', async () => {
  const fetch = reply({
    translations: {
      en: { question: 'How long is delivery?', answer: '<p>3–5 days.</p>' },
      el: {
        question: 'Πόσο διαρκεί η αποστολή;',
        answer: '<p>3–5 ημέρες.</p>',
      },
    },
  });
  vi.stubGlobal('fetch', fetch);
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <FaqForm navigation={[]} breadcrumbs={[]} faq={null} defaultLocale="en" />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Question & answer' }));
  fireEvent.change(screen.getByLabelText('Answer'), {
    target: { value: '<p>Old <b>answer</b></p>' },
  });

  await ask('AI assistant', 'Describe the FAQ', 'Delivery times');

  expect(fetch.mock.calls[0][0]).toBe('/admin/faqs/assistant');
  expect(
    JSON.parse(fetch.mock.calls[0][1].body).current.translations.en,
  ).toEqual({
    question: '',
    answer: 'Old answer',
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(visit.mock.calls[0][1]).toMatchObject({
    data: {
      translations: {
        en: { question: 'How long is delivery?', answer: '<p>3–5 days.</p>' },
        el: { question: 'Πόσο διαρκεί η αποστολή;' },
      },
    },
  });
});

it('writes a blog post into the selected language only, keeping its slug', async () => {
  const fetch = reply({
    name: 'Running shoes guide',
    excerpt: 'For beginners.',
    content: '<h2>Fit</h2><p>Try them on.</p>',
    seo_title: 'Running shoes',
    meta_description: 'How to choose.',
    meta_keywords: 'running, shoes',
    tags: 'Running, Guides',
  });
  vi.stubGlobal('fetch', fetch);
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  vi.spyOn(router, 'replace').mockImplementation(() => undefined);
  render(
    <BlogForm
      navigation={[]}
      breadcrumbs={[]}
      kind="post"
      item={null}
      initialBlog="1"
      blogs={[{ id: '1', name: 'News' }]}
      facebookTypes={{}}
      defaultLocale="en"
      aiReady
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));

  await ask('AI writer', 'Describe the post', 'A running shoe guide');

  expect(fetch.mock.calls[0][0]).toBe('/admin/blog-posts/assistant');
  expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
    locale: 'el',
    post_id: null,
    messages: [{ role: 'user', content: 'A running shoe guide' }],
  });
  fireEvent.click(screen.getAllByRole('button', { name: 'Save post' })[0]);
  const data = visit.mock.calls[0][1]?.data as {
    translations: Record<string, Record<string, string>>;
  };
  expect(data.translations.el).toMatchObject({
    name: 'Running shoes guide',
    content: '<h2>Fit</h2><p>Try them on.</p>',
    tags: 'Running, Guides',
    slug: '',
  });
  expect(data.translations.en.name).toBe('');
});

it('writes a newsletter into the selected language and keeps the live preview working', async () => {
  const draft = {
    name: 'Winter sale',
    subject: '20% off coats',
    random_subjects: 'Coats on sale',
    body_html: '<p>Hello {{company_name}}</p><p>{{unsubscribe_link}}</p>',
  };
  const fetch = vi.fn((url: string) =>
    Promise.resolve({
      ok: true,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () =>
        url === '/admin/newsletters/assistant'
          ? { reply: 'Done, have a look.', draft, log_id: '1' }
          : { subject: '', html: '' },
    }),
  );
  vi.stubGlobal('fetch', fetch);
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <NewsletterForm
      navigation={[]}
      breadcrumbs={[]}
      type="newsletter"
      base="/admin/newsletters"
      item={null}
      campaigns={[]}
      lists={[]}
      templates={[]}
      defaultLocale="en"
      canSend={false}
      aiReady
    />,
  );

  await ask('Email writer', 'Describe the email', 'A winter coat sale');

  const call = fetch.mock.calls.find(
    ([url]) => url === '/admin/newsletters/assistant',
  ) as unknown as [string, { body: string }];
  expect(JSON.parse(call[1].body)).toMatchObject({
    locale: 'en',
    type: 'newsletter',
    current: { name: '', subject: '', body_html: '' },
  });
  fireEvent.click(screen.getAllByRole('button', { name: 'Save changes' })[0]);
  expect(visit.mock.calls[0][1]).toMatchObject({
    data: { translations: { en: draft } },
  });
});

it('shows example prompts in a help tooltip', () => {
  render(
    <FaqForm navigation={[]} breadcrumbs={[]} faq={null} defaultLocale="en" />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'AI assistant' }));
  fireEvent.click(screen.getByRole('button', { name: 'Example prompts (?)' }));
  expect(screen.getByRole('tooltip').textContent).toContain(
    '“How long does delivery take?”',
  );
});

it('offers no assistant tab without the capability', () => {
  shared.props.capabilities = { 'faqs.manage': true };
  render(
    <FaqForm navigation={[]} breadcrumbs={[]} faq={null} defaultLocale="en" />,
  );
  expect(screen.queryByRole('tab', { name: 'AI assistant' })).toBeNull();
});
