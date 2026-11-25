import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import HelpPopover from '../../../components/admin/HelpPopover';
import Select from '../../../components/admin/Select';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import { useTranslation } from '../../../lib/useTranslation';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import {
  conditionTypes,
  emptyCondition,
  type StickerCondition,
  type StickerData,
  type StickerDetail,
  type StickerOption,
} from '../../../types/stickers';
import TargetPicker from '../Promotions/TargetPicker';
import ConditionPicker from './ConditionPicker';

const tabs = ['general', 'appearance', 'display', 'assignment'] as const;
type Tab = (typeof tabs)[number];
const tabFor = (key: string): Tab =>
  /^(conditions|products)/.test(key)
    ? 'assignment'
    : /^(placement|show_)/.test(key)
      ? 'display'
      : /^(translations|image|background|color|border_|radius|padding|font_size|alignment|css_class)/.test(
            key,
          )
        ? 'appearance'
        : 'general';

type Props = AdminShell & {
  sticker: StickerDetail | null;
  options: Record<string, StickerOption[]>;
  defaultLocale: string;
  currency: string;
  timezone: string;
};
export default function StickerForm(props: Props) {
  return (
    <Editor
      key={`${props.sticker?.id ?? 'new'}:${props.sticker?.version ?? 0}`}
      {...props}
    />
  );
}
function Editor({
  sticker,
  options,
  defaultLocale,
  currency,
  timezone,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const manage = !!capabilities['stickers.manage'];
  const [tab, setTab] = useUrlTab(tabs);
  const [language, setLanguage] = useState(defaultLocale);
  const [deleting, setDeleting] = useState(false);
  const [newType, setNewType] = useState('price');
  const form = useForm<StickerData>({
    version: sticker?.version ?? 0,
    name: sticker?.name ?? '',
    type: sticker?.type ?? 'manual',
    style: sticker?.style ?? 'text',
    status: sticker?.status ?? 'active',
    starts_at: sticker?.starts_at ?? '',
    ends_at: sticker?.ends_at ?? '',
    priority: String(sticker?.priority ?? 100),
    match: sticker?.match ?? 'all',
    placement: sticker?.placement ?? 'image_top',
    show_card: sticker?.show_card ?? true,
    show_detail: sticker?.show_detail ?? true,
    show_mobile: sticker?.show_mobile ?? true,
    show_tablet: sticker?.show_tablet ?? true,
    show_desktop: sticker?.show_desktop ?? true,
    is_filter: sticker?.is_filter ?? false,
    background: sticker?.background ?? '#111827',
    color: sticker?.color ?? '#ffffff',
    border_color: sticker?.border_color ?? '#111827',
    border_width: String(sticker?.border_width ?? 0),
    radius: String(sticker?.radius ?? 4),
    padding: String(sticker?.padding ?? 6),
    font_size: String(sticker?.font_size ?? 12),
    alignment: sticker?.alignment ?? 'center',
    image_width: String(sticker?.image_width ?? 64),
    css_class: sticker?.css_class ?? '',
    image_media_id: sticker?.image_media_id ?? '',
    image: null,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        sticker?.translations[l.code] ?? {
          text: '',
          short_text: '',
          tooltip: '',
        },
      ]),
    ),
    conditions: sticker?.conditions ?? [],
    products: sticker?.products ?? [],
  });
  useDirtyForm(form);
  const path = sticker
    ? `/admin/stickers/${sticker.id}/edit`
    : '/admin/stickers/create';
  const errors = form.errors as Record<string, string>;
  const choice = (
    field: 'status' | 'type' | 'style' | 'match' | 'placement' | 'alignment',
    values: string[],
  ) => (
    <div
      className={field === 'status' ? 'flex items-center gap-3' : 'space-y-2'}
    >
      <label htmlFor={`sticker-${field}`} className="block text-sm font-medium">
        {t(`stickers.${field}`)}
      </label>
      <Select
        id={`sticker-${field}`}
        className={field === 'status' ? 'w-auto' : undefined}
        aria-label={t(`stickers.${field}`)}
        value={form.data[field]}
        onChange={(value) =>
          form.setData({
            ...form.data,
            [field]: value,
            ...(field === 'type'
              ? {
                  products: [],
                  conditions:
                    value === 'dynamic' ? [emptyCondition('price')] : [],
                }
              : {}),
          })
        }
        options={values.map((value) => ({
          value,
          label: t(`stickers.${value}`),
        }))}
      />
    </div>
  );
  const condition = (n: number, patch: Partial<StickerCondition>) =>
    form.setData(
      'conditions',
      form.data.conditions.map((c, i) => (i === n ? { ...c, ...patch } : c)),
    );
  const pick = (
    kind: string,
    values: string[],
    onChange: (ids: string[]) => void,
    label: string,
    single = false,
  ) =>
    single && tab === 'assignment' ? (
      <ConditionPicker
        label={label}
        kind={kind}
        values={values}
        options={options[kind] ?? []}
        path={path}
        query={{ tab }}
        onChange={onChange}
      />
    ) : (
      <TargetPicker
        label={label}
        kind={kind}
        values={values}
        options={options[kind] ?? []}
        path={path}
        onChange={onChange}
        single={single}
        query={{ tab }}
      />
    );
  return (
    <AdminLayout title={sticker?.name ?? t('stickers.create')} {...shell}>
      <EditorHeader
        heading={sticker?.name ?? t('stickers.create')}
        iconKey="stickers"
        backHref="/admin/stickers"
        backLabel={t('stickers.back')}
        status={
          <>
            <fieldset disabled={!manage}>
              <EditorStatusSelect
                aria-label={t('stickers.status')}
                value={form.data.status}
                disabled={!manage || form.processing}
                invalid={!!errors.status}
                onChange={(value) =>
                  form.setData('status', value as typeof form.data.status)
                }
                options={['active', 'disabled'].map((value) => ({
                  value,
                  label: t('stickers.' + value),
                }))}
              />
              {errors.status && (
                <p role="alert" className="text-xs text-red-700">
                  {errors.status}
                </p>
              )}
            </fieldset>
          </>
        }
        actions={
          <>
            {manage && (
              <button
                className="button-primary"
                disabled={form.processing}
                form="admin-stickers-form-0"
              >
                {t('stickers.save')}
              </button>
            )}
          </>
        }
        menu={[
          sticker && manage && (
            <button
              type="button"
              className="button-secondary px-3 text-red-700 hover:bg-red-50"
              aria-label={t('stickers.delete')}
              title={t('stickers.delete')}
              onClick={() => setDeleting(true)}
            >
              <Icon name="trash" />
              {t('stickers.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!manage) return;
          form.post(
            sticker ? `/admin/stickers/${sticker.id}` : '/admin/stickers',
            {
              forceFormData: true,
              onError: (errors) =>
                setTab(tabFor(Object.keys(errors)[0] ?? 'name')),
            },
          );
        }}
        id="admin-stickers-form-0"
      >
        <AdminTabs
          label={t('stickers.edit')}
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={language}
              onChange={setLanguage}
              errors={errors}
            />
          }
        >
          {tabs.map((item) => (
            <AdminTab
              key={item}
              type="button"
              id={`sticker-tab-${item}`}
              role="tab"
              aria-selected={tab === item}
              aria-controls={`sticker-panel-${item}`}
              onClick={() => setTab(item)}
              iconKey={item}
            >
              {t(
                `stickers.${item === 'assignment' && form.data.type === 'dynamic' ? 'conditions' : item}`,
              )}
              {Object.keys(errors).some((key) => tabFor(key) === item) && (
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

        <FormErrors errors={errors} />
        <fieldset disabled={!manage || form.processing} className="space-y-6">
          {tab === 'general' && (
            <section
              role="tabpanel"
              id="sticker-panel-general"
              aria-labelledby="sticker-tab-general"
            >
              <UnifiedCard
                title={t('stickers.general')}
                iconKey="general"
                bodyClassName="p-5 "
              >
                <div className="grid gap-6 md:grid-cols-2">
                  <FormField
                    id="sticker-name"
                    label={t('stickers.name')}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={errors.name}
                    required
                    maxLength={128}
                  />
                  {choice('type', ['manual', 'dynamic'])}
                  {choice('style', ['text', 'image'])}
                  <FormField
                    id="sticker-priority"
                    label={t('stickers.priority')}
                    value={form.data.priority}
                    onChange={(e) => form.setData('priority', e.target.value)}
                    type="number"
                    min="0"
                    max="99999"
                    error={errors.priority}
                  />
                  {(['starts_at', 'ends_at'] as const).map((field) => (
                    <FormField
                      key={field}
                      id={field}
                      type="datetime-local"
                      label={t(`stickers.${field}`)}
                      value={form.data[field]}
                      onChange={(e) => form.setData(field, e.target.value)}
                      error={errors[field]}
                    />
                  ))}
                </div>
                <p className="mt-4 text-sm text-slate-500">
                  {t('stickers.schedule_help', { timezone })}
                </p>
              </UnifiedCard>
            </section>
          )}
          {tab === 'appearance' && (
            <section
              role="tabpanel"
              id="sticker-panel-appearance"
              aria-labelledby="sticker-tab-appearance"
            >
              <UnifiedCard
                title={t('stickers.appearance')}
                iconKey="appearance"
                bodyClassName="p-5 "
              >
                <div className="grid gap-6 md:grid-cols-2">
                  {(['text', 'short_text', 'tooltip'] as const)
                    .filter(
                      (field) =>
                        form.data.style === 'text' || field !== 'short_text',
                    )
                    .map((field) => (
                      <FormField
                        key={`${language}-${field}`}
                        id={`text-${field}`}
                        label={t(
                          `stickers.${form.data.style === 'image' && field === 'text' ? 'alt' : field}`,
                        )}
                        value={form.data.translations[language]?.[field] ?? ''}
                        required={field === 'text'}
                        maxLength={
                          field === 'text'
                            ? 200
                            : field === 'short_text'
                              ? 80
                              : 500
                        }
                        error={errors[`translations.${language}.${field}`]}
                        onChange={(e) =>
                          form.setData('translations', {
                            ...form.data.translations,
                            [language]: {
                              ...form.data.translations[language],
                              [field]: e.target.value,
                            },
                          })
                        }
                      />
                    ))}
                </div>
                {form.data.style === 'text' ? (
                  <div className="mt-6 grid gap-6 md:grid-cols-3">
                    {(['background', 'color', 'border_color'] as const).map(
                      (field) => (
                        <FormField
                          key={field}
                          id={field}
                          label={t(`stickers.${field}`)}
                          type="color"
                          value={form.data[field]}
                          onChange={(e) => form.setData(field, e.target.value)}
                          error={errors[field]}
                        />
                      ),
                    )}
                    {(
                      [
                        'radius',
                        'padding',
                        'font_size',
                        'border_width',
                      ] as const
                    ).map((field) => (
                      <FormField
                        key={field}
                        id={field}
                        label={`${t(`stickers.${field}`)} (px)`}
                        type="number"
                        value={form.data[field]}
                        onChange={(e) => form.setData(field, e.target.value)}
                        error={errors[field]}
                      />
                    ))}
                    {choice('alignment', ['left', 'center', 'right'])}
                  </div>
                ) : (
                  <div className="mt-6 space-y-6">
                    <SingleImageUploader
                      title={t('stickers.image')}
                      help={t('stickers.image_help')}
                      current={sticker?.image ? { url: sticker.image } : null}
                      alt={form.data.translations[language]?.text ?? ''}
                      file={form.data.image}
                      onFile={(f) => form.setData('image', f)}
                      removed={!form.data.image_media_id}
                      onRemoved={(removed) => {
                        if (removed) form.setData('image_media_id', '');
                      }}
                      disabled={!manage}
                      error={errors.image}
                    />
                    {pick(
                      'images',
                      form.data.image_media_id
                        ? [form.data.image_media_id]
                        : [],
                      (ids) => form.setData('image_media_id', ids[0] ?? ''),
                      t('stickers.reuse_image'),
                      true,
                    )}
                    <FormField
                      id="image-width"
                      label={`${t('stickers.image_width')} (px)`}
                      type="number"
                      min="16"
                      max="160"
                      value={form.data.image_width}
                      onChange={(e) =>
                        form.setData('image_width', e.target.value)
                      }
                      error={errors.image_width}
                    />
                  </div>
                )}
                {form.data.style === 'text' && (
                  <div className="my-6 rounded-lg border border-dashed border-slate-300 p-6">
                    <p className="mb-3 text-xs text-slate-500">
                      {t('stickers.preview')}
                    </p>
                    <output
                      aria-label={t('stickers.preview')}
                      style={{
                        display: 'inline-block',
                        backgroundColor: form.data.background,
                        color: form.data.color,
                        borderRadius: Number(form.data.radius),
                        padding: Number(form.data.padding),
                        fontSize: Number(form.data.font_size),
                        textAlign: form.data.alignment as
                          'left' | 'center' | 'right',
                        border: `${form.data.border_width}px solid ${form.data.border_color}`,
                      }}
                    >
                      {form.data.translations[language]?.text || '…'}
                    </output>
                  </div>
                )}
                <FormField
                  id="css-class"
                  label={t('stickers.css_class')}
                  value={form.data.css_class}
                  onChange={(e) => form.setData('css_class', e.target.value)}
                  error={errors.css_class}
                />
              </UnifiedCard>
            </section>
          )}
          {tab === 'display' && (
            <section
              role="tabpanel"
              id="sticker-panel-display"
              aria-labelledby="sticker-tab-display"
            >
              <UnifiedCard
                title={t('stickers.display')}
                iconKey="display"
                bodyClassName="p-5 "
              >
                {choice('placement', [
                  'image_top',
                  'image_bottom',
                  'above_price',
                  'below_price',
                ])}
                <div className="mt-6 flex flex-wrap gap-6">
                  {(
                    [
                      'show_card',
                      'show_detail',
                      'show_mobile',
                      'show_tablet',
                      'show_desktop',
                    ] as const
                  ).map((field) => (
                    <label
                      className="flex items-center gap-2 text-sm"
                      key={field}
                    >
                      <input
                        type="checkbox"
                        checked={form.data[field]}
                        onChange={(e) => form.setData(field, e.target.checked)}
                      />
                      {t(`stickers.${field}`)}
                    </label>
                  ))}
                </div>
                <div className="mt-6 flex items-center gap-2 border-t border-slate-200 pt-5">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <input
                      type="checkbox"
                      checked={form.data.is_filter}
                      onChange={(e) =>
                        form.setData('is_filter', e.target.checked)
                      }
                    />
                    {t('stickers.is_filter')}
                  </label>
                  <HelpPopover label={t('stickers.is_filter')}>
                    {t('stickers.is_filter_help')}
                  </HelpPopover>
                </div>
                {form.data.is_filter && (
                  <div className="mt-4 max-w-xl">
                    <FormField
                      id="sticker-filter-slug"
                      label={t('stickers.filter_slug')}
                      value={sticker?.filter_slug ?? ''}
                      placeholder={t('stickers.filter_slug_pending')}
                      hint={
                        sticker?.filter_slug
                          ? t('stickers.filter_slug_hint', {
                              url: '?product-label=' + sticker.filter_slug,
                            })
                          : t('stickers.filter_slug_pending')
                      }
                      disabled
                      readOnly
                    />
                  </div>
                )}
                <p className="mt-5 text-sm text-slate-500">
                  {t('stickers.priority_help')}
                </p>
              </UnifiedCard>
            </section>
          )}
          {tab === 'assignment' && (
            <section
              role="tabpanel"
              id="sticker-panel-assignment"
              aria-labelledby="sticker-tab-assignment"
            >
              <UnifiedCard
                title={t(
                  form.data.type === 'manual'
                    ? 'stickers.assignment'
                    : 'stickers.conditions',
                )}
                iconKey="stickers"
                bodyClassName="p-5 "
              >
                {form.data.type === 'manual' ? (
                  pick(
                    'products',
                    form.data.products,
                    (ids) => form.setData('products', ids),
                    t('stickers.products'),
                  )
                ) : (
                  <>
                    <p className="mb-5 text-sm text-slate-500">
                      {t('stickers.condition_help', { currency })}
                    </p>
                    <div className="flex flex-wrap items-center justify-between gap-4 border-y border-slate-200 py-4">
                      <div className="flex flex-wrap items-center gap-3 text-sm">
                        <label htmlFor="sticker-match" className="font-medium">
                          {t('stickers.match')}
                        </label>
                        <Select
                          id="sticker-match"
                          aria-label={t('stickers.match')}
                          className="w-44"
                          value={form.data.match}
                          onChange={(value) => form.setData('match', value)}
                          options={['all', 'any'].map((value) => ({
                            value,
                            label: t(`stickers.${value}`),
                          }))}
                        />
                      </div>
                      {form.data.conditions.length < 12 && (
                        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                          <Select
                            aria-label={t('stickers.add_condition')}
                            className="min-w-0 flex-1 sm:w-64 sm:flex-none"
                            value={newType}
                            onChange={setNewType}
                            options={conditionTypes.map((value) => ({
                              value,
                              label: t(`stickers.condition_${value}`),
                            }))}
                          />
                          <button
                            type="button"
                            className="button-secondary shrink-0 gap-2"
                            onClick={() =>
                              form.setData('conditions', [
                                ...form.data.conditions,
                                emptyCondition(newType),
                              ])
                            }
                          >
                            <Icon name="plus" />
                            {t('stickers.add_condition')}
                          </button>
                        </div>
                      )}
                    </div>
                    <div className="mt-5 space-y-3 border-l border-dashed border-slate-300 pl-3 sm:pl-5">
                      {form.data.conditions.map((c, n) => {
                        const f = options.features?.find(
                          (f) => f.id === c.feature_id,
                        );
                        const numeric =
                          [
                            'price',
                            'discount_percent',
                            'discount_amount',
                            'quantity',
                            'sales',
                          ].includes(c.type) ||
                          (c.type === 'feature' && f?.type === 'number');
                        return (
                          <div
                            key={n}
                            className="relative grid gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 pr-14 lg:grid-cols-[minmax(160px,0.7fr)_minmax(0,2fr)]"
                          >
                            <div className="flex items-start gap-3 lg:pt-2">
                              <h3 className="text-sm font-medium">
                                {t(`stickers.condition_${c.type}`)}
                              </h3>
                            </div>
                            <span className="absolute top-3 right-3">
                              <IconButton
                                icon="trash"
                                danger
                                label={t('stickers.remove')}
                                onClick={() =>
                                  form.setData(
                                    'conditions',
                                    form.data.conditions.filter(
                                      (_, i) => i !== n,
                                    ),
                                  )
                                }
                              />
                            </span>
                            <div className="min-w-0 space-y-4">
                              {c.type === 'category' && (
                                <>
                                  {pick(
                                    'categories',
                                    c.category_id ? [c.category_id] : [],
                                    (ids) =>
                                      condition(n, {
                                        category_id: ids[0] ?? '',
                                      }),
                                    t('stickers.category'),
                                    true,
                                  )}
                                  <label className="mt-4 flex items-center gap-2 text-sm">
                                    <input
                                      type="checkbox"
                                      checked={c.include_descendants}
                                      onChange={(e) =>
                                        condition(n, {
                                          include_descendants: e.target.checked,
                                        })
                                      }
                                    />
                                    {t('stickers.include_descendants')}
                                  </label>
                                </>
                              )}
                              {c.type === 'feature' && (
                                <div className="space-y-5">
                                  {pick(
                                    'features',
                                    c.feature_id ? [c.feature_id] : [],
                                    (ids) =>
                                      condition(n, {
                                        feature_id: ids[0] ?? '',
                                        option_id: '',
                                        minimum: '',
                                        maximum: '',
                                        boolean_value: null,
                                      }),
                                    t('stickers.feature'),
                                    true,
                                  )}
                                  {f &&
                                    ['single', 'multiple'].includes(
                                      f.type ?? '',
                                    ) && (
                                      <TargetPicker
                                        label={t('stickers.feature_value')}
                                        kind="options"
                                        query={{
                                          feature_id: c.feature_id,
                                          tab,
                                        }}
                                        path={path}
                                        values={
                                          c.option_id ? [c.option_id] : []
                                        }
                                        options={(options.options ?? []).filter(
                                          (o) => o.feature_id === c.feature_id,
                                        )}
                                        onChange={(ids) =>
                                          condition(n, {
                                            option_id: ids[0] ?? '',
                                          })
                                        }
                                        single
                                      />
                                    )}
                                  {f?.type === 'boolean' && (
                                    <Select
                                      aria-label={t('stickers.feature_value')}
                                      value={
                                        c.boolean_value === null
                                          ? ''
                                          : String(c.boolean_value)
                                      }
                                      onChange={(v) =>
                                        condition(n, {
                                          boolean_value:
                                            v === '' ? null : v === 'true',
                                        })
                                      }
                                      options={[
                                        {
                                          value: '',
                                          label: t('stickers.select'),
                                        },
                                        {
                                          value: 'true',
                                          label: t('stickers.yes'),
                                        },
                                        {
                                          value: 'false',
                                          label: t('stickers.no'),
                                        },
                                      ]}
                                    />
                                  )}
                                </div>
                              )}
                              {numeric && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                  {(['minimum', 'maximum'] as const).map(
                                    (field) => (
                                      <FormField
                                        key={field}
                                        id={`condition-${n}-${field}`}
                                        label={t(`stickers.${field}`)}
                                        value={c[field]}
                                        inputMode="decimal"
                                        error={
                                          errors[`conditions.${n}.${field}`]
                                        }
                                        onChange={(e) =>
                                          condition(n, {
                                            [field]: e.target.value,
                                          })
                                        }
                                      />
                                    ),
                                  )}
                                </div>
                              )}
                              {c.type === 'new' && (
                                <FormField
                                  id={`age-${n}`}
                                  label={t('stickers.age_days')}
                                  value={c.maximum}
                                  type="number"
                                  min="1"
                                  max="3650"
                                  onChange={(e) =>
                                    condition(n, { maximum: e.target.value })
                                  }
                                  error={errors[`conditions.${n}.maximum`]}
                                />
                              )}
                              {!numeric &&
                                !['category', 'feature', 'new'].includes(
                                  c.type,
                                ) && (
                                  <span className="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                                    <Icon name="check" />
                                    {t('stickers.yes')}
                                  </span>
                                )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </>
                )}
              </UnifiedCard>
            </section>
          )}
        </fieldset>
      </form>
      {deleting && sticker && (
        <ConfirmDeleteDialog
          title={t('stickers.delete')}
          confirmLabel={t('stickers.delete')}
          facts={[t('stickers.delete_impact')]}
          onCancel={() => setDeleting(false)}
          onConfirm={() =>
            router.delete(`/admin/stickers/${sticker.id}`, {
              data: { version: sticker.version },
            })
          }
        />
      )}
    </AdminLayout>
  );
}
