import UrlSlugInput from '../../../components/admin/UrlSlugInput';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton, {
  EditorActionLink,
} from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import FormErrors from '../../../components/admin/FormErrors';
import FaqPicker from '../../../components/admin/FaqPicker';
import type { FaqDraft, FaqOption } from '../../../types/faqs';
import { usePreferences } from '../../../lib/usePreferences';
import { toLocalInput, toInstant } from '../../../lib/zonedDateTime';
import DateInput from '../../../components/admin/DateInput';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import RichText from '../../../components/admin/RichText';
import Select from '../../../components/admin/Select';
import FormBuilder, {
  emptyForm,
} from '../../../components/admin/pages/FormBuilder';
import {
  Check,
  Choice,
  Row,
  Section,
} from '../../../components/admin/form/fields';
import { pageLabels } from '../../../lib/pageLabels';
import { useUrlTab } from '../../../lib/useUrlTab';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  PageFormDefinition,
  PageEditor,
  PageNode,
  PageStatus,
  PageText,
} from '../../../types/pages';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  faqOptions?: FaqOption[];
  page: PageEditor | null;
  initialParent: string | null;
  tree: PageNode[];
  customerGroups: { id: string; name: string }[];
  facebookTypes: Record<string, string[]>;
  defaultLocale: string;
};

type FormData = {
  faqs_submitted: boolean;
  faq_ids: string[] | undefined;
  faq_drafts: FaqDraft[] | undefined;
  version: number | null;
  parent_id: string;
  position: string;
  status: PageStatus;
  created_on: string;
  facebook_type: string;
  available: boolean;
  available_from: string;
  available_until: string;
  translations: Record<string, PageText>;
  customer_groups: string[];
  form: PageFormDefinition;
};

const emptyText = (): PageText => ({
  name: '',
  content: '',
  slug: '',
  seo_title: '',
  meta_description: '',
  meta_keywords: '',
});

export default function PageForm(props: Props) {
  // A fresh editor after every save so the form mirrors the stored page.
  return (
    <PageEditorForm
      key={(props.page?.id ?? 'new') + ':' + (props.page?.version ?? 0)}
      {...props}
    />
  );
}

function PageEditorForm({
  page,
  initialParent,
  tree,
  customerGroups,
  facebookTypes,
  defaultLocale,
  faqOptions = [],
  ...shell
}: Props) {
  const t = useTranslation();
  const { timezone: timeZone } = usePreferences();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const allowed = !!capabilities['pages.manage'];
  const locales = i18n.locales;
  const [locale, setLocale] = useState(
    locales.some((l) => l.code === i18n.locale) ? i18n.locale : defaultLocale,
  );
  const [tab, setTab] = useUrlTab(
    (['general', 'additional', 'form', 'faqs'] as const).filter(
      (item) => item !== 'faqs' || capabilities['faqs.view'],
    ),
  );
  const form = useForm<FormData>({
    faqs_submitted: !!capabilities['faqs.view'],
    faq_ids: capabilities['faqs.view'] ? (page?.faq_ids ?? []) : undefined,
    faq_drafts: capabilities['faqs.view'] ? [] : undefined,
    version: page?.version ?? null,
    parent_id: page?.parent_id ?? initialParent ?? '',
    position: String(page?.position ?? 0),
    status: page?.status ?? 'active',
    created_on: page?.created_on ?? new Date().toISOString().slice(0, 10),
    facebook_type: page?.facebook_type ?? 'website',
    available: !!(page?.available_from || page?.available_until),
    available_from: toLocalInput(page?.available_from, timeZone),
    available_until: toLocalInput(page?.available_until, timeZone),
    translations: Object.fromEntries(
      locales.map((l) => [
        l.code,
        { ...emptyText(), ...(page?.translations[l.code] ?? {}) },
      ]),
    ),
    customer_groups: page?.customer_groups ?? [],
    form: page?.form ?? emptyForm(),
  });
  useDirtyForm(form);
  const data = form.data;
  const errors = form.errors as Record<string, string>;
  const text = data.translations[locale];
  const setText = (patch: Partial<PageText>) =>
    form.setData('translations', {
      ...data.translations,
      [locale]: { ...text, ...patch },
    });
  const err = (field: string) => errors[`translations.${locale}.${field}`];
  const title =
    data.translations[defaultLocale]?.name ||
    t(page ? 'pages.edit' : 'pages.create');

  // A page cannot move under itself or its own sub-pages.
  const blocked = new Set<string>();
  if (page) {
    blocked.add(page.id);
    let growing = true;
    while (growing) {
      growing = false;
      for (const node of tree)
        if (
          node.parent_id &&
          blocked.has(node.parent_id) &&
          !blocked.has(node.id)
        ) {
          blocked.add(node.id);
          growing = true;
        }
    }
  }

  const submit = () => {
    form.transform((values) => ({
      ...values,
      available_from: values.available
        ? toInstant(values.available_from, timeZone)
        : '',
      available_until: values.available
        ? toInstant(values.available_until, timeZone)
        : '',
      // A page that never had a form and still has none sends no form at all.
      form:
        page?.form || values.form.enabled || values.form.fields.length > 0
          ? values.form
          : undefined,
    }));
    if (page) form.put('/admin/pages/' + page.id, { preserveScroll: true });
    else form.post('/admin/pages', { preserveScroll: true });
  };
  const remove = () => {
    if (page && window.confirm(t('pages.delete_confirm', { name: title })))
      router.delete('/admin/pages/' + page.id, {
        data: { version: page.version },
      });
  };

  return (
    <AdminLayout title={title} {...shell}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (allowed) submit();
        }}
        id="admin-pages-form-0"
      >
        <EditorHeader
          heading={title}
          iconKey="content"
          backHref="/admin/pages"
          backLabel={t('pages.back')}
          status={
            <>
              <div>
                <EditorStatusSelect
                  aria-label={t('pages.status')}
                  value={data.status}
                  disabled={!allowed || form.processing}
                  invalid={!!errors.status}
                  onChange={(value) =>
                    form.setData('status', value as typeof data.status)
                  }
                  options={(['active', 'hidden', 'disabled'] as const).map(
                    (value) => ({ value, label: t(pageLabels.status[value]) }),
                  )}
                />
                {errors.status && (
                  <p role="alert" className="mt-1 text-xs text-red-700">
                    {errors.status}
                  </p>
                )}
              </div>
            </>
          }
          description={
            <>
              {!allowed && (
                <p className="mt-2 text-sm text-slate-500">
                  {t('pages.read_only')}
                </p>
              )}
            </>
          }
          actions={
            <>
              {tree.length > 0 && (
                <div className="w-full sm:w-72">
                  <Select
                    aria-label={t('pages.switch_page')}
                    value={page?.id ?? ''}
                    search={{
                      label: t('common.search_options'),
                      emptyMessage: t('common.no_options'),
                    }}
                    options={[
                      ...(page
                        ? []
                        : [
                            {
                              value: '',
                              label: t('pages.switch_page'),
                              disabled: true,
                            },
                          ]),
                      ...tree.map((node) => ({
                        value: node.id,
                        label: '\u00a0\u00a0'.repeat(node.depth) + node.name,
                      })),
                    ]}
                    onChange={(id) => {
                      if (id && id !== page?.id)
                        router.visit('/admin/pages/' + id + '/edit');
                    }}
                  />
                </div>
              )}
              <div className="flex flex-wrap gap-2">
                {page?.preview_url && (
                  <EditorActionLink
                    href={page.preview_url}
                    target="_blank"
                    rel="noopener"
                  >
                    {t('pages.preview')}
                  </EditorActionLink>
                )}

                {allowed && (
                  <EditorActionButton
                    disabled={form.processing}
                    form="admin-pages-form-0"
                  >
                    {t('pages.save')}
                  </EditorActionButton>
                )}
              </div>
            </>
          }
          menu={[
            page && allowed && (
              <button
                type="button"
                className="button-secondary text-red-700"
                onClick={remove}
              >
                {t('pages.delete')}
              </button>
            ),
          ]}
        />

        {(errors.version || errors.translations || errors.page) && (
          <p
            role="alert"
            className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"
          >
            {errors.version ?? errors.translations ?? errors.page}
          </p>
        )}

        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(([key]) => key.startsWith('faq_')),
          )}
        />
        <div>
          <div className="min-w-0 space-y-6">
            <AdminTabs
              label={t('pages.title')}
              language={
                <ContentLanguageSelector
                  languages={locales}
                  value={locale}
                  onChange={setLocale}
                  errors={errors}
                />
              }
            >
              {(['general', 'additional', 'form', 'faqs'] as const)
                .filter((item) => item !== 'faqs' || capabilities['faqs.view'])
                .map((item) => (
                  <AdminTab
                    key={item}
                    type="button"
                    role="tab"
                    aria-selected={tab === item}
                    onClick={() => setTab(item)}
                    iconKey={item}
                  >
                    {t(
                      item === 'faqs'
                        ? 'faqs.title'
                        : item === 'general'
                          ? 'pages.tab_general'
                          : item === 'additional'
                            ? 'pages.tab_additional'
                            : 'pages.tab_form',
                    )}
                    {(item === 'form' || item === 'faqs') &&
                      Object.keys(errors).some((key) =>
                        key.startsWith(item === 'faqs' ? 'faq_' : 'form.'),
                      ) && (
                        <span aria-hidden="true" className="ml-1 text-red-500">
                          •
                        </span>
                      )}
                  </AdminTab>
                ))}
            </AdminTabs>

            <div role="tabpanel" className="space-y-6">
              {tab === 'faqs' && capabilities['faqs.view'] ? (
                <FaqPicker
                  locale={locale}
                  ids={data.faq_ids ?? []}
                  drafts={data.faq_drafts ?? []}
                  options={faqOptions}
                  errors={errors}
                  disabled={!allowed}
                  source={{
                    owner: 'page',
                    object_id: page?.id ?? null,
                    source_name: data.translations[locale]?.name ?? '',
                    source_text: data.translations[locale]?.content ?? '',
                  }}
                  onIds={(ids) => form.setData('faq_ids', ids)}
                  onDrafts={(drafts) => form.setData('faq_drafts', drafts)}
                />
              ) : tab === 'general' ? (
                <>
                  <Section title={t('pages.section_info')} iconKey="content">
                    <Row
                      id="parent_id"
                      label={t('pages.parent')}
                      required
                      error={errors.parent_id}
                    >
                      <Choice
                        id="parent_id"
                        value={data.parent_id}
                        disabled={!allowed}
                        options={[
                          { value: '', label: t('pages.parent_root') },
                          ...tree
                            .filter((node) => !blocked.has(node.id))
                            .map((node) => ({
                              value: node.id,
                              label: '  '.repeat(node.depth) + node.name,
                            })),
                        ]}
                        onChange={(value) => form.setData('parent_id', value)}
                      />
                    </Row>
                    <Row
                      id={'name-' + locale}
                      label={
                        t('pages.name') +
                        (locales.length > 1 ? ' · ' + locale : '')
                      }
                      required={locale === defaultLocale}
                      error={err('name')}
                    >
                      <input
                        id={'name-' + locale}
                        className="field"
                        maxLength={200}
                        disabled={!allowed}
                        value={text.name}
                        onChange={(e) => setText({ name: e.target.value })}
                      />
                    </Row>
                    <Row
                      id={'content-' + locale}
                      label={t('pages.content')}
                      error={err('content')}
                    >
                      <RichText
                        key={locale}
                        id={'content-' + locale}
                        label={t('pages.content')}
                        profile="page"
                        maxLength={500000}
                        value={text.content}
                        disabled={!allowed}
                        invalid={!!err('content')}
                        onChange={(html) => setText({ content: html })}
                      />
                    </Row>
                    <Row
                      id="position"
                      label={t('pages.position')}
                      error={errors.position}
                    >
                      <input
                        id="position"
                        type="number"
                        className="field max-w-32"
                        disabled={!allowed}
                        value={data.position}
                        onChange={(e) =>
                          form.setData('position', e.target.value)
                        }
                      />
                    </Row>
                  </Section>

                  <Section title={t('pages.section_seo')} iconKey="seo">
                    <Row
                      id={'seo_title-' + locale}
                      label={t('pages.seo_title')}
                      help={t('pages.seo_title_help')}
                      error={err('seo_title')}
                    >
                      <input
                        id={'seo_title-' + locale}
                        className="field"
                        maxLength={200}
                        disabled={!allowed}
                        value={text.seo_title}
                        onChange={(e) => setText({ seo_title: e.target.value })}
                      />
                    </Row>
                    <Row
                      id={'meta_description-' + locale}
                      label={t('pages.meta_description')}
                      help={t('pages.meta_description_help')}
                      error={err('meta_description')}
                    >
                      <textarea
                        id={'meta_description-' + locale}
                        className="field min-h-24"
                        maxLength={500}
                        disabled={!allowed}
                        value={text.meta_description}
                        onChange={(e) =>
                          setText({ meta_description: e.target.value })
                        }
                      />
                    </Row>
                    <Row
                      id={'meta_keywords-' + locale}
                      label={t('pages.meta_keywords')}
                      help={t('pages.meta_keywords_help')}
                      error={err('meta_keywords')}
                    >
                      <textarea
                        id={'meta_keywords-' + locale}
                        className="field min-h-24"
                        maxLength={500}
                        disabled={!allowed}
                        value={text.meta_keywords}
                        onChange={(e) =>
                          setText({ meta_keywords: e.target.value })
                        }
                      />
                    </Row>
                  </Section>

                  <Section
                    title={t('pages.section_availability')}
                    iconKey="clock"
                  >
                    <Row
                      id="customer-group-all"
                      label={t('pages.customer_groups')}
                      help={t('pages.customer_groups_help')}
                      error={errors.customer_groups}
                    >
                      <div className="flex flex-wrap gap-x-5 gap-y-1">
                        <Check
                          id="customer-group-all"
                          label={t('pages.customer_groups_all')}
                          disabled={!allowed}
                          checked={data.customer_groups.length === 0}
                          onChange={(on) =>
                            on && form.setData('customer_groups', [])
                          }
                        />
                        {customerGroups.map((group) => (
                          <Check
                            key={group.id}
                            id={'customer-group-' + group.id}
                            label={group.name}
                            disabled={!allowed}
                            checked={data.customer_groups.includes(group.id)}
                            onChange={(on) =>
                              form.setData(
                                'customer_groups',
                                on
                                  ? [...data.customer_groups, group.id]
                                  : data.customer_groups.filter(
                                      (g) => g !== group.id,
                                    ),
                              )
                            }
                          />
                        ))}
                      </div>
                    </Row>
                    <Row
                      id="created_on"
                      label={t('pages.created_on')}
                      error={errors.created_on}
                    >
                      <DateInput
                        id="created_on"
                        className="field max-w-xs"
                        disabled={!allowed}
                        value={data.created_on}
                        onChange={(value) => form.setData('created_on', value)}
                      />
                    </Row>
                    <Row id="available" label={t('pages.available')}>
                      <Check
                        id="available"
                        checked={data.available}
                        disabled={!allowed}
                        onChange={(on) => form.setData('available', on)}
                      />
                    </Row>
                    <Row
                      id="available_from"
                      label={t('pages.available_from')}
                      error={errors.available_from}
                    >
                      <input
                        id="available_from"
                        type="datetime-local"
                        className="field max-w-xs"
                        disabled={!allowed || !data.available}
                        value={data.available_from}
                        onChange={(e) =>
                          form.setData('available_from', e.target.value)
                        }
                      />
                    </Row>
                    <Row
                      id="available_until"
                      label={t('pages.available_until')}
                      error={errors.available_until}
                    >
                      <input
                        id="available_until"
                        type="datetime-local"
                        className="field max-w-xs"
                        disabled={!allowed || !data.available}
                        value={data.available_until}
                        onChange={(e) =>
                          form.setData('available_until', e.target.value)
                        }
                      />
                      {data.available && (
                        <p className="text-xs text-slate-500">
                          {t('pages.timezone_note', { zone: timeZone })}
                        </p>
                      )}
                    </Row>
                  </Section>
                </>
              ) : tab === 'form' ? (
                <FormBuilder
                  form={data.form}
                  onChange={(next) => form.setData('form', next)}
                  locale={locale}
                  defaultLocale={defaultLocale}
                  errors={errors}
                  disabled={!allowed}
                  onInsertMarker={() => {
                    const current = data.translations[defaultLocale];
                    if (current.content.includes('[form]')) return;
                    form.setData('translations', {
                      ...data.translations,
                      [defaultLocale]: {
                        ...current,
                        content: current.content + '<p>[form]</p>',
                      },
                    });
                  }}
                />
              ) : (
                <>
                  <Section title={t('pages.section_facebook')} iconKey="social">
                    <Row
                      id="facebook_type"
                      label={t('pages.facebook_type')}
                      help={t('pages.facebook_help')}
                      error={errors.facebook_type}
                    >
                      <select
                        id="facebook_type"
                        className="field max-w-md"
                        disabled={!allowed}
                        value={data.facebook_type}
                        onChange={(e) =>
                          form.setData('facebook_type', e.target.value)
                        }
                      >
                        {Object.entries(facebookTypes).map(([group, types]) => (
                          <optgroup
                            key={group}
                            label={t(pageLabels.facebook[group] ?? group)}
                          >
                            {types.map((type) => (
                              <option key={type} value={type}>
                                {type}
                              </option>
                            ))}
                          </optgroup>
                        ))}
                      </select>
                    </Row>
                  </Section>
                  <Section
                    title={t('pages.section_seo_name')}
                    iconKey="similar_products"
                  >
                    <Row
                      id={'slug-' + locale}
                      label={
                        t('pages.slug') +
                        (locales.length > 1 ? ' · ' + locale : '')
                      }
                      help={t('pages.slug_help')}
                      error={err('slug')}
                    >
                      <div className="max-w-md">
                        <UrlSlugInput
                          prefix="/"
                          id={'slug-' + locale}

                          maxLength={200}
                          disabled={!allowed}
                          value={text.slug}
                          onChange={(e) =>
                            setText({ slug: e.target.value.toLowerCase() })
                          }
                        />
                      </div>
                    </Row>
                  </Section>
                </>
              )}
            </div>

            {allowed && (
              <div className="flex justify-end">
                <EditorActionButton disabled={form.processing}>
                  {t('pages.save')}
                </EditorActionButton>
              </div>
            )}
          </div>
        </div>
      </form>
    </AdminLayout>
  );
}
