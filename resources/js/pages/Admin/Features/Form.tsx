import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';

import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import RichText from '../../../components/admin/RichText';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FeatureImpactDialog from '../../../components/admin/FeatureImpactDialog';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import {
  emptyFeatureText,
  featurePurposes,
  featureTypes,
  landingFields,
  variationStyles,
  type VariationStyle,
  type Feature,
  type FeatureGroup,
  type FeatureOption,
  type FeatureOptionText,
  type ImpactPreview,
} from '../../../types/features';
import Select from '../../../components/admin/Select';
import FeatureOptionsTable from '../../../components/admin/FeatureOptionsTable';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
type Confirmation = { confirmed: boolean; impact_token: string | null };
/** The impact dialog already presents the confirmation request. */
const withoutConfirmation = (errors: Record<string, string>) =>
  Object.fromEntries(
    Object.entries(errors).filter(([key]) => key !== 'confirmation'),
  );
const tabs = ['general', 'settings', 'categories', 'options'] as const;
type Tab = (typeof tabs)[number];
type Props = AdminShell & {
  feature: Feature | null;
  groups: FeatureGroup[];
  categories: { id: string; name: string }[];
  defaultLocale: string;
  impactPreview: ImpactPreview;
  brandLandingPages: boolean;
};
export default function Form(props: Props) {
  return (
    <Editor
      key={(props.feature?.id ?? 'new') + ':' + props.feature?.version}
      {...props}
    />
  );
}
function Editor({
  feature,
  groups,
  categories,
  defaultLocale,
  impactPreview,
  brandLandingPages,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const [confirmedToken, setConfirmedToken] = useState('');
  const confirmed = !!impactPreview && confirmedToken === impactPreview.token;
  const [dismissedToken, setDismissedToken] = useState('');
  const [deleting, setDeleting] = useState(false);
  // A deletion already confirmed in its own dialog must not ask the same question again.
  const [preconfirmed, setPreconfirmed] = useState(false);
  // Repeats the attempt that needed confirmation, so one click in the dialog completes it.
  const retry = useRef<((c: Confirmation) => void) | null>(null);
  const [search, setSearch] = useState('');
  const form = useForm({
    version: feature?.version,
    type: feature?.type ?? 'text',
    purpose: feature?.purpose ?? 'standard',
    variation_style: feature?.variation_style ?? 'text',
    group_id: feature?.group_id ?? groups.find((g) => g.protected)?.id ?? '',
    unit: feature?.unit ?? '',
    required: feature?.required ?? false,
    filterable: feature?.filterable ?? false,
    comparison: feature?.comparison ?? false,
    categories: feature?.categories ?? [],
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        { ...emptyFeatureText, ...feature?.translations[l.code] },
      ]),
    ),
  });
  useDirtyForm(form);
  const visibleTabs = tabs.filter(
    (item) =>
      item !== 'options' ||
      (feature && ['single', 'multiple'].includes(feature.type)),
  );
  const [tab, setTab] = useUrlTab(visibleTabs);
  const tabError = (candidate: Tab) =>
    Object.keys(form.errors).some((key) => {
      const owner: Tab = key.startsWith('categories')
        ? 'categories'
        : /^(unit|purpose|required|filterable|comparison)/.test(key)
          ? 'settings'
          : 'general';
      return candidate === owner;
    });
  const lifecycle = useForm({});
  useDirtyForm(lifecycle);
  const readonly = feature?.status === 'archived';
  const text = form.data.translations[locale];
  const title = feature?.name || feature?.code || t('features.create');
  const confirmation = {
    confirmed,
    impact_token: impactPreview?.token ?? null,
  };
  const handled = useRef('');
  useEffect(() => {
    if (
      impactPreview &&
      preconfirmed &&
      handled.current !== impactPreview.token
    ) {
      handled.current = impactPreview.token;
      setConfirmedToken(impactPreview.token);
      retry.current?.({ confirmed: true, impact_token: impactPreview.token });
    }
  }, [impactPreview, preconfirmed]);
  const runAction = (action: string) => {
    if (!feature) return;
    const send = (c: Confirmation) => {
      lifecycle.transform(() => ({ version: feature.version, action, ...c }));
      lifecycle.post('/admin/features/' + feature.id + '/action', {
        preserveScroll: true,
      });
    };
    retry.current = send;
    setDismissedToken('');
    setPreconfirmed(false);
    send(confirmation);
  };
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          feature && (
            <EditorStatusSelect
              aria-label={t('common.status')}
              value={feature.status}
              disabled={
                !capabilities['features.status'] || lifecycle.processing
              }
              options={['active', 'archived'].map((value) => ({
                value,
                label: t('features.' + value),
              }))}
              onChange={(value) => {
                if (value !== feature.status)
                  runAction(
                    value === 'archived'
                      ? 'archive'
                      : readonly
                        ? 'restore'
                        : 'activate',
                  );
              }}
            />
          )
        }
        heading={title}
        iconKey="features"
        backHref="/admin/features"
        backLabel={t('features.title')}
        actions={
          <>
            <EditorActionButton
              type="submit"
              form="feature-definition-form"

              disabled={readonly || form.processing}
            >
              {t('common.save')}
            </EditorActionButton>
          </>
        }
        metadata={
          feature && (
            <div className="mb-4 flex flex-wrap items-center gap-3">
              <span>{feature.code}</span>
              {feature.incomplete && <span>{t('features.incomplete')}</span>}
              {capabilities['products.view'] && (
                <Link
                  className="text-link"
                  href={'/admin/features/remediation?feature_id=' + feature.id}
                >
                  {t('features.review')} →
                </Link>
              )}
            </div>
          )
        }
        menu={[
          feature && capabilities['features.delete'] && (
            <button
              type="button"
              aria-label={t('features.delete')}
              title={t('features.delete')}
              className="button-secondary px-3 text-red-700 hover:bg-red-50"
              disabled={lifecycle.processing}
              onClick={() => setDeleting(true)}
            >
              <Icon name="trash" />
              {t('features.delete')}
            </button>
          ),
        ]}
      />

      {readonly && (
        <p className="my-3 text-amber-700">{t('features.readonly')}</p>
      )}
      <FormErrors errors={withoutConfirmation(lifecycle.errors)} />
      {deleting && feature && (
        <ConfirmDeleteDialog
          title={t('features.delete_feature_title', { name: feature.name })}
          confirmWord={feature.code}
          facts={[
            <span key="o">
              {t('features.delete_options_lost', {
                count: feature.options.length,
              })}
            </span>,
            ...((feature.products ?? 0) > 0
              ? [
                  <span key="p">
                    {t('features.delete_products_lost', {
                      count: feature.products ?? 0,
                      name: feature.name,
                    })}
                  </span>,
                ]
              : []),
          ]}
          onArchive={
            capabilities['features.status'] && !readonly
              ? () => {
                  setDeleting(false);
                  runAction('archive');
                }
              : undefined
          }
          onCancel={() => setDeleting(false)}
          onConfirm={() => {
            setDeleting(false);
            runAction('delete');
            setPreconfirmed(true);
          }}
        />
      )}
      <FeatureImpactDialog
        preview={
          impactPreview &&
          impactPreview.token !== dismissedToken &&
          !preconfirmed
            ? impactPreview
            : null
        }
        onCancel={() => setDismissedToken(impactPreview?.token ?? '')}
        onConfirm={() => {
          if (!impactPreview) return;
          setConfirmedToken(impactPreview.token);
          setDismissedToken(impactPreview.token);
          retry.current?.({
            confirmed: true,
            impact_token: impactPreview.token,
          });
        }}
      />

      <AdminTabs
        label={title}
        language={
          <ContentLanguageSelector
            languages={i18n.locales}
            value={locale}
            onChange={setLocale}
          />
        }
      >
        {visibleTabs.map((item) => (
          <AdminTab
            key={item}
            id={'feature-tab-' + item}
            role="tab"
            type="button"
            aria-selected={tab === item}
            aria-controls={'feature-panel-' + item}
            tabIndex={tab === item ? 0 : -1}
            onClick={() => setTab(item)}
            iconKey={item}
          >
            {t(`features.tab_${item}`)}
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

      <FormErrors errors={withoutConfirmation(form.errors)} />
      <form
        hidden={tab === 'options'}
        id="feature-definition-form"
        onSubmit={(e) => {
          e.preventDefault();
          const send = (c: Confirmation) => {
            form.transform((d) => ({ ...d, ...c }));
            form.post(
              feature ? '/admin/features/' + feature.id : '/admin/features',
              { preserveScroll: true },
            );
          };
          retry.current = send;
          setDismissedToken('');
          setPreconfirmed(false);
          send(confirmation);
        }}
        className="space-y-6"
      >
        <fieldset disabled={readonly || form.processing} className="space-y-6">
          <section
            hidden={tab !== 'general'}
            role="tabpanel"
            id="feature-panel-general"
            aria-labelledby="feature-tab-general"
            className="space-y-6"
          >
            <AdminCard title={t('features.tab_general')} iconKey="general">
              <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label>
                    {t('features.type')}
                    <Select
                      className="mt-1"
                      disabled={!!feature}
                      value={form.data.type}
                      onChange={(value) =>
                        form.setData({
                          ...form.data,
                          type: value,
                          filterable:
                            value === 'text' ? false : form.data.filterable,
                          unit: value === 'number' ? form.data.unit : '',
                          purpose:
                            value === 'single' ? form.data.purpose : 'standard',
                        })
                      }
                      options={featureTypes.map((type) => ({
                        value: type,
                        label: t(`features.type_${type}`),
                      }))}
                    />
                    <span className="text-sm text-slate-500">
                      {t('features.immutable')}
                    </span>
                  </label>
                  <label>
                    {t('features.group_id')}
                    <Select
                      className="mt-1"
                      value={form.data.group_id}
                      onChange={(value) => form.setData('group_id', value)}
                      options={groups.map((g) => ({
                        value: g.id,
                        label: g.name,
                      }))}
                    />
                  </label>
                </div>

                <div>
                  <label className="block" htmlFor={'feature-name-' + locale}>
                    {t('features.name')}
                  </label>
                  <input
                    id={'feature-name-' + locale}
                    className="field mt-1"
                    value={text.name}
                    onChange={(e) =>
                      form.setData('translations', {
                        ...form.data.translations,
                        [locale]: { ...text, name: e.target.value },
                      })
                    }
                  />
                </div>
                <div>
                  <label
                    className="mb-2 block"
                    htmlFor={'feature-description-' + locale}
                  >
                    {t('features.description')}
                  </label>
                  <RichText
                    key={locale}
                    id={'feature-description-' + locale}
                    label={t('features.description')}
                    value={text.description}
                    maxLength={10000}
                    disabled={readonly || form.processing}
                    onChange={(html) =>
                      form.setData('translations', {
                        ...form.data.translations,
                        [locale]: { ...text, description: html },
                      })
                    }
                  />
                </div>
              </div>
            </AdminCard>
          </section>
          <section
            hidden={tab !== 'settings'}
            role="tabpanel"
            id="feature-panel-settings"
            aria-labelledby="feature-tab-settings"
            className="space-y-6"
          >
            <AdminCard title={t('features.tab_settings')} iconKey="settings">
              <div className="space-y-5">
                {form.data.type === 'number' && (
                  <label className="block">
                    {t('features.unit')}
                    <input
                      className="field mt-1"
                      value={form.data.unit}
                      onChange={(e) => form.setData('unit', e.target.value)}
                    />
                  </label>
                )}
                <fieldset className="space-y-3">
                  <legend className="font-semibold">
                    {t('features.purpose')}
                  </legend>
                  {brandLandingPages && (
                    <p className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                      {t('features.brand_landing_pages_enabled')}
                      <Link
                        className="underline"
                        href="/admin/settings/general?tab=catalog"
                      >
                        {t('settings.title')}
                      </Link>
                    </p>
                  )}
                  {featurePurposes.map((purpose) => {
                    const unavailable =
                      (purpose !== 'standard' && form.data.type !== 'single') ||
                      (purpose === 'landing_pages' && brandLandingPages);
                    return (
                      <div
                        className={
                          'flex items-start gap-2' +
                          (unavailable
                            ? ' cursor-not-allowed opacity-50 [&_*]:cursor-not-allowed'
                            : '')
                        }
                        key={purpose}
                      >
                        <input
                          id={'feature-purpose-' + purpose}
                          type="radio"
                          name="purpose"
                          className="mt-1"
                          value={purpose}
                          checked={form.data.purpose === purpose}
                          disabled={unavailable}
                          aria-describedby={'feature-purpose-help-' + purpose}
                          onChange={() => form.setData('purpose', purpose)}
                        />
                        <div>
                          <label
                            htmlFor={'feature-purpose-' + purpose}
                            className="font-medium"
                          >
                            {t(`features.purpose_${purpose}`)}
                          </label>
                          <p
                            id={'feature-purpose-help-' + purpose}
                            className="text-sm text-slate-500"
                          >
                            {t(`features.purpose_${purpose}_help`)}
                          </p>
                        </div>
                      </div>
                    );
                  })}
                  {form.data.type !== 'single' && (
                    <p className="text-sm text-slate-500">
                      {t('features.purpose_unavailable')}
                    </p>
                  )}
                  {feature?.purpose === 'landing_pages' &&
                    form.data.purpose !== 'landing_pages' && (
                      <p role="status" className="text-sm text-amber-700">
                        {t('features.purpose_downgrade')}
                      </p>
                    )}
                  {feature?.purpose === 'variations' &&
                    form.data.purpose !== 'variations' && (
                      <p role="status" className="text-sm text-amber-700">
                        {t(
                          (feature.variation_groups ?? 0) > 0
                            ? 'features.purpose_variations_in_use'
                            : 'features.purpose_variations_downgrade',
                          { count: feature.variation_groups ?? 0 },
                        )}
                      </p>
                    )}
                </fieldset>
                {form.data.purpose === 'variations' && (
                  <fieldset className="space-y-3">
                    <legend className="font-semibold">
                      {t('features.variation_style')}
                    </legend>
                    <p className="text-sm text-slate-500">
                      {t('features.variation_style_help')}
                    </p>
                    <div className="max-w-xs">
                      <Select
                        aria-label={t('features.variation_style')}
                        value={form.data.variation_style}
                        onChange={(value) =>
                          form.setData(
                            'variation_style',
                            value as VariationStyle,
                          )
                        }
                        options={variationStyles.map((style) => ({
                          value: style,
                          label: t(`features.style_${style}`),
                        }))}
                      />
                    </div>
                    {(feature?.variation_groups ?? 0) > 0 && (
                      <p className="text-sm text-slate-500">
                        {t('features.variation_groups_using', {
                          count: feature?.variation_groups ?? 0,
                        })}
                      </p>
                    )}
                    {feature?.purpose === 'variations' &&
                      (['color', 'image'] as const).includes(
                        form.data.variation_style as 'color' | 'image',
                      ) &&
                      (() => {
                        const missing = feature.options.filter(
                          (o) =>
                            o.status === 'active' &&
                            (form.data.variation_style === 'color'
                              ? !o.swatch_color
                              : !o.swatch_image_url),
                        );
                        return missing.length > 0 ? (
                          <div
                            role="status"
                            className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
                          >
                            <p>
                              {t(
                                form.data.variation_style === 'color'
                                  ? 'features.swatch_missing_color'
                                  : 'features.swatch_missing_image',
                              )}
                            </p>
                            <p className="mt-1 font-medium">
                              {missing
                                .map((o) => o.label || '#' + o.id)
                                .join(', ')}
                            </p>
                          </div>
                        ) : null;
                      })()}
                  </fieldset>
                )}
                <div className="space-y-3">
                  {(['required', 'filterable', 'comparison'] as const).map(
                    (key) => (
                      <label className="flex gap-2" key={key}>
                        <input
                          type="checkbox"
                          checked={form.data[key]}
                          disabled={
                            key === 'filterable' && form.data.type === 'text'
                          }
                          onChange={(e) => form.setData(key, e.target.checked)}
                        />
                        {t('features.' + key)}
                      </label>
                    ),
                  )}
                </div>
              </div>
            </AdminCard>
          </section>
          <section
            hidden={tab !== 'categories'}
            role="tabpanel"
            id="feature-panel-categories"
            aria-labelledby="feature-tab-categories"
          >
            <AdminCard
              title={t('features.tab_categories')}
              iconKey="categories"
            >
              <div className="space-y-5">
                <p className="my-2 text-sm">
                  {t('features.applicability_help')}
                </p>
                <div className="mb-3 flex gap-2">
                  <input
                    className="field"
                    aria-label={t('common.search')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() =>
                      router.get(
                        window.location.pathname,
                        { category_search: search },
                        {
                          only: ['categories'],
                          preserveState: true,
                          preserveScroll: true,
                        },
                      )
                    }
                  >
                    {t('common.search')}
                  </button>
                </div>
                <div className="max-h-64 space-y-2 overflow-auto">
                  {categories.map((c) => (
                    <label key={c.id} className="flex gap-2">
                      <input
                        type="checkbox"
                        checked={form.data.categories.includes(c.id)}
                        onChange={(e) =>
                          form.setData(
                            'categories',
                            e.target.checked
                              ? [...form.data.categories, c.id]
                              : form.data.categories.filter(
                                  (id) => id !== c.id,
                                ),
                          )
                        }
                      />
                      {c.name}
                    </label>
                  ))}
                </div>
              </div>
            </AdminCard>
          </section>
        </fieldset>
      </form>
      {feature && (
        <>
          {['single', 'multiple'].includes(feature.type) && (
            <section
              hidden={tab !== 'options'}
              role="tabpanel"
              id="feature-panel-options"
              aria-labelledby="feature-tab-options"
              className="space-y-4"
            >
              <AdminCard title={t('features.tab_options')} iconKey="options">
                <div className="space-y-5">
                  {brandLandingPages && feature.purpose === 'landing_pages' && (
                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                      {t('features.brand_landing_pages_enabled')}
                    </p>
                  )}
                  <FeatureOptionsTable
                    feature={feature}
                    confirmation={confirmation}
                    onPreconfirm={() => setPreconfirmed(true)}
                    onAttempt={(attempt) => {
                      retry.current = attempt;
                      setDismissedToken('');
                      setPreconfirmed(false);
                    }}
                    details={
                      feature.purpose === 'standard'
                        ? undefined
                        : (o) => (
                            <OptionEditor
                              key={o.id + ':' + o.version}
                              option={o}
                              feature={feature}
                              confirmation={confirmation}
                              defaultLocale={defaultLocale}
                              brandLandingPages={brandLandingPages}
                            />
                          )
                    }
                  />
                </div>
              </AdminCard>
            </section>
          )}
        </>
      )}
    </AdminLayout>
  );
}
function OptionEditor({
  option,
  feature,
  confirmation,
  defaultLocale,
  brandLandingPages,
}: {
  option: FeatureOption | null;
  feature: Feature;
  confirmation: { confirmed: boolean; impact_token: string | null };
  defaultLocale: string;
  brandLandingPages: boolean;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  // Landing properties follow the saved parent purpose; Standard Attribute options submit labels only.
  const landing = feature.purpose === 'landing_pages' && !brandLandingPages;
  // Swatch data is shared across languages and only edited under the variation purpose.
  const variations = feature.purpose === 'variations';
  const style = feature.variation_style ?? 'text';
  const form = useForm({
    version: option?.version,
    feature_version: feature.version,
    action: 'save',
    translations: Object.fromEntries(
      i18n.locales.map((l) => {
        const saved = option?.translations[l.code];
        const label = saved?.label ?? '';
        return [
          l.code,
          landing
            ? {
                label,
                ...Object.fromEntries(
                  landingFields.map((key) => [key, saved?.[key] ?? '']),
                ),
                suggest_slug: false,
              }
            : { label },
        ];
      }),
    ) as Record<string, FeatureOptionText>,
    image: null as File | null,
    remove_image: false,
    swatch_color: option?.swatch_color ?? '',
    swatch_image: null as File | null,
    remove_swatch_image: false,
  });
  useDirtyForm(form);
  const readonly =
    option?.status === 'archived' || feature.status === 'archived';
  const url =
    '/admin/features/' +
    feature.id +
    '/options' +
    (option ? '/' + option.id : '');
  const text = form.data.translations[locale];
  const setText = (changes: Partial<FeatureOptionText>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...changes },
    });
  const submit = (action: string) => {
    form.transform((d) => {
      // Multipart uploads would send absent values as empty strings, so only defined fields are sent.
      const payload: Record<string, unknown> = {
        feature_version: d.feature_version,
        action,
        translations: d.translations,
        confirmed: confirmation.confirmed,
      };
      if (d.version !== undefined) payload.version = d.version;
      if (confirmation.impact_token)
        payload.impact_token = confirmation.impact_token;
      if (landing && action === 'save') {
        if (d.image) payload.image = d.image;
        if (d.remove_image) payload.remove_image = true;
      }
      if (variations && action === 'save') {
        payload.swatch_color = d.swatch_color;
        if (d.swatch_image) payload.swatch_image = d.swatch_image;
        if (d.remove_swatch_image) payload.remove_swatch_image = true;
      }
      return payload as typeof d;
    });
    form.post(url, { preserveScroll: true });
  };
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        submit('save');
      }}
      className="overflow-hidden rounded-xl border border-slate-200 bg-white"
    >
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <h3 className="text-base font-semibold">
          {option?.label || t('features.add_option')}
        </h3>
        {option && (
          <span
            className={
              'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ' +
              (readonly
                ? 'bg-slate-100 text-slate-600'
                : 'bg-primary-tint text-emerald-700')
            }
          >
            <span
              className="size-1.5 rounded-full bg-current"
              aria-hidden="true"
            />
            {t(
              option.status === 'archived'
                ? 'features.archived'
                : 'features.active',
            )}
          </span>
        )}
      </div>
      <AdminTabs
        language={
          <ContentLanguageSelector
            languages={i18n.locales}
            value={locale}
            onChange={setLocale}
          />
        }
      />
      <fieldset
        disabled={readonly || form.processing}
        className="space-y-6 p-5 sm:p-6"
      >
        <FormErrors errors={form.errors} />
        <div className="max-w-xl">
          <FormField
            id={'option-label-' + (option?.id ?? 'new') + '-' + locale}
            label={t('features.label')}
            value={text.label}
            maxLength={200}
            error={
              (form.errors as Record<string, string>)[
                `translations.${locale}.label`
              ]
            }
            onChange={(e) => setText({ label: e.target.value })}
          />
        </div>
        {variations && style === 'color' && (
          <div className="space-y-1">
            <label
              className="block"
              htmlFor={'swatch-color-' + (option?.id ?? 'new')}
            >
              {t('features.swatch_color')}
            </label>
            <div className="flex flex-wrap items-center gap-2">
              <input
                type="color"
                aria-label={t('features.swatch_picker')}
                className="h-9 w-12 cursor-pointer rounded-md border border-slate-200 bg-white p-1"
                disabled={readonly}
                value={
                  /^#[0-9a-fA-F]{6}$/.test(form.data.swatch_color)
                    ? form.data.swatch_color
                    : '#000000'
                }
                onChange={(e) =>
                  form.setData('swatch_color', e.target.value.toUpperCase())
                }
              />
              <input
                id={'swatch-color-' + (option?.id ?? 'new')}
                className="field w-32 font-mono uppercase"
                disabled={readonly}
                placeholder="#RRGGBB"
                maxLength={7}
                aria-describedby={'swatch-color-hint-' + (option?.id ?? 'new')}
                value={form.data.swatch_color}
                onChange={(e) => form.setData('swatch_color', e.target.value)}
              />
              {form.data.swatch_color !== '' && (
                <button
                  type="button"
                  className="text-link text-sm"
                  disabled={readonly}
                  onClick={() => form.setData('swatch_color', '')}
                >
                  {t('features.swatch_clear')}
                </button>
              )}
            </div>
            <p
              id={'swatch-color-hint-' + (option?.id ?? 'new')}
              className="text-xs text-slate-500"
            >
              {t('features.swatch_color_hint')}
            </p>
          </div>
        )}
        {variations && style === 'image' && (
          <SingleImageUploader
            title={t('features.swatch_image')}
            help={t('features.image_hint')}
            current={
              option?.swatch_image_url
                ? { url: option.swatch_image_url, meta: option.swatch_meta }
                : null
            }
            alt={t('features.swatch_current')}
            file={form.data.swatch_image}
            onFile={(file) => form.setData('swatch_image', file)}
            removed={form.data.remove_swatch_image}
            onRemoved={(value) => form.setData('remove_swatch_image', value)}
            progress={
              form.processing && form.data.swatch_image
                ? (form.progress?.percentage ?? 0)
                : null
            }
            disabled={readonly || form.processing}
          />
        )}
        {landing && (
          <fieldset
            disabled={readonly}
            className="space-y-5 border-t border-slate-200 pt-5"
          >
            <legend className="sr-only">{t('features.landing_page')}</legend>
            <div className="space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h4 className="text-base font-semibold">
                  {t('features.landing_page')}
                </h4>
                {option && (
                  <span
                    className={
                      'inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-medium ' +
                      (option.landing_eligible
                        ? 'bg-primary-tint text-emerald-700'
                        : 'bg-amber-50 text-amber-800')
                    }
                  >
                    <Icon name={option.landing_eligible ? 'check' : 'clock'} />
                    {t(
                      option.landing_eligible
                        ? 'features.landing_eligible'
                        : 'features.landing_ineligible',
                    )}
                  </span>
                )}
              </div>
              <p className="max-w-3xl text-sm leading-6 text-slate-500">
                {t('features.landing_help')}
              </p>
            </div>
            <div>
              <label
                className="mb-2 block text-sm font-medium"
                htmlFor={
                  'option-description-' + (option?.id ?? 'new') + '-' + locale
                }
              >
                {t('features.landing_description')}
              </label>
              <RichText
                key={locale}
                id={
                  'option-description-' + (option?.id ?? 'new') + '-' + locale
                }
                label={t('features.landing_description')}
                value={text.description ?? ''}
                maxLength={10000}
                disabled={readonly || form.processing}
                onChange={(html) => setText({ description: html })}
              />
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              {(
                [
                  'seo_title',
                  'seo_description',
                  'meta_keywords',
                  'slug',
                ] as const
              ).map((key) => (
                <FormField
                  key={key}
                  id={
                    'option-' + key + '-' + (option?.id ?? 'new') + '-' + locale
                  }
                  label={t('features.' + key)}
                  urlPrefix={key === 'slug' ? '/' : undefined}
                  value={text[key] ?? ''}
                  maxLength={
                    key === 'seo_description'
                      ? 500
                      : key === 'meta_keywords'
                        ? 1000
                        : 200
                  }
                  error={
                    (form.errors as Record<string, string>)[
                      `translations.${locale}.${key}`
                    ]
                  }
                  onChange={(e) => setText({ [key]: e.target.value })}
                />
              ))}
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={text.suggest_slug ?? false}
                onChange={(e) => setText({ suggest_slug: e.target.checked })}
              />
              {t('features.suggest_slug')}
            </label>
            <SingleImageUploader
              title={t('features.image')}
              help={t('features.image_hint')}
              current={
                option?.image_url
                  ? { url: option.image_url, meta: option.image_meta }
                  : null
              }
              alt={t('features.current_image')}
              file={form.data.image}
              onFile={(file) => form.setData('image', file)}
              removed={form.data.remove_image}
              onRemoved={(value) => form.setData('remove_image', value)}
              progress={
                form.processing && form.data.image
                  ? (form.progress?.percentage ?? 0)
                  : null
              }
              disabled={readonly || form.processing}
            />
          </fieldset>
        )}
      </fieldset>
      {feature.status !== 'archived' && (
        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
          {!readonly && (
            <EditorActionButton disabled={form.processing}>
              {t('common.save')}
            </EditorActionButton>
          )}
          {option &&
            (readonly ? ['restore', 'delete'] : ['archive', 'delete']).map(
              (action) => (
                <button
                  key={action}
                  type="button"
                  className="button-secondary"
                  disabled={form.processing}
                  onClick={() => submit(action)}
                >
                  {t('features.' + action)}
                </button>
              ),
            )}
        </div>
      )}
    </form>
  );
}
