import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import AiChat from '../../../components/admin/AiChat';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import RichText from '../../../components/admin/RichText';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  Choice,
  NewsletterEditor,
  NewsletterText,
  NewsletterType,
  TemplateChoice,
} from '../../../types/newsletters';
import type { SharedProps } from '../../../types/page';

const titles: Record<NewsletterType, string> = {
  newsletter: 'newsletters.newsletters_title',
  template: 'newsletters.templates_title',
  autoresponder: 'newsletters.autoresponders_title',
};
// Literal keys (translation keys are never built at runtime).
const variables: [string, string][] = [
  ['unsubscribe_link', 'newsletters.var_unsubscribe_link'],
  ['activation_link', 'newsletters.var_activation_link'],
  ['subscriber_email', 'newsletters.var_subscriber_email'],
  ['company_name', 'newsletters.var_company_name'],
  ['company_address', 'newsletters.var_company_address'],
  ['company_phone', 'newsletters.var_company_phone'],
];
function xsrf(): string {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}

const empty: NewsletterText = {
  name: '',
  subject: '',
  random_subjects: '',
  body_html: '',
};

function initial(item: NewsletterEditor | null, locales: { code: string }[]) {
  return {
    version: item?.version ?? 1,
    is_active: item?.active ?? true,
    campaign_id: item?.campaign_id ?? '',
    translations: Object.fromEntries(
      locales.map((l) => [l.code, item?.translations[l.code] ?? empty]),
    ) as Record<string, NewsletterText>,
    list_ids: item?.list_ids ?? ([] as string[]),
    customer_ids: item?.customers.map((c) => c.id) ?? ([] as string[]),
  };
}

type Customer = { id: string; name: string; email: string };

/** Registered customers addressed directly: found by a server search, kept as removable chips. */
function CustomerPicker({
  selected,
  onChange,
  error,
}: {
  selected: Customer[];
  onChange: (customers: Customer[]) => void;
  error?: string;
}) {
  const t = useTranslation();
  const [term, setTerm] = useState('');
  const [found, setFound] = useState<Customer[]>([]);
  useEffect(() => {
    if (term.trim().length < 2) return;
    let current = true;
    const timer = window.setTimeout(() => {
      fetch('/admin/newsletters/customers?term=' + encodeURIComponent(term), {
        headers: { Accept: 'application/json' },
      })
        .then((r) => (r.ok ? r.json() : { customers: [] }))
        .then((data: { customers: Customer[] }) => {
          if (current) setFound(data.customers);
        })
        .catch(() => undefined);
    }, 300);
    return () => {
      current = false;
      window.clearTimeout(timer);
    };
  }, [term]);
  const options = term.trim().length < 2 ? [] : found;
  return (
    <div className="space-y-2">
      <FormField
        id="customer-search"
        label={t('newsletters.customers')}
        hint={t('newsletters.customers_hint')}
        value={term}
        error={error}
        onChange={(e) => setTerm(e.target.value)}
      />
      {options.length > 0 && (
        <ul className="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
          {options
            .filter((o) => !selected.some((s) => s.id === o.id))
            .map((o) => (
              <li key={o.id}>
                <button
                  type="button"
                  className="w-full px-3 py-2 text-left hover:bg-slate-50"
                  onClick={() => onChange([...selected, o])}
                >
                  {o.name} · {o.email}
                </button>
              </li>
            ))}
        </ul>
      )}
      {selected.length > 0 && (
        <ul className="flex flex-wrap gap-2">
          {selected.map((c) => (
            <li
              key={c.id}
              className="flex items-center gap-2 rounded-full bg-slate-100 py-1 pr-1 pl-3 text-sm"
            >
              {c.name}
              <button
                type="button"
                className="rounded-full px-2 text-slate-500 hover:bg-slate-200"
                aria-label={t('newsletters.remove_customer', { name: c.name })}
                onClick={() => onChange(selected.filter((s) => s.id !== c.id))}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default function NewsletterForm({
  type,
  base,
  item,
  campaigns,
  lists,
  templates,
  defaultLocale,
  canSend,
  aiReady = false,
  ...shell
}: AdminShell & {
  type: NewsletterType;
  base: string;
  item: NewsletterEditor | null;
  campaigns: Choice[];
  lists: Choice[];
  templates: TemplateChoice[];
  defaultLocale: string;
  canSend: boolean;
  aiReady?: boolean;
}) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const showAi = !!capabilities['newsletters.generate'];
  const [tab, setTab] = useState<'content' | 'ai'>('content');
  const [copied, setCopied] = useState('');
  const [loads, setLoads] = useState(0);
  const [preview, setPreview] = useState<{
    subject: string;
    html: string;
  } | null>(null);
  const [previewError, setPreviewError] = useState('');
  const [previewing, setPreviewing] = useState(false);
  const [template, setTemplate] = useState('');
  const [customers, setCustomers] = useState<Customer[]>(item?.customers ?? []);
  const form = useForm(initial(item, i18n.locales));
  const test = useForm({ email: '', locale });
  const errors = form.errors as Record<string, string>;
  const text = form.data.translations[locale] ?? empty;
  const setText = (patch: Partial<NewsletterText>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...patch },
    });
  const previewKey = JSON.stringify([
    locale,
    text.subject,
    text.body_html,
    type,
  ]);
  // Live preview: the server renders the unsaved content with sample data (nothing is saved or sent).
  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const [code, subject, body, type] = JSON.parse(previewKey) as string[];
      setPreviewing(true);
      fetch('/admin/newsletters/preview', {
        method: 'POST',
        signal: controller.signal,
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({ locale: code, subject, body_html: body, type }),
      })
        .then(async (response) => {
          const data = await response.json();
          if (!response.ok) {
            const first = Object.values(
              (data.errors ?? {}) as Record<string, string[]>,
            )[0];
            setPreviewError(
              first?.[0] ?? data.message ?? String(response.status),
            );
            return;
          }
          setPreviewError('');
          setPreview(data);
        })
        .catch((error: unknown) => {
          if (!(error instanceof DOMException && error.name === 'AbortError'))
            setPreviewError(String(error));
        })
        .finally(() => setPreviewing(false));
    }, 500);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [previewKey]);
  const isNewsletter = type === 'newsletter';
  const hasSubject = type !== 'template';
  const title = item
    ? (item.translations[locale]?.name ??
      item.translations[defaultLocale]?.name ??
      '')
    : t('newsletters.add');
  const sync = (page: { props: unknown }) => {
    const next = (page.props as { item: NewsletterEditor | null }).item;
    const data = initial(next, i18n.locales);
    form.setDefaults(data);
    form.setData(data);
    setCustomers(next?.customers ?? []);
  };
  const copy = (name: string) => {
    void navigator.clipboard?.writeText('{{' + name + '}}').then(() => {
      setCopied(name);
      window.setTimeout(() => setCopied(''), 1500);
    });
  };
  const load = () => {
    const chosen = templates.find((x) => x.id === template);
    if (!chosen) return;
    const body = chosen.bodies[locale] ?? chosen.bodies[defaultLocale] ?? '';
    if (text.body_html && !window.confirm(t('newsletters.load_confirm')))
      return;
    setText({ body_html: body });
    setLoads(loads + 1);
  };
  const toggle = (id: string, checked: boolean) =>
    form.setData(
      'list_ids',
      checked
        ? [...form.data.list_ids, id]
        : form.data.list_ids.filter((x) => x !== id),
    );
  const send = () => {
    if (!item || !window.confirm(t('newsletters.send_confirm'))) return;
    router.post(base + '/' + item.id + '/send', {}, { preserveScroll: true });
  };

  return (
    <AdminLayout title={title || t(titles[type])} {...shell}>
      <EditorHeader
        heading={title || t('newsletters.add')}
        iconKey="emails"
        backHref={base}
        backLabel={t(titles[type])}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-newsletters-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_30rem]">
        <div className="min-w-0 space-y-5">
          {showAi && (
            <AdminTabs
              label={t(titles[type])}
              language={
                // The details card has its own selector; the AI tab needs one too.
                tab === 'ai' ? (
                  <ContentLanguageSelector
                    languages={i18n.locales}
                    value={locale}
                    onChange={setLocale}
                    errors={errors}
                  />
                ) : undefined
              }
            >
              {(['content', 'ai'] as const).map((item) => (
                <AdminTab
                  key={item}
                  type="button"
                  role="tab"
                  aria-selected={tab === item}
                  onClick={() => setTab(item)}
                  iconKey={item === 'ai' ? 'ai' : 'content'}
                >
                  {t(
                    item === 'ai'
                      ? 'newsletters.ai_tab'
                      : 'newsletters.tab_content',
                  )}
                </AdminTab>
              ))}
            </AdminTabs>
          )}
          {showAi && (
            // Hidden rather than unmounted, so the conversation survives switching tabs; the live preview shows each reply.
            <div role="tabpanel" hidden={tab !== 'ai'}>
              <AiChat<NewsletterText>
                endpoint="/admin/newsletters/assistant"
                payload={{ locale, type, newsletter_id: item?.id ?? null }}
                ready={aiReady}
                current={() => ({ ...text })}
                // The language is the one the request was written for, even if the editor switched meanwhile.
                onApply={(draft) =>
                  form.setData((current) => ({
                    ...current,
                    translations: {
                      ...current.translations,
                      [locale]: { ...current.translations[locale], ...draft },
                    },
                  }))
                }
                label={t('newsletters.ai_tab')}
                intro={t('newsletters.ai_intro')}
                placeholder={t('newsletters.ai_placeholder')}
                consent={t('newsletters.ai_consent')}
                notConfigured={t('newsletters.ai_not_configured')}
                examples={[
                  t('newsletters.ai_example_1'),
                  t('newsletters.ai_example_2'),
                  t('newsletters.ai_example_3'),
                ]}
              />
            </div>
          )}
          <div className="space-y-5" hidden={tab === 'ai'}>
            <form
              className="space-y-5"
              onSubmit={(event) => {
                event.preventDefault();
                const options = { preserveScroll: true, onSuccess: sync };
                if (item) form.put(base + '/' + item.id, options);
                else form.post(base, options);
              }}
              id="admin-newsletters-form-0"
            >
              <FormErrors errors={errors} />

              <section>
                <UnifiedCard
                  title={t('common.details')}
                  iconKey="details"
                  bodyClassName="p-5 space-y-5"
                >
                  <AdminTabs
                    language={
                      <ContentLanguageSelector
                        languages={i18n.locales}
                        value={locale}
                        onChange={setLocale}
                        errors={errors}
                      />
                    }
                  />
                  <FormField
                    id={'name-' + locale}
                    label={t('common.name') + ' · ' + locale}
                    hint={t('newsletters.name_hint')}
                    requiredIndicator={locale === defaultLocale}
                    maxLength={150}
                    value={text.name}
                    error={errors['translations.' + locale + '.name']}
                    onChange={(e) => setText({ name: e.target.value })}
                  />
                  {hasSubject && (
                    <FormField
                      id={'subject-' + locale}
                      label={t('newsletters.subject')}
                      requiredIndicator={locale === defaultLocale}
                      maxLength={300}
                      value={text.subject}
                      error={errors['translations.' + locale + '.subject']}
                      onChange={(e) => setText({ subject: e.target.value })}
                    />
                  )}
                  {isNewsletter && (
                    <div className="space-y-2">
                      <label
                        className="block text-sm font-medium"
                        htmlFor={'random-' + locale}
                      >
                        {t('newsletters.random_subjects')}
                      </label>
                      <textarea
                        id={'random-' + locale}
                        className="field min-h-20"
                        value={text.random_subjects}
                        onChange={(e) =>
                          setText({ random_subjects: e.target.value })
                        }
                      />
                      <p className="text-xs text-slate-500">
                        {t('newsletters.random_subjects_hint')}
                      </p>
                      {errors[
                        'translations.' + locale + '.random_subjects'
                      ] && (
                        <p role="alert" className="text-sm text-red-700">
                          {
                            errors[
                              'translations.' + locale + '.random_subjects'
                            ]
                          }
                        </p>
                      )}
                    </div>
                  )}
                  {templates.length > 0 && (
                    <div className="flex flex-wrap items-end gap-3">
                      <div className="min-w-56 space-y-2">
                        <label
                          className="block text-sm font-medium"
                          htmlFor="template-load"
                        >
                          {t('newsletters.template')}
                        </label>
                        <Select
                          id="template-load"
                          aria-label={t('newsletters.template')}
                          value={template}
                          onChange={setTemplate}
                          options={[
                            {
                              value: '',
                              label: t('newsletters.choose_template'),
                            },
                            ...templates.map((x) => ({
                              value: x.id,
                              label: x.name,
                            })),
                          ]}
                        />
                      </div>
                      <button
                        type="button"
                        className="button-secondary"
                        disabled={!template}
                        onClick={load}
                      >
                        {t('newsletters.load')}
                      </button>
                    </div>
                  )}
                  <div className="space-y-2">
                    <RichText
                      key={locale + ':' + loads}
                      id={'body-' + locale}
                      label={t('newsletters.body')}
                      profile="page"
                      maxLength={200000}
                      value={text.body_html}
                      invalid={
                        !!errors['translations.' + locale + '.body_html']
                      }
                      onChange={(html) => setText({ body_html: html })}
                    />
                    <p className="text-xs text-slate-500">
                      {t('newsletters.placeholders_hint')}
                    </p>
                    {errors['translations.' + locale + '.body_html'] && (
                      <p role="alert" className="text-sm text-red-700">
                        {errors['translations.' + locale + '.body_html']}
                      </p>
                    )}
                  </div>
                  <div className="space-y-2 rounded-md bg-slate-50 p-4">
                    <h2 className="text-sm font-semibold">
                      {t('newsletters.variables_title')}
                    </h2>
                    <p className="text-xs text-slate-500">
                      {t('newsletters.variables_hint')}
                    </p>
                    <ul className="grid gap-2 sm:grid-cols-2">
                      {variables.map(([name, label]) => (
                        <li
                          key={name}
                          className="flex items-center gap-2 text-sm"
                        >
                          <button
                            type="button"
                            className="rounded border border-slate-200 bg-white px-2 py-1 font-mono text-xs hover:bg-slate-100"
                            onClick={() => copy(name)}
                          >
                            {'{{' + name + '}}'}
                          </button>
                          <span className="text-slate-600">
                            {copied === name
                              ? t('newsletters.variable_copied')
                              : t(label)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                </UnifiedCard>
              </section>

              <section>
                <UnifiedCard
                  title={t('common.details')}
                  iconKey="details"
                  bodyClassName="p-5 grid gap-5 sm:grid-cols-2"
                >
                  <label className="flex items-start gap-2 text-sm sm:col-span-2">
                    <input
                      type="checkbox"
                      className="mt-0.5"
                      checked={form.data.is_active}
                      onChange={(e) =>
                        form.setData('is_active', e.target.checked)
                      }
                    />
                    <span>
                      <span className="font-medium">
                        {t('newsletters.active')}
                      </span>
                      <span className="block text-xs text-slate-500">
                        {t('newsletters.active_hint')}
                      </span>
                    </span>
                  </label>
                  {isNewsletter && (
                    <div className="space-y-2">
                      <label
                        className="block text-sm font-medium"
                        htmlFor="campaign"
                      >
                        {t('newsletters.campaign')}
                      </label>
                      <Select
                        id="campaign"
                        aria-label={t('newsletters.campaign')}
                        value={form.data.campaign_id}
                        onChange={(value) => form.setData('campaign_id', value)}
                        options={[
                          { value: '', label: t('newsletters.no_campaign') },
                          ...campaigns.map((c) => ({
                            value: c.id,
                            label: c.name,
                          })),
                        ]}
                      />
                    </div>
                  )}
                </UnifiedCard>
              </section>

              {isNewsletter && (
                <section>
                  <UnifiedCard
                    title={t('newsletters.send_to')}
                    iconKey="send_to"
                    bodyClassName="p-5 space-y-5"
                  >
                    <p className="mt-1 text-xs text-slate-500">
                      {t('newsletters.send_to_hint')}
                    </p>

                    <fieldset className="space-y-2">
                      <legend className="text-sm font-medium">
                        {t('newsletters.lists_title')}
                      </legend>
                      {lists.length === 0 ? (
                        <p className="text-sm text-slate-500">
                          {t('newsletters.no_lists')}
                        </p>
                      ) : (
                        <div className="grid gap-1 sm:grid-cols-2">
                          {lists.map((l) => (
                            <label
                              key={l.id}
                              className="flex items-center gap-2 text-sm"
                            >
                              <input
                                type="checkbox"
                                checked={form.data.list_ids.includes(l.id)}
                                onChange={(e) => toggle(l.id, e.target.checked)}
                              />
                              {l.name}
                            </label>
                          ))}
                        </div>
                      )}
                      {errors.list_ids && (
                        <p role="alert" className="text-sm text-red-700">
                          {errors.list_ids}
                        </p>
                      )}
                    </fieldset>
                    <CustomerPicker
                      selected={customers}
                      error={errors.customer_ids}
                      onChange={(next) => {
                        setCustomers(next);
                        form.setData(
                          'customer_ids',
                          next.map((c) => c.id),
                        );
                      }}
                    />
                  </UnifiedCard>
                </section>
              )}
            </form>

            {item && canSend && (
              <div className="mt-5 space-y-5">
                <section>
                  <AdminCard
                    title={t('newsletters.test_title')}
                    iconKey="test_title"
                    bodyClassName="p-5 space-y-3"
                  >
                    <form
                      className="flex flex-wrap items-end gap-3"
                      onSubmit={(event) => {
                        event.preventDefault();
                        test.transform((data) => ({ ...data, locale }));
                        test.post(base + '/' + item.id + '/test', {
                          preserveScroll: true,
                        });
                      }}
                    >
                      <div className="min-w-64 grow">
                        <FormField
                          id="test-email"
                          type="email"
                          label={t('newsletters.test_email')}
                          value={test.data.email}
                          error={test.errors.email}
                          onChange={(e) =>
                            test.setData('email', e.target.value)
                          }
                        />
                      </div>
                      <button
                        className="button-secondary"
                        disabled={test.processing || form.isDirty}
                      >
                        {t('newsletters.test_send')}
                      </button>
                    </form>
                    {form.isDirty && (
                      <p className="text-xs text-amber-700">
                        {t('newsletters.save_first')}
                      </p>
                    )}
                  </AdminCard>
                </section>
                {isNewsletter && (
                  <section>
                    <AdminCard
                      title={t('newsletters.send_title')}
                      iconKey="send_title"
                      bodyClassName="p-5 space-y-3"
                    >
                      {item.stats.total > 0 && (
                        <p className="text-sm text-slate-600 tabular-nums">
                          {t('newsletters.stats_line', {
                            sent: item.stats.sent,
                            total: item.stats.total,
                            failed: item.stats.failed,
                          })}
                        </p>
                      )}
                      <p className="text-xs text-slate-500">
                        {t('newsletters.send_hint')}
                      </p>
                      <button
                        type="button"
                        className="button-primary"
                        disabled={
                          form.isDirty ||
                          !form.data.is_active ||
                          !capabilities['newsletters.send']
                        }
                        onClick={send}
                      >
                        {t(
                          item.state === 'draft'
                            ? 'newsletters.send'
                            : 'newsletters.send_again',
                        )}
                      </button>
                      {form.isDirty && (
                        <p className="text-xs text-amber-700">
                          {t('newsletters.save_first')}
                        </p>
                      )}
                    </AdminCard>
                  </section>
                )}
              </div>
            )}
          </div>
        </div>
        <aside className="space-y-4 xl:sticky xl:top-4 xl:self-start">
          <section>
            <UnifiedCard
              title={t('newsletters.live_preview')}
              iconKey="live_preview"
              action={
                <div className="flex items-center justify-between gap-3">
                  {previewing && (
                    <span role="status" className="text-xs text-slate-500">
                      {t('newsletters.preview_updating')}
                    </span>
                  )}
                </div>
              }
              bodyClassName="p-5 space-y-3"
            >
              {previewError && (
                <p role="alert" className="text-sm text-red-700">
                  {t('newsletters.preview_error', { message: previewError })}
                </p>
              )}
              {preview && (
                <>
                  {hasSubject && (
                    <p className="text-sm">
                      <span className="text-slate-500">
                        {t('newsletters.subject')}:{' '}
                      </span>
                      <strong>{preview.subject}</strong>
                    </p>
                  )}
                  <iframe
                    title={t('newsletters.live_preview')}
                    sandbox=""
                    className="h-[42rem] w-full rounded-md border border-slate-200 bg-white"
                    srcDoc={preview.html}
                  />
                </>
              )}
              <p className="text-xs text-slate-500">
                {t('newsletters.preview_hint')}
              </p>
            </UnifiedCard>
          </section>
        </aside>
      </div>
    </AdminLayout>
  );
}
