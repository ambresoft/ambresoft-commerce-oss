import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, afterEach, expect, it, vi } from 'vitest';
import FaqForm from '../pages/Admin/Faqs/Form';
import General from '../pages/Admin/Settings/General';
import FaqPicker from '../components/admin/FaqPicker';
import FaqAiModal from '../components/admin/FaqAiModal';
import type { FaqDraft, FaqOption } from '../types/faqs';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose } from './select';

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
    disabled,
  }: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
  }) => (
    <label htmlFor={id}>
      {label}
      <textarea
        id={id}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const source = {
  owner: 'category' as const,
  object_id: '1',
  source_name: 'Shoes',
  source_text: 'Leather shoes.',
};
const strings = {
  'faqs.title': 'FAQs',
  'faqs.create': 'Create FAQ',
  'faqs.edit': 'Edit FAQ',
  'faqs.type': 'Type',
  'faqs.type_general': 'General',
  'faqs.type_category': 'Category',
  'faqs.type_product': 'Product',
  'faqs.tab_general': 'General settings',
  'faqs.tab_content': 'Question & answer',
  'faqs.question': 'Question',
  'faqs.answer': 'Answer',
  'faqs.position': 'Catalog position',
  'faqs.choose': 'Choose an FAQ',
  'faqs.link_add': 'Link an existing FAQ',
  'faqs.unlink': 'Unlink',
  'faqs.move_up': 'Move up: :question',
  'faqs.move_down': 'Move down: :question',
  'faqs.generate': 'Generate with AI',
  'faqs.ai_preview': 'Review AI suggestions',
  'faqs.ai_language': 'Generation language',
  'faqs.ai_count': 'Questions',
  'faqs.ai_auto': 'All active languages (automatic)',
  'faqs.ai_entry': 'Suggestion :number',
  'faqs.add_selected': 'Add selected to the form',
  'faqs.regenerate': 'Regenerate',
  'faqs.ai_settings': 'FAQ AI settings',
  'faqs.ai_key': 'Anthropic API key',
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    faqAiReady: true,
    faqFieldRules: {
      question: { required: true, maxLength: 500 },
      answer: { required: true, maxLength: 20000 },
    },
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Staff',
        email: 'staff@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'faqs.view': true,
      'faqs.manage': true,
      'faqs.generate': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings: {
        ...english,
        ...strings,
        ...Object.fromEntries(
          Object.entries(strings)
            .filter(([key]) => key.startsWith('faqs.ai_'))
            .map(([key, value]) => [key.replace('faqs.ai_', 'ai.'), value]),
        ),
        'ai.settings': 'AI settings',
        'settings.save': 'Save settings',
      },
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});
afterEach(() => {
  vi.unstubAllGlobals();
});

function draft(question: string): FaqDraft {
  return {
    translations: {
      en: { question, answer: 'English answer.' },
      el: { question: 'Ερώτηση ' + question, answer: 'Ελληνική απάντηση.' },
    },
  };
}
function response(entries: FaqDraft[]) {
  return {
    ok: true,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => ({ entries, log_id: '1' }),
  };
}

it('submits manual localized content with its FAQ type and current version', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<FaqForm {...shell} faq={null} defaultLocale="en" />);
  choose(screen.getByRole('combobox', { name: 'Type' }), 'Category');
  fireEvent.click(screen.getByRole('tab', { name: 'Question & answer' }));
  fireEvent.change(screen.getByLabelText('Question'), {
    target: { value: 'How do I choose shoes?' },
  });
  fireEvent.change(screen.getByLabelText('Answer'), {
    target: { value: '<p>Read the size guide.</p>' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  fireEvent.change(screen.getByLabelText('Question'), {
    target: { value: 'Πώς επιλέγω παπούτσια;' },
  });
  fireEvent.change(screen.getByLabelText('Answer'), {
    target: { value: '<p>Δείτε τον οδηγό μεγεθών.</p>' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/faqs');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'post',
    data: {
      type: 'category',
      translations: {
        en: {
          question: 'How do I choose shoes?',
          answer: '<p>Read the size guide.</p>',
        },
        el: { question: 'Πώς επιλέγω παπούτσια;' },
      },
    },
  });
});

it('links and reorders reusable entries and keeps disabled existing links readable', () => {
  const onIds = vi.fn();
  const options: FaqOption[] = [
    { id: '1', question: 'Contact us?', type: 'general', status: 'active' },
    { id: '2', question: 'Size guide?', type: 'category', status: 'active' },
    {
      id: '3',
      question: 'Old question?',
      type: 'category',
      status: 'disabled',
    },
  ];
  const view = render(
    <FaqPicker
      source={source}
      options={options}
      ids={['1', '2', '3']}
      drafts={[]}
      onIds={onIds}
      onDrafts={vi.fn()}
    />,
  );
  expect(screen.getByText('Inactive')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Move up: Size guide?' }));
  expect(onIds).toHaveBeenCalledWith(['2', '1', '3']);
  fireEvent.click(screen.getAllByRole('button', { name: 'Unlink' })[1]);
  expect(onIds).toHaveBeenLastCalledWith(['1', '3']);
  view.rerender(
    <FaqPicker
      source={source}
      options={options}
      ids={['3']}
      drafts={[]}
      onIds={onIds}
      onDrafts={vi.fn()}
    />,
  );
  choose(
    screen.getByRole('combobox', { name: 'Link an existing FAQ' }),
    'Size guide? · Category',
  );
  expect(onIds).toHaveBeenLastCalledWith(['3', '2']);
});

it('allows preview selection and editing while adding drafts only to the unsaved form', async () => {
  const fetch = vi
    .fn()
    .mockResolvedValue(response([draft('First?'), draft('Second?')]));
  vi.stubGlobal('fetch', fetch);
  const add = vi.fn();
  render(<FaqAiModal source={source} onAdd={add} />);
  fireEvent.click(screen.getByRole('button', { name: 'Generate with AI' }));
  const dialog = screen.getByRole('dialog');
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Generate with AI' }),
  );
  await within(dialog).findByLabelText('Suggestion 1');
  fireEvent.click(within(dialog).getByLabelText('Suggestion 2'));
  fireEvent.change(within(dialog).getAllByLabelText('Question')[0], {
    target: { value: 'Edited first?' },
  });
  fireEvent.change(within(dialog).getAllByLabelText('Answer')[0], {
    target: { value: 'Safe <script>text</script>' },
  });
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Add selected to the form' }),
  );
  expect(add).toHaveBeenCalledWith([
    {
      translations: {
        en: {
          question: 'Edited first?',
          answer: '<p>Safe &lt;script&gt;text&lt;/script&gt;</p>',
        },
        el: { question: 'Ερώτηση First?', answer: '<p>Ελληνική απάντηση.</p>' },
      },
    },
  ]);
  expect(fetch).toHaveBeenCalledTimes(1);
  expect(fetch.mock.calls[0][0]).toBe('/admin/faqs/generate');
});

it('regenerates one entry without replacing the other edited suggestions', async () => {
  const fetch = vi
    .fn()
    .mockResolvedValueOnce(response([draft('First?'), draft('Second?')]))
    .mockResolvedValueOnce(response([draft('Replacement?')]));
  vi.stubGlobal('fetch', fetch);
  const add = vi.fn();
  render(<FaqAiModal source={source} onAdd={add} />);
  fireEvent.click(screen.getByRole('button', { name: 'Generate with AI' }));
  const dialog = screen.getByRole('dialog');
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Generate with AI' }),
  );
  await within(dialog).findByLabelText('Suggestion 1');
  fireEvent.change(within(dialog).getAllByLabelText('Question')[1], {
    target: { value: 'Keep my edit?' },
  });
  fireEvent.click(
    within(dialog).getAllByRole('button', { name: 'Regenerate' })[0],
  );
  await waitFor(() =>
    expect(within(dialog).getAllByLabelText('Question')[0]).toHaveValue(
      'Replacement?',
    ),
  );
  expect(within(dialog).getAllByLabelText('Question')[1]).toHaveValue(
    'Keep my edit?',
  );
  expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({
    count: 1,
    question: 'First?',
  });
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Add selected to the form' }),
  );
  expect(add.mock.calls[0][0]).toHaveLength(2);
});

it('requires the missing active-language content before adding single-language suggestions', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      response([
        {
          translations: { en: { question: 'Question?', answer: 'Answer.' } },
        },
      ]),
    ),
  );
  render(<FaqAiModal source={source} onAdd={vi.fn()} />);
  fireEvent.click(screen.getByRole('button', { name: 'Generate with AI' }));
  const dialog = screen.getByRole('dialog');
  choose(
    within(dialog).getByRole('combobox', { name: 'Generation language' }),
    'English',
  );
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'Generate with AI' }),
  );
  await within(dialog).findByLabelText('Suggestion 1');
  expect(
    within(dialog).getByRole('button', { name: 'Add selected to the form' }),
  ).toBeDisabled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Ελληνικά' }));
  fireEvent.change(within(dialog).getByLabelText('Question'), {
    target: { value: 'Ερώτηση;' },
  });
  fireEvent.change(within(dialog).getByLabelText('Answer'), {
    target: { value: 'Απάντηση.' },
  });
  expect(
    within(dialog).getByRole('button', { name: 'Add selected to the form' }),
  ).toBeEnabled();
});

it('does not display saved credentials and preserves them by submitting an empty key', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{
        out_of_stock_action: 'N',
        version: 4,
        tax_calculation_method: 'unit_price',
        display_catalog_prices_with_tax: false,
        display_cart_prices_with_tax: false,
        tax_estimate_without_address: false,
        tax_default_country: null,
        tax_default_region: null,
      }}
      overrides={0}
      aiSettings={{
        enabled: true,
        has_key: true,
        model: 'claude-sonnet-4-6',
        max_tokens: 3000,
        hourly_limit: 50,
        version: 2,
      }}
      aiModels={[{ value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6' }]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'AI settings' }));
  expect(screen.getByLabelText('Anthropic API key')).toHaveValue('');
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][0]).toBe('/admin/settings/general');
  expect(request.mock.calls[0][1]).toMatchObject({
    method: 'put',
    data: {
      version: 4,
      ai_settings: { version: 2, api_key: '', clear_key: false },
    },
  });
});

it('hides AI generation when the capability is absent', () => {
  shared.props.capabilities = { 'faqs.view': true, 'faqs.manage': true };
  act(() => {
    render(<FaqAiModal source={source} onAdd={vi.fn()} />);
  });
  expect(screen.queryByRole('button', { name: 'Generate with AI' })).toBeNull();
});
