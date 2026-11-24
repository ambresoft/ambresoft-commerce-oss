import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import FaqPicker from '../../../components/admin/FaqPicker';
import BlogPostPicker, {
  type BlogPostOption,
} from '../../../components/admin/BlogPostPicker';
import type { FaqDraft, FaqOption } from '../../../types/faqs';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import { useForm, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormField from '../../../components/auth/FormField';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import RichText from '../../../components/admin/RichText';
import FormErrors from '../../../components/admin/FormErrors';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import { Icon } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type {
  CategoryEditor,
  CategoryTranslation,
  ParentAgeRestriction,
} from '../../../types/categories';
import type { SharedProps } from '../../../types/page';
import Select from '../../../components/admin/Select';
import CustomerGroupPicker, {
  type CustomerGroupOption,
} from '../../../components/admin/CustomerGroupPicker';

const tabs = [
  'general',
  'content',
  'seo',
  'age',
  'image',
  'blog',
  'faqs',
  'additional',
] as const;
type Tab = (typeof tabs)[number];
const tabLabels: Record<Tab, string> = {
  faqs: 'faqs.title',
  blog: 'blogs.category_posts',
  general: 'categories.tab_general',
  content: 'categories.tab_content',
  seo: 'categories.tab_seo',
  age: 'categories.tab_age',
  image: 'categories.tab_image',
  additional: 'categories.tab_additional',
};
const emptyTranslation = (): CategoryTranslation => ({
  name: '',
  description: '',
  seo_title: '',
  seo_description: '',
  slug: '',
  image_alt: '',
  meta_keywords: '',
  age_warning_message: '',
});
type Props = AdminShell & {
  faqOptions?: FaqOption[];
  blogPostOptions?: BlogPostOption[];
  category: CategoryEditor | null;
  initialParentId: string | null;
  defaultLocale: string;
  parentOptions: { id: string; name: string }[];
  parentAgeRestriction: ParentAgeRestriction;
  customerGroups?: CustomerGroupOption[];
};

export default function CategoryForm({
  category,
  initialParentId,
  defaultLocale,
  parentOptions,
  parentAgeRestriction,
  customerGroups = [],
  faqOptions = [],
  blogPostOptions = [],
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [deleting, setDeleting] = useState(false);
  const [locale, setLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : defaultLocale,
  );
  const [edited, setEdited] = useState<Set<string>>(
    new Set(category ? [] : [defaultLocale]),
  );
  const [tab, setTab] = useUrlTab(
    tabs.filter(
      (item) =>
        (item !== 'faqs' || capabilities['faqs.view']) &&
        (item !== 'blog' || capabilities['blog.view']),
    ),
  );
  const parentTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
  // A pending parent search must not reload a page the editor has already left.
  useEffect(() => () => clearTimeout(parentTimer.current), []);
  const [parentAgeError, setParentAgeError] = useState(false);
  const form = useForm({
    faqs_submitted: !!capabilities['faqs.view'],
    faq_ids: capabilities['faqs.view'] ? (category?.faqIds ?? []) : undefined,
    faq_drafts: capabilities['faqs.view'] ? ([] as FaqDraft[]) : undefined,
    // Multipart encoding drops an empty list, which must still clear the posts.
    blog_posts_submitted: !!capabilities['blog.view'],
    blog_post_ids: capabilities['blog.view']
      ? (category?.blogPostIds ?? [])
      : undefined,
    parent_id: category?.parentId ?? initialParentId ?? '',
    position: category?.position ?? 1,
    status: category?.status ?? 'active',
    version: category?.version ?? null,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        category?.translations[l.code] ?? emptyTranslation(),
      ]),
    ) as Record<string, CategoryTranslation>,
    image: null as File | null,
    remove_image: false,
    menu_icon: null as File | null,
    remove_menu_icon: false,
    block_image: null as File | null,
    remove_block_image: false,
    banner_image: null as File | null,
    remove_banner_image: false,
    banner_link: category?.bannerLink ?? '',
    exclude_from_discovery: category?.excludeFromDiscovery ?? false,
    exclude_from_sitemap: category?.excludeFromSitemap ?? false,
    is_landing_page: category?.isLandingPage ?? false,
    // Always submitted: multipart encoding drops an empty list, which must still clear the restriction.
    customer_groups_submitted: true,
    customer_groups: category?.customerGroups ?? [],
    age_verification: category?.ageVerification ?? false,
    age_limit: category?.ageLimit?.toString() ?? '',
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const title = t(category ? 'categories.edit' : 'categories.create');
  const pageTitle = category ? `${title}: ${category.displayName}` : title;
  const tabError = (candidate: Tab) =>
    Object.keys(errors).some((key) => {
      if (key.startsWith('faq_')) return candidate === 'faqs';
      if (key.startsWith('blog_post')) return candidate === 'blog';
      const field = key.replace(/^translations\.[^.]+\./, '');
      const owner: Record<string, Tab> = {
        exclude_from_sitemap: 'additional',
        name: 'content',
        description: 'content',
        seo_title: 'seo',
        seo_description: 'seo',
        slug: 'seo',
        meta_keywords: 'seo',
        age_verification: 'age',
        age_limit: 'age',
        age_warning_message: 'age',
        image: 'image',
        remove_image: 'image',
        menu_icon: 'image',
        remove_menu_icon: 'image',
        block_image: 'image',
        remove_block_image: 'image',
        banner_image: 'image',
        remove_banner_image: 'image',
        banner_link: 'image',
        image_alt: 'image',
      };
      return (owner[field] ?? 'general') === candidate;
    });
  const values = form.data.translations[locale] ?? emptyTranslation();
  const updateTranslation = (
    field: keyof CategoryTranslation,
    value: string,
  ) => {
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...values, [field]: value },
    });
    setEdited((previous) => new Set(previous).add(locale));
  };

  return (
    <AdminLayout title={pageTitle} {...shell}>
      <EditorHeader
        image={category?.imageUrl}
        heading={pageTitle}
        iconKey="categories"
        backHref={
          capabilities['categories.view'] ? '/admin/categories' : '/admin'
        }
        backLabel={t('common.cancel')}
        status={
          <>
            <EditorStatusSelect
              id="status"
              aria-required
              aria-label={t('categories.status')}
              invalid={!!errors.status}
              aria-describedby={
                errors.status ? 'category-status-error' : undefined
              }
              value={form.data.status}
              onChange={(value) =>
                form.setData('status', value as 'active' | 'disabled')
              }
              options={[
                { value: 'active', label: t('categories.active') },
                { value: 'disabled', label: t('categories.disabled') },
              ]}
            />
            {errors.status && (
              <p
                id="category-status-error"
                role="alert"
                className="text-sm text-red-700"
              >
                {errors.status}
              </p>
            )}
          </>
        }
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-categories-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
        menu={[
          category && capabilities['categories.delete'] && (
            <button
              type="button"
              aria-label={t('categories.delete')}
              title={
                category.childrenCount > 0
                  ? t('categories.errors.children')
                  : t('categories.delete')
              }
              className="button-secondary px-3 text-red-700 hover:bg-red-50 disabled:opacity-50"
              disabled={form.processing || category.childrenCount > 0}
              onClick={() => setDeleting(true)}
            >
              <Icon name="trash" />
              {t('categories.delete')}
            </button>
          ),
        ]}
      />
      {deleting && category && (
        <ConfirmDeleteDialog
          title={t('categories.delete_title', { name: category.displayName })}
          facts={[]}
          onCancel={() => setDeleting(false)}
          onConfirm={() => {
            setDeleting(false);
            router.delete('/admin/categories/' + category.id, {
              data: { version: category.version },
              preserveState: 'errors',
            });
          }}
        />
      )}
      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.transform((data) => ({
            ...data,
            _method: category ? 'put' : 'post',
            parent_id: data.parent_id || null,
            age_limit: data.age_limit === '' ? null : data.age_limit,
            translations: Object.fromEntries(
              Object.entries(data.translations).filter(([code]) =>
                edited.has(code),
              ),
            ),
          }));
          form.post(
            category ? '/admin/categories/' + category.id : '/admin/categories',
            { forceFormData: true },
          );
        }}
        id="admin-categories-form-0"
      >
        <AdminTabs
          label={title}
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={locale}
              onChange={setLocale}
              errors={errors}
            />
          }
        >
          {tabs
            .filter(
              (item) =>
                (item !== 'faqs' || capabilities['faqs.view']) &&
                (item !== 'blog' || capabilities['blog.view']),
            )
            .map((item) => (
              <AdminTab
                key={item}
                id={'category-tab-' + item}
                role="tab"
                type="button"
                aria-selected={tab === item}
                onClick={() => setTab(item)}
                iconKey={item}
              >
                {t(tabLabels[item])}
                {tabError(item) && (
                  <span
                    className="ml-1 text-red-400"
                    aria-label={t('common.errors')}
                  >
                    ●
                  </span>
                )}
              </AdminTab>
            ))}
        </AdminTabs>

        <p className="mt-4 text-xs text-slate-500">
          {t('common.required_fields')}
        </p>
        <FormErrors errors={errors} />
        {tab === 'additional' && (
          <section
            role="tabpanel"
            id="category-panel-additional"
            aria-labelledby="category-tab-additional"
            className="mt-4"
          >
            <AdminCard
              title={t('common.xml_sitemap')}
              iconKey="xml_sitemap"
              bodyClassName="p-5 space-y-4"
            >
              <label className="flex items-center gap-3 text-sm font-medium">
                <input
                  type="checkbox"
                  checked={form.data.exclude_from_sitemap}
                  onChange={(event) =>
                    form.setData('exclude_from_sitemap', event.target.checked)
                  }
                  aria-describedby="category-sitemap-hint"
                  aria-invalid={!!errors.exclude_from_sitemap}
                />
                {t('categories.exclude_from_sitemap')}
              </label>
              <p id="category-sitemap-hint" className="text-sm text-slate-500">
                {t('categories.exclude_from_sitemap_hint')}
              </p>
              {errors.exclude_from_sitemap && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.exclude_from_sitemap}
                </p>
              )}
            </AdminCard>
          </section>
        )}
        {tab === 'blog' && capabilities['blog.view'] && (
          <div
            role="tabpanel"
            id="category-panel-blog"
            aria-labelledby="category-tab-blog"
            className="mt-4"
          >
            <UnifiedCard
              title={t('blogs.category_posts')}
              iconKey="details"
              bodyClassName="p-5 "
            >
              <BlogPostPicker
                ids={form.data.blog_post_ids ?? []}
                options={blogPostOptions}
                error={
                  errors.blog_post_ids ??
                  Object.entries(errors).find(([key]) =>
                    key.startsWith('blog_post_ids.'),
                  )?.[1]
                }
                onIds={(ids) => form.setData('blog_post_ids', ids)}
              />
            </UnifiedCard>
          </div>
        )}
        {tab === 'faqs' && capabilities['faqs.view'] && (
          <div
            role="tabpanel"
            id="category-panel-faqs"
            aria-labelledby="category-tab-faqs"
            className="mt-4"
          >
            <UnifiedCard
              title={t('faqs.title')}
              iconKey="details"
              bodyClassName="p-5 "
            >
              <FaqPicker
                locale={locale}
                ids={form.data.faq_ids ?? []}
                drafts={form.data.faq_drafts ?? []}
                options={faqOptions}
                errors={errors}
                source={{
                  owner: 'category',
                  object_id: category?.id ?? null,
                  source_name: form.data.translations[locale]?.name ?? '',
                  source_text:
                    form.data.translations[locale]?.description ?? '',
                }}
                onIds={(ids) => form.setData('faq_ids', ids)}
                onDrafts={(drafts) => form.setData('faq_drafts', drafts)}
              />
            </UnifiedCard>
          </div>
        )}
        {tab === 'general' && (
          <section role="tabpanel">
            <AdminCard
              id="general-heading"
              title={t('categories.general')}
              iconKey="general"
              bodyClassName="p-5 space-y-5"
            >
              <div>
                <label
                  htmlFor="parent_id"
                  className="mb-2 block text-sm font-medium"
                >
                  {t('categories.parent')}
                </label>
                <Select
                  id="parent_id"
                  value={form.data.parent_id}
                  invalid={!!errors.parent_id}
                  onChange={(selected) => {
                    form.setData('parent_id', selected);
                    setParentAgeError(false);
                    router.reload({
                      data: { selected_parent: selected },
                      only: ['parentAgeRestriction'],
                      preserveUrl: true,
                      onError: () => setParentAgeError(true),
                    });
                  }}
                  options={[
                    { value: '', label: t('categories.root') },
                    ...parentOptions.map((parent) => ({
                      value: parent.id,
                      label: `${parent.name} · #${parent.id}`,
                    })),
                  ]}
                  search={{
                    label: t('categories.parent_search'),
                    emptyMessage: t('categories.parent_none'),
                    // Matches load as you type (debounced partial reload); descendants are never offered.
                    onChange: (query) => {
                      clearTimeout(parentTimer.current);
                      parentTimer.current = setTimeout(
                        () =>
                          router.reload({
                            data: {
                              parent_search: query,
                              selected_parent: form.data.parent_id,
                            },
                            only: ['parentOptions'],
                            preserveUrl: true,
                          }),
                        300,
                      );
                    },
                  }}
                />
                <p className="mt-2 text-xs text-slate-500">
                  {t('categories.parent_search_hint')}
                </p>
                {errors.parent_id && (
                  <p role="alert" className="mt-2 text-sm text-red-700">
                    {errors.parent_id}
                  </p>
                )}
              </div>
              <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                  id="position"
                  label={t('categories.position')}
                  hint={t('categories.position_hint')}
                  type="number"
                  min={1}
                  max={2147483647}
                  step={1}
                  required
                  value={form.data.position}
                  error={errors.position}
                  onChange={(e) =>
                    form.setData('position', Number(e.target.value))
                  }
                />
              </div>
              <div className="space-y-5 border-t border-slate-200 pt-5">
                <h2 className="text-base font-semibold">
                  {t('categories.section_visibility')}
                </h2>
                <div>
                  <label className="flex items-center gap-3 text-sm font-medium">
                    <input
                      type="checkbox"
                      checked={form.data.is_landing_page}
                      onChange={(event) =>
                        form.setData('is_landing_page', event.target.checked)
                      }
                      aria-describedby="is-landing-page-hint"
                      aria-invalid={!!errors.is_landing_page}
                    />
                    {t('categories.is_landing_page')}
                  </label>
                  <p
                    id="is-landing-page-hint"
                    className="mt-2 text-sm text-slate-500"
                  >
                    {t('categories.is_landing_page_hint')}
                  </p>
                  {errors.is_landing_page && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                      {errors.is_landing_page}
                    </p>
                  )}
                </div>
                <div>
                  <label className="flex items-center gap-3 text-sm font-medium">
                    <input
                      type="checkbox"
                      checked={form.data.exclude_from_discovery}
                      onChange={(event) =>
                        form.setData(
                          'exclude_from_discovery',
                          event.target.checked,
                        )
                      }
                      aria-describedby="exclude-from-discovery-hint"
                      aria-invalid={!!errors.exclude_from_discovery}
                    />
                    {t('categories.exclude_from_discovery')}
                  </label>
                  <p
                    id="exclude-from-discovery-hint"
                    className="mt-2 text-sm text-slate-500"
                  >
                    {t('categories.exclude_from_discovery_hint')}
                  </p>
                  {errors.exclude_from_discovery && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                      {errors.exclude_from_discovery}
                    </p>
                  )}
                </div>
                <CustomerGroupPicker
                  id="category-customer-groups"
                  label={t('categories.customer_groups')}
                  help={t('categories.customer_groups_help')}
                  allLabel={t('categories.customer_groups_all')}
                  groups={customerGroups}
                  value={form.data.customer_groups}
                  onChange={(value) => form.setData('customer_groups', value)}
                  error={
                    errors.customer_groups ??
                    Object.entries(errors).find(([key]) =>
                      key.startsWith('customer_groups.'),
                    )?.[1]
                  }
                />
              </div>
            </AdminCard>
          </section>
        )}
        {tab === 'content' && (
          <section role="tabpanel">
            <AdminCard
              id="content-heading"
              title={t('categories.translations')}
              iconKey="translations"
              bodyClassName="p-5 space-y-5"
            >
              <p className="mt-2 text-sm text-slate-500">
                {t(
                  i18n.locales.length >= 2
                    ? 'languages.catalog_required'
                    : 'categories.translation_hint',
                )}
              </p>
              {!category && i18n.locales.length === 1 && (
                <p className="mt-1 text-xs text-slate-500">
                  {t('categories.default_required')}
                </p>
              )}
              <div className="space-y-5">
                <div
                  id="translation-panel"
                  role="tabpanel"
                  aria-label={t('categories.translations')}
                  className="space-y-5"
                >
                  <FormField
                    id={'category-name-' + locale}
                    label={t('categories.name')}
                    requiredIndicator
                    value={values.name}
                    error={errors['translations.' + locale + '.name']}
                    maxLength={200}
                    onChange={(e) => updateTranslation('name', e.target.value)}
                  />
                  <div>
                    <label
                      htmlFor={'description-' + locale}
                      className="mb-2 block text-sm font-medium"
                    >
                      {t('categories.description')}
                    </label>
                    <RichText
                      key={locale}
                      id={'description-' + locale}
                      label={t('categories.description')}
                      value={values.description}
                      maxLength={20000}
                      disabled={form.processing}
                      onChange={(html) =>
                        updateTranslation('description', html)
                      }
                      describedBy={
                        errors['translations.' + locale + '.description']
                          ? 'description-hint description-error'
                          : 'description-hint'
                      }
                      invalid={
                        !!errors['translations.' + locale + '.description']
                      }
                    />
                    <p
                      id="description-hint"
                      className="mt-2 text-xs text-slate-500"
                    >
                      {t('categories.description_hint')}
                    </p>
                    {errors['translations.' + locale + '.description'] && (
                      <p
                        id="description-error"
                        role="alert"
                        className="mt-2 text-sm text-red-700"
                      >
                        {errors['translations.' + locale + '.description']}
                      </p>
                    )}
                  </div>
                </div>
              </div>
            </AdminCard>
          </section>
        )}
        {tab === 'seo' && (
          <section role="tabpanel">
            <AdminCard
              id="seo-heading"
              title={t('categories.tab_seo')}
              iconKey="seo"
              bodyClassName="p-5 space-y-5"
            >
              <div className="space-y-5">
                <div
                  id="translation-panel"
                  role="tabpanel"
                  aria-label={t('categories.translations')}
                  className="space-y-5"
                >
                  <FormField
                    id={'seo-title-' + locale}
                    label={t('categories.seo_title')}
                    requiredIndicator={i18n.locales.length > 1}
                    maxLength={200}
                    value={values.seo_title}
                    error={errors['translations.' + locale + '.seo_title']}
                    onChange={(e) =>
                      updateTranslation('seo_title', e.target.value)
                    }
                  />
                  <FormField
                    id={'seo-description-' + locale}
                    label={t('categories.seo_description')}
                    requiredIndicator={i18n.locales.length > 1}
                    maxLength={500}
                    value={values.seo_description}
                    error={
                      errors['translations.' + locale + '.seo_description']
                    }
                    onChange={(e) =>
                      updateTranslation('seo_description', e.target.value)
                    }
                  />
                  <FormField
                    id={'slug-' + locale}
                    label={t('categories.slug')}
                    urlPrefix={form.data.parent_id ? '/…/' : '/'}
                    requiredIndicator
                    hint={t('categories.slug_hint')}
                    maxLength={200}
                    value={values.slug}
                    error={errors['translations.' + locale + '.slug']}
                    onChange={(e) => updateTranslation('slug', e.target.value)}
                  />
                  <FormField
                    id={'meta-keywords-' + locale}
                    label={t('categories.meta_keywords')}
                    requiredIndicator={i18n.locales.length > 1}
                    hint={t('categories.meta_keywords_hint')}
                    maxLength={1000}
                    value={values.meta_keywords}
                    error={errors['translations.' + locale + '.meta_keywords']}
                    onChange={(e) =>
                      updateTranslation('meta_keywords', e.target.value)
                    }
                  />
                </div>
              </div>
            </AdminCard>
          </section>
        )}
        {tab === 'age' && (
          <section role="tabpanel">
            <AdminCard
              id="age-heading"
              title={t('categories.age_restrictions')}
              iconKey="age_restrictions"
              bodyClassName="p-5 space-y-5"
            >
              <p className="text-xs text-slate-500">
                {t('categories.age_configuration_hint')}
              </p>
              <label className="flex items-center gap-3 text-sm font-medium">
                <input
                  type="checkbox"
                  className="size-4"
                  checked={form.data.age_verification}
                  onChange={(e) =>
                    form.setData('age_verification', e.target.checked)
                  }
                  aria-describedby="age-verification-hint"
                  aria-invalid={!!errors.age_verification}
                />
                {t('categories.age_verification')}
              </label>
              <p id="age-verification-hint" className="text-xs text-slate-500">
                {t('categories.age_verification_hint')}
              </p>
              {errors.age_verification && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.age_verification}
                </p>
              )}
              <FormField
                id="age_limit"
                label={t('categories.age_limit')}
                hint={t('categories.age_limit_hint')}
                type="number"
                min={1}
                max={2147483647}
                step={1}
                required={form.data.age_verification}
                value={form.data.age_limit}
                error={errors.age_limit}
                onChange={(e) => form.setData('age_limit', e.target.value)}
              />
              {parentAgeError ? (
                <p role="alert" className="text-sm text-red-700">
                  {t('categories.age_load_failed')}
                </p>
              ) : parentAgeRestriction.parentId !==
                (form.data.parent_id || null) ? (
                <p role="status" className="text-sm text-slate-500">
                  {t('categories.age_loading')}
                </p>
              ) : (
                <dl className="grid gap-4 rounded-md bg-slate-50 p-4 text-sm sm:grid-cols-2">
                  <div>
                    <dt className="text-slate-500">
                      {t('categories.parent_age_verification')}
                    </dt>
                    <dd className="mt-1 font-medium">
                      {t(
                        parentAgeRestriction.verification
                          ? 'categories.age_enabled'
                          : 'categories.age_disabled',
                      )}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">
                      {t('categories.parent_age_limit')}
                    </dt>
                    <dd className="mt-1 font-medium">
                      {parentAgeRestriction.ageLimit ??
                        t('categories.age_none')}
                    </dd>
                  </div>
                </dl>
              )}
              <p className="text-xs text-slate-500">
                {t('categories.age_inherited_hint')}
              </p>
              <div className="space-y-5">
                <div
                  id="translation-panel"
                  role="tabpanel"
                  aria-label={t('categories.translations')}
                  className="space-y-5"
                >
                  <FormField
                    id={'age-warning-' + locale}
                    label={t('categories.age_warning_message')}
                    hint={t('categories.age_warning_hint')}
                    maxLength={2000}
                    value={values.age_warning_message}
                    error={
                      errors['translations.' + locale + '.age_warning_message']
                    }
                    onChange={(e) =>
                      updateTranslation('age_warning_message', e.target.value)
                    }
                  />
                </div>
              </div>
            </AdminCard>
          </section>
        )}
        {tab === 'image' && (
          <section role="tabpanel">
            <AdminCard
              id="image-heading"
              title={t('categories.image')}
              iconKey="image"
              bodyClassName="p-5 space-y-4"
            >
              <SingleImageUploader
                title={t('categories.image')}
                help={t('categories.image_hint')}
                current={
                  category?.imageUrl
                    ? { url: category.imageUrl, meta: category.imageMeta }
                    : null
                }
                alt={values.image_alt || t('categories.current_image')}
                file={form.data.image}
                onFile={(file) => form.setData('image', file)}
                removed={form.data.remove_image}
                onRemoved={(value) => form.setData('remove_image', value)}
                progress={
                  form.processing && form.data.image
                    ? (form.progress?.percentage ?? 0)
                    : null
                }
                disabled={form.processing}
                error={
                  errors.image ? (
                    <p role="alert" className="text-sm text-red-700">
                      {errors.image}
                    </p>
                  ) : null
                }
              />
              <SingleImageUploader
                title={t('categories.menu_icon')}
                help={t('categories.menu_icon_hint')}
                current={
                  category?.menuIconUrl
                    ? { url: category.menuIconUrl, meta: category.menuIconMeta }
                    : null
                }
                alt={t('categories.current_menu_icon')}
                file={form.data.menu_icon}
                onFile={(file) => form.setData('menu_icon', file)}
                removed={form.data.remove_menu_icon}
                onRemoved={(value) => form.setData('remove_menu_icon', value)}
                progress={
                  form.processing && form.data.menu_icon
                    ? (form.progress?.percentage ?? 0)
                    : null
                }
                disabled={form.processing}
                error={
                  errors.menu_icon ? (
                    <p role="alert" className="text-sm text-red-700">
                      {errors.menu_icon}
                    </p>
                  ) : null
                }
              />
              <SingleImageUploader
                title={t('categories.block_image')}
                help={t('categories.block_image_hint')}
                current={
                  category?.blockImageUrl
                    ? {
                        url: category.blockImageUrl,
                        meta: category.blockImageMeta,
                      }
                    : null
                }
                alt={t('categories.current_block_image')}
                file={form.data.block_image}
                onFile={(file) => form.setData('block_image', file)}
                removed={form.data.remove_block_image}
                onRemoved={(value) => form.setData('remove_block_image', value)}
                progress={
                  form.processing && form.data.block_image
                    ? (form.progress?.percentage ?? 0)
                    : null
                }
                disabled={form.processing}
                error={
                  errors.block_image ? (
                    <p role="alert" className="text-sm text-red-700">
                      {errors.block_image}
                    </p>
                  ) : null
                }
              />
              <SingleImageUploader
                title={t('categories.banner_image')}
                help={t('categories.banner_image_hint')}
                current={
                  category?.bannerImageUrl
                    ? {
                        url: category.bannerImageUrl,
                        meta: category.bannerImageMeta,
                      }
                    : null
                }
                alt={t('categories.current_banner_image')}
                file={form.data.banner_image}
                onFile={(file) => form.setData('banner_image', file)}
                removed={form.data.remove_banner_image}
                onRemoved={(value) =>
                  form.setData('remove_banner_image', value)
                }
                progress={
                  form.processing && form.data.banner_image
                    ? (form.progress?.percentage ?? 0)
                    : null
                }
                disabled={form.processing}
                error={
                  errors.banner_image ? (
                    <p role="alert" className="text-sm text-red-700">
                      {errors.banner_image}
                    </p>
                  ) : null
                }
              />
              <FormField
                id="banner_link"
                label={t('categories.banner_link')}
                hint={t('categories.banner_link_hint')}
                value={form.data.banner_link}
                onChange={(e) => form.setData('banner_link', e.target.value)}
                error={errors.banner_link}
                maxLength={2048}
                placeholder="/gaming/consoles"
              />
              <div className="space-y-5">
                <div
                  id="translation-panel"
                  role="tabpanel"
                  aria-label={t('categories.translations')}
                  className="space-y-5"
                >
                  <FormField
                    id={'image-alt-' + locale}
                    label={t('categories.image_alt')}
                    requiredIndicator={i18n.locales.length > 1}
                    maxLength={200}
                    value={values.image_alt}
                    error={errors['translations.' + locale + '.image_alt']}
                    onChange={(e) =>
                      updateTranslation('image_alt', e.target.value)
                    }
                  />
                </div>
              </div>
            </AdminCard>
          </section>
        )}
      </form>
    </AdminLayout>
  );
}
