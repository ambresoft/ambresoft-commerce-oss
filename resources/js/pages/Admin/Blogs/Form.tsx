import UrlSlugInput from '../../../components/admin/UrlSlugInput';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton, {
  EditorActionLink,
} from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import RichText from '../../../components/admin/RichText';
import AiChat from '../../../components/admin/AiChat';
import { htmlToText } from '../../../lib/htmlText';
import {
  Check,
  Choice,
  Row,
  Section,
} from '../../../components/admin/form/fields';
import { pageLabels } from '../../../lib/pageLabels';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { usePreferences } from '../../../lib/usePreferences';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import { toInstant, toLocalInput } from '../../../lib/zonedDateTime';
import type { AdminShell } from '../../../types/admin';
import type { BlogEditor, BlogKind, BlogText } from '../../../types/blogs';
import type { SharedProps } from '../../../types/page';
import type { PageStatus } from '../../../types/pages';

type Props = AdminShell & {
  kind: BlogKind;
  item: BlogEditor | null;
  initialBlog: string | null;
  blogs: { id: string; name: string }[];
  facebookTypes: Record<string, string[]>;
  defaultLocale: string;
  aiReady?: boolean;
};

/** What the AI writer returns for one language; slug and cover text stay the merchant's. */
type PostDraft = Pick<
  BlogText,
  | 'name'
  | 'content'
  | 'excerpt'
  | 'seo_title'
  | 'meta_description'
  | 'meta_keywords'
  | 'tags'
>;

type FormData = {
  version: number | null;
  blog_id: string;
  position: string;
  status: PageStatus;
  published_at: string;
  facebook_type: string;
  available: boolean;
  available_from: string;
  available_until: string;
  translations: Record<string, BlogText>;
  cover: File | null;
  remove_cover: boolean;
  cover_mobile: File | null;
  remove_cover_mobile: boolean;
};

const emptyText = (kind: BlogKind): BlogText => ({
  name: '',
  content: '',
  slug: '',
  seo_title: '',
  meta_description: '',
  meta_keywords: '',
  ...(kind === 'post' ? { excerpt: '', cover_alt: '', tags: '' } : {}),
});

export default function BlogForm(props: Props) {
  // A fresh editor after every save so the form mirrors what was stored.
  return (
    <Editor
      key={(props.item?.id ?? 'new') + ':' + (props.item?.version ?? 0)}
      {...props}
    />
  );
}

/** A blog (landing page) or a blog post; posts add their blog, publication, excerpt, cover and tags. */
function Editor({
  kind,
  item,
  initialBlog,
  blogs,
  facebookTypes,
  defaultLocale,
  aiReady = false,
  ...shell
}: Props) {
  const t = useTranslation();
  const { timezone: timeZone } = usePreferences();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const allowed = !!capabilities['blog.manage'];
  const post = kind === 'post';
  const base = post ? '/admin/blog-posts' : '/admin/blogs';
  const locales = i18n.locales;
  const [locale, setLocale] = useState(
    locales.some((l) => l.code === i18n.locale) ? i18n.locale : defaultLocale,
  );
  const showAi = post && allowed && !!capabilities['blog.generate'];
  const [tab, setTab] = useUrlTab(
    showAi
      ? (['general', 'additional', 'ai'] as const)
      : (['general', 'additional'] as const),
  );
  const form = useForm<FormData>({
    version: item?.version ?? null,
    blog_id: item?.blog_id ?? initialBlog ?? blogs[0]?.id ?? '',
    position: String(item?.position ?? 0),
    status: item?.status ?? 'active',
    // A new post is published now unless the date is cleared (draft).
    published_at: item
      ? toLocalInput(item.published_at, timeZone)
      : post
        ? toLocalInput(new Date().toISOString(), timeZone)
        : '',
    facebook_type: item?.facebook_type ?? (post ? 'article' : 'blog'),
    available: !!(item?.available_from || item?.available_until),
    available_from: toLocalInput(item?.available_from, timeZone),
    available_until: toLocalInput(item?.available_until, timeZone),
    translations: Object.fromEntries(
      locales.map((l) => [
        l.code,
        { ...emptyText(kind), ...(item?.translations[l.code] ?? {}) },
      ]),
    ),
    cover: null,
    remove_cover: false,
    cover_mobile: null,
    remove_cover_mobile: false,
  });
  useDirtyForm(form);
  const data = form.data;
  const errors = form.errors as Record<string, string>;
  const text = data.translations[locale];
  const setText = (patch: Partial<BlogText>) =>
    form.setData('translations', {
      ...data.translations,
      [locale]: { ...text, ...patch },
    });
  const err = (field: string) => errors[`translations.${locale}.${field}`];
  const title =
    data.translations[defaultLocale]?.name ||
    t(
      item
        ? post
          ? 'blogs.edit_post'
          : 'blogs.edit'
        : post
          ? 'blogs.create_post'
          : 'blogs.create',
    );

  const submit = () => {
    form.transform((values) => ({
      ...values,
      published_at: toInstant(values.published_at, timeZone),
      available_from: values.available
        ? toInstant(values.available_from, timeZone)
        : '',
      available_until: values.available
        ? toInstant(values.available_until, timeZone)
        : '',
    }));
    form.post(item ? base + '/' + item.id : base, {
      forceFormData: true,
      preserveScroll: true,
    });
  };
  const remove = () => {
    if (item && window.confirm(t('blogs.delete_confirm', { name: title })))
      router.delete(base + '/' + item.id, { data: { version: item.version } });
  };
  const input = (field: keyof BlogText, max: number, area = false) => {
    const props = {
      id: field + '-' + locale,
      className: area ? 'field min-h-24' : 'field',
      maxLength: max,
      disabled: !allowed,
      value: text[field] ?? '',
      onChange: (
        e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>,
      ) => setText({ [field]: e.target.value }),
    };
    return area ? <textarea {...props} /> : <input {...props} />;
  };

  return (
    <AdminLayout title={title} {...shell}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (allowed) submit();
        }}
        id="admin-blogs-form-0"
      >
        <EditorHeader
          heading={title}
          iconKey="blog"
          backHref={base}
          backLabel={t('blogs.back')}
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
                  {t('blogs.read_only')}
                </p>
              )}
            </>
          }
          actions={
            <>
              <div className="flex flex-wrap gap-2">
                {item?.preview_url && (
                  <EditorActionLink
                    href={item.preview_url}
                    target="_blank"
                    rel="noopener"
                  >
                    {t('pages.preview')}
                  </EditorActionLink>
                )}

                {allowed && (
                  <EditorActionButton
                    disabled={form.processing}
                    form="admin-blogs-form-0"
                  >
                    {t('pages.save')}
                  </EditorActionButton>
                )}
              </div>
            </>
          }
          menu={[
            item && allowed && (
              <button
                type="button"
                className="button-secondary text-red-700"
                onClick={remove}
              >
                {t('blogs.delete')}
              </button>
            ),
          ]}
        />

        {(errors.version || errors.translations || errors.blog) && (
          <p
            role="alert"
            className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"
          >
            {errors.version ?? errors.translations ?? errors.blog}
          </p>
        )}

        <div className="min-w-0 space-y-6">
          <AdminTabs
            label={title}
            language={
              <ContentLanguageSelector
                languages={locales}
                value={locale}
                onChange={setLocale}
                errors={errors}
              />
            }
          >
            {(showAi
              ? (['general', 'additional', 'ai'] as const)
              : (['general', 'additional'] as const)
            ).map((item) => (
              <AdminTab
                key={item}
                type="button"
                role="tab"
                aria-selected={tab === item}
                onClick={() => setTab(item)}
                iconKey={item}
              >
                {t(
                  item === 'general'
                    ? 'pages.tab_general'
                    : item === 'ai'
                      ? 'blogs.ai_tab'
                      : 'pages.tab_additional',
                )}
                {item === 'additional' &&
                  [
                    'facebook_type',
                    `translations.${locale}.slug`,
                    `translations.${locale}.tags`,
                  ].some((key) => errors[key]) && (
                    <span aria-hidden="true" className="ml-1 text-red-500">
                      •
                    </span>
                  )}
              </AdminTab>
            ))}
          </AdminTabs>

          <div role="tabpanel" className="space-y-6" hidden={tab === 'ai'}>
            {tab === 'general' ? (
              <>
                <Section title={t('pages.section_info')} iconKey="content">
                  {post && (
                    <Row
                      id="blog_id"
                      label={t('blogs.blog')}
                      required
                      error={errors.blog_id}
                    >
                      <Choice
                        id="blog_id"
                        value={data.blog_id}
                        disabled={!allowed}
                        options={blogs.map((b) => ({
                          value: b.id,
                          label: b.name,
                        }))}
                        onChange={(value) => form.setData('blog_id', value)}
                      />
                    </Row>
                  )}
                  <Row
                    id={'name-' + locale}
                    label={
                      t(post ? 'blogs.post_name' : 'blogs.name') +
                      (locales.length > 1 ? ' · ' + locale : '')
                    }
                    required={locale === defaultLocale}
                    error={err('name')}
                  >
                    {input('name', 200)}
                  </Row>
                  {post && (
                    <Row
                      id={'excerpt-' + locale}
                      label={t('blogs.excerpt')}
                      help={t('blogs.excerpt_help')}
                      error={err('excerpt')}
                    >
                      {input('excerpt', 1000, true)}
                    </Row>
                  )}
                  <Row
                    id={'content-' + locale}
                    label={t(post ? 'blogs.post_content' : 'blogs.content')}
                    error={err('content')}
                  >
                    <RichText
                      key={locale}
                      id={'content-' + locale}
                      label={t(post ? 'blogs.post_content' : 'blogs.content')}
                      profile="page"
                      maxLength={500000}
                      value={text.content}
                      disabled={!allowed}
                      invalid={!!err('content')}
                      onChange={(html) => setText({ content: html })}
                    />
                  </Row>
                  {!post && (
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
                  )}

                  {post && (
                    <Row
                      id="published_at"
                      label={t('blogs.published_at')}
                      help={t('blogs.published_at_help')}
                      error={errors.published_at}
                    >
                      <input
                        id="published_at"
                        type="datetime-local"
                        className="field max-w-xs"
                        disabled={!allowed}
                        value={data.published_at}
                        onChange={(e) =>
                          form.setData('published_at', e.target.value)
                        }
                      />
                    </Row>
                  )}
                </Section>

                {post && (
                  <Section title={t('blogs.cover')} iconKey="media">
                    <SingleImageUploader
                      title={t('blogs.cover')}
                      help={t('blogs.cover_help')}
                      current={item?.cover ?? null}
                      alt={text.cover_alt || text.name}
                      file={data.cover}
                      onFile={(file) => form.setData('cover', file)}
                      removed={data.remove_cover}
                      onRemoved={(value) => form.setData('remove_cover', value)}
                      progress={form.progress?.percentage}
                      disabled={!allowed || form.processing}
                      error={errors.cover}
                    />
                    <SingleImageUploader
                      title={t('blogs.cover_mobile')}
                      help={t('blogs.cover_mobile_help')}
                      current={item?.cover_mobile ?? null}
                      alt={text.cover_alt || text.name}
                      file={data.cover_mobile}
                      onFile={(file) => form.setData('cover_mobile', file)}
                      removed={data.remove_cover_mobile}
                      onRemoved={(value) =>
                        form.setData('remove_cover_mobile', value)
                      }
                      progress={form.progress?.percentage}
                      disabled={!allowed || form.processing}
                      error={errors.cover_mobile}
                    />
                    <Row
                      id={'cover_alt-' + locale}
                      label={t('blogs.cover_alt')}
                      error={err('cover_alt')}
                    >
                      {input('cover_alt', 200)}
                    </Row>
                  </Section>
                )}

                <Section title={t('pages.section_seo')} iconKey="seo">
                  <Row
                    id={'seo_title-' + locale}
                    label={t('pages.seo_title')}
                    help={t('pages.seo_title_help')}
                    error={err('seo_title')}
                  >
                    {input('seo_title', 200)}
                  </Row>
                  <Row
                    id={'meta_description-' + locale}
                    label={t('pages.meta_description')}
                    help={t('pages.meta_description_help')}
                    error={err('meta_description')}
                  >
                    {input('meta_description', 500, true)}
                  </Row>
                  <Row
                    id={'meta_keywords-' + locale}
                    label={t('pages.meta_keywords')}
                    help={t('pages.meta_keywords_help')}
                    error={err('meta_keywords')}
                  >
                    {input('meta_keywords', 500, true)}
                  </Row>
                </Section>

                <Section
                  title={t('pages.section_availability')}
                  iconKey="clock"
                >
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
                    <p className="text-xs text-slate-500">
                      {t('pages.timezone_note', { zone: timeZone })}
                    </p>
                  </Row>
                </Section>
              </>
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
                        prefix={post ? '/blog/…/' : '/blog/'}
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
                {post && (
                  <Section title={t('blogs.tags')} iconKey="general">
                    <Row
                      id={'tags-' + locale}
                      label={
                        t('blogs.tags') +
                        (locales.length > 1 ? ' · ' + locale : '')
                      }
                      help={t('blogs.tags_help')}
                      error={err('tags')}
                    >
                      {input('tags', 2000)}
                    </Row>
                  </Section>
                )}
              </>
            )}
          </div>
          {showAi && (
            // Hidden rather than unmounted, so the conversation survives switching tabs.
            <div role="tabpanel" hidden={tab !== 'ai'}>
              <AiChat<PostDraft>
                endpoint="/admin/blog-posts/assistant"
                payload={{ locale, post_id: item?.id ?? null }}
                ready={aiReady}
                current={() => ({
                  name: text.name,
                  content: text.content,
                  excerpt: text.excerpt ?? '',
                  seo_title: text.seo_title,
                  meta_description: text.meta_description,
                  meta_keywords: text.meta_keywords,
                  tags: text.tags ?? '',
                })}
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
                preview={
                  <Section title={t('common.preview')} iconKey="preview">
                    <h3 className="text-lg font-semibold">{text.name}</h3>
                    {text.excerpt && (
                      <p className="text-sm text-slate-600 italic">
                        {text.excerpt}
                      </p>
                    )}
                    <div className="max-h-[32rem] overflow-y-auto text-sm whitespace-pre-line text-slate-700">
                      {htmlToText(text.content)}
                    </div>
                  </Section>
                }
                label={t('blogs.ai_tab')}
                intro={t('blogs.ai_intro')}
                placeholder={t('blogs.ai_placeholder')}
                consent={t('blogs.ai_consent')}
                notConfigured={t('blogs.ai_not_configured')}
                examples={[
                  t('blogs.ai_example_1'),
                  t('blogs.ai_example_2'),
                  t('blogs.ai_example_3'),
                ]}
              />
            </div>
          )}

          {allowed && (
            <div className="flex justify-end">
              <EditorActionButton disabled={form.processing}>
                {t('pages.save')}
              </EditorActionButton>
            </div>
          )}
        </div>
      </form>
    </AdminLayout>
  );
}
