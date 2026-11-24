import AdminCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useTranslation } from '../../../lib/useTranslation';
import { useUrlTab } from '../../../lib/useUrlTab';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  PromotionCondition,
  PromotionData,
  PromotionOption,
  PromotionDetail,
  TargetKind,
} from '../../../types/promotions';
import TargetPicker from './TargetPicker';

const conditionTypes = [
  'subtotal',
  'products',
  'categories',
  'quantity',
  'groups',
  'shipping',
] as const;
export default function PromotionForm({
  promotion,
  options,
  currency,
  timezone,
  ...shell
}: AdminShell & {
  promotion: PromotionDetail | null;
  options: Record<TargetKind | 'coupons', PromotionOption[]>;
  currency: { code: string; decimals: number };
  timezone: string;
}) {
  const t = useTranslation();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const manage = !!capabilities['promotions.manage'];
  const tabs = ['general', 'conditions', 'benefits'] as const;
  const [tab, setTab] = useUrlTab(tabs);
  const [language, setLanguage] = useState(i18n.locales[0]?.code ?? 'en');
  const [newType, setNewType] =
    useState<PromotionCondition['type']>('subtotal');
  const targetIds = (values: (string | null)[] = []) =>
    values.map((id) => id ?? '');
  const form = useForm<PromotionData>({
    version: promotion?.version ?? 0,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [
        l.code,
        promotion?.translations[l.code] ?? { name: '', description: '' },
      ]),
    ),
    status: promotion?.status ?? 'active',
    mode: promotion?.mode ?? 'automatic',
    coupon_id: promotion?.coupon_id ?? '',
    starts_at: promotion?.starts_at ?? '',
    ends_at: promotion?.ends_at ?? '',
    priority: promotion?.priority ?? '100',
    stop_other: promotion?.stop_other ?? false,
    match: promotion?.match ?? 'all',
    benefit: promotion?.benefit ?? 'percentage',
    scope: promotion?.scope ?? 'order',
    amount: promotion?.amount ?? '',
    all_products: promotion?.all_products ?? true,
    exclude_sale: promotion?.exclude_sale ?? false,
    all_shipping: promotion?.all_shipping ?? true,
    conditions: (promotion?.conditions ?? []).map((c) => ({
      ...c,
      minimum: c.minimum ?? '',
      maximum: c.maximum ?? '',
      quantity: c.quantity ?? '',
      products: targetIds(c.products),
      categories: targetIds(c.categories),
      groups: targetIds(c.groups),
      shipping: targetIds(c.shipping),
    })),
    products: targetIds(promotion?.products),
    categories: targetIds(promotion?.categories),
    shipping: targetIds(promotion?.shipping),
    excluded_products: targetIds(promotion?.excluded_products),
    excluded_categories: targetIds(promotion?.excluded_categories),
  });
  useDirtyForm(form);
  const path = promotion
    ? `/admin/promotions/${promotion.id}/edit`
    : '/admin/promotions/create';
  const availableTypes = conditionTypes.filter(
    (type) => !form.data.conditions.some((c) => c.type === type),
  );
  const title = promotion?.name ?? t('promotions.create');
  const setCondition = (index: number, changes: Partial<PromotionCondition>) =>
    form.setData(
      'conditions',
      form.data.conditions.map((c, n) =>
        n === index ? { ...c, ...changes } : c,
      ),
    );
  const pick = (
    kind: TargetKind,
    field:
      | 'products'
      | 'categories'
      | 'shipping'
      | 'excluded_products'
      | 'excluded_categories',
    label?: string,
  ) => (
    <TargetPicker
      label={label ?? t(`promotions.${field}`)}
      kind={kind}
      values={form.data[field]}
      options={options[kind]}
      path={path}
      onChange={(v) => form.setData(field, v)}
    />
  );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="promotions"
        backHref="/admin/promotions"
        backLabel={t('promotions.back')}
        status={
          <>
            <EditorStatusSelect
              id="promotion-status"
              aria-label={t('promotions.status')}
              disabled={!manage}
              value={form.data.status}
              onChange={(v) =>
                form.setData('status', v as PromotionData['status'])
              }
              options={['active', 'disabled'].map((value) => ({
                value,
                label: t(`promotions.${value}`),
              }))}
            />
          </>
        }
        actions={
          <>
            {manage && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-promotions-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!manage) return;
          const onSuccess = (page: { props: unknown }) => {
            const saved = (page.props as { promotion: PromotionDetail })
              .promotion;
            const next = { ...form.data, version: saved.version };
            form.setDefaults(next);
            form.setData(next);
          };
          const onError = (errors: Record<string, string>) => {
            const first = Object.keys(errors)[0] ?? '';
            const field = first.split('.')[0];
            setTab(
              ['conditions', 'match'].includes(field)
                ? 'conditions'
                : [
                      'benefit',
                      'scope',
                      'amount',
                      'all_products',
                      'exclude_sale',
                      'all_shipping',
                      'products',
                      'categories',
                      'shipping',
                      'excluded_products',
                      'excluded_categories',
                    ].includes(field)
                  ? 'benefits'
                  : 'general',
            );
            if (field === 'translations') {
              const locale = first.split('.')[1];
              if (i18n.locales.some((l) => l.code === locale))
                setLanguage(locale);
            }
          };
          if (promotion)
            form.put(`/admin/promotions/${promotion.id}`, {
              onSuccess,
              onError,
            });
          else form.post('/admin/promotions', { onSuccess, onError });
        }}
        id="admin-promotions-form-0"
      >
        <FormErrors errors={form.errors} />

        <AdminTabs
          label={t('promotions.title')}
          language={
            <ContentLanguageSelector
              languages={i18n.locales}
              value={language}
              onChange={setLanguage}
            />
          }
        >
          {tabs.map((item) => (
            <AdminTab
              key={item}
              type="button"
              id={`promotion-tab-${item}`}
              role="tab"
              aria-selected={tab === item}
              aria-controls={`promotion-panel-${item}`}
              onClick={() => setTab(item)}
              iconKey={item}
            >
              {t(`promotions.${item}`)}
            </AdminTab>
          ))}
        </AdminTabs>

        <fieldset disabled={!manage} className="space-y-6">
          {tab === 'general' && (
            <section
              role="tabpanel"
              id="promotion-panel-general"
              aria-labelledby="promotion-tab-general"
            >
              <AdminCard
                title={t('promotions.general')}
                iconKey="general"
                bodyClassName="p-5 "
              >
                <div className="grid gap-6 md:grid-cols-2">
                  <FormField
                    id="promotion-name"
                    label={t('promotions.name')}
                    requiredIndicator
                    value={form.data.translations[language]?.name ?? ''}
                    onChange={(e) =>
                      form.setData('translations', {
                        ...form.data.translations,
                        [language]: {
                          ...form.data.translations[language],
                          name: e.target.value,
                        },
                      })
                    }
                    error={
                      form.errors[
                        `translations.${language}.name` as keyof PromotionData
                      ]
                    }
                  />
                  <FormField
                    id="promotion-description"
                    label={t('promotions.description')}
                    value={form.data.translations[language]?.description ?? ''}
                    onChange={(e) =>
                      form.setData('translations', {
                        ...form.data.translations,
                        [language]: {
                          ...form.data.translations[language],
                          description: e.target.value,
                        },
                      })
                    }
                  />
                  <div className="space-y-2">
                    <span className="text-sm font-medium">
                      {t('promotions.mode')}
                    </span>
                    <Select
                      aria-label={t('promotions.mode')}
                      value={form.data.mode}
                      onChange={(v) =>
                        form.setData({
                          ...form.data,
                          mode: v as PromotionData['mode'],
                          coupon_id: '',
                          benefit: v === 'coupon' ? 'coupon' : 'percentage',
                          amount: '',
                        })
                      }
                      options={['automatic', 'coupon'].map((value) => ({
                        value,
                        label: t(`promotions.${value}`),
                      }))}
                    />
                  </div>
                  {form.data.mode === 'coupon' && (
                    <TargetPicker
                      label={t('promotions.existing_coupon')}
                      kind="coupons"
                      values={form.data.coupon_id ? [form.data.coupon_id] : []}
                      options={options.coupons}
                      path={path}
                      single
                      onChange={(v) => form.setData('coupon_id', v[0] ?? '')}
                    />
                  )}
                  {(['starts_at', 'ends_at'] as const).map((field) => (
                    <FormField
                      key={field}
                      id={field}
                      type="datetime-local"
                      label={`${t(`promotions.${field}`)} (${timezone})`}
                      value={form.data[field]}
                      onChange={(e) => form.setData(field, e.target.value)}
                      error={form.errors[field]}
                    />
                  ))}
                  <FormField
                    id="priority"
                    label={t('promotions.priority')}
                    inputMode="numeric"
                    value={form.data.priority}
                    onChange={(e) => form.setData('priority', e.target.value)}
                    error={form.errors.priority}
                  />
                  <label className="flex items-center gap-3 text-sm">
                    <input
                      type="checkbox"
                      checked={form.data.stop_other}
                      onChange={(e) =>
                        form.setData('stop_other', e.target.checked)
                      }
                    />
                    {t('promotions.stop_other')}
                  </label>
                </div>
                <p className="mt-5 text-sm text-slate-500">
                  {t('promotions.schedule_help')}
                </p>
              </AdminCard>
            </section>
          )}
          {tab === 'conditions' && (
            <section
              role="tabpanel"
              id="promotion-panel-conditions"
              aria-labelledby="promotion-tab-conditions"
            >
              <AdminCard
                title={t('promotions.conditions')}
                iconKey="conditions"
                bodyClassName="p-5 "
              >
                <Select
                  aria-label={t('promotions.match')}
                  value={form.data.match}
                  onChange={(v) => form.setData('match', v as 'all' | 'any')}
                  options={['all', 'any'].map((value) => ({
                    value,
                    label: t(`promotions.match_${value}`),
                  }))}
                />
                <p className="mt-3 text-sm text-slate-500">
                  {t('promotions.subtotal_help')}
                </p>
                <div className="mt-5 space-y-4">
                  {form.data.conditions.map((c, n) => (
                    <div
                      key={c.type}
                      className="rounded-lg border border-slate-200 p-5"
                    >
                      <div className="mb-4 flex justify-between gap-3">
                        <h3 className="font-medium">
                          {t(`promotions.condition_${c.type}`)}
                        </h3>
                        <button
                          type="button"
                          className="text-link text-sm"
                          onClick={() =>
                            form.setData(
                              'conditions',
                              form.data.conditions.filter((_, i) => i !== n),
                            )
                          }
                        >
                          {t('promotions.remove')}
                        </button>
                      </div>
                      {c.type === 'subtotal' && (
                        <div className="grid gap-4 md:grid-cols-2">
                          {(['minimum', 'maximum'] as const).map((f) => (
                            <FormField
                              key={f}
                              id={`condition-${n}-${f}`}
                              label={`${t(`promotions.${f}`)} (${currency.code})`}
                              inputMode="decimal"
                              value={c[f]}
                              onChange={(e) =>
                                setCondition(n, { [f]: e.target.value })
                              }
                            />
                          ))}
                        </div>
                      )}
                      {c.type === 'products' && (
                        <Select
                          aria-label={t('promotions.product_match')}
                          value={c.match}
                          onChange={(v) =>
                            setCondition(n, { match: v as 'all' | 'any' })
                          }
                          options={['any', 'all'].map((value) => ({
                            value,
                            label: t(`promotions.products_${value}`),
                          }))}
                        />
                      )}
                      {c.type === 'quantity' && (
                        <div className="mb-4 grid gap-4 md:grid-cols-2">
                          <FormField
                            id={`quantity-${n}`}
                            label={t('promotions.quantity')}
                            inputMode="numeric"
                            value={c.quantity}
                            onChange={(e) =>
                              setCondition(n, { quantity: e.target.value })
                            }
                          />
                          <div className="space-y-2">
                            <label
                              htmlFor={`quantity-scope-${n}`}
                              className="block text-sm font-medium text-slate-800"
                            >
                              {t('promotions.quantity_scope')}
                            </label>
                            <Select
                              id={`quantity-scope-${n}`}
                              aria-label={t('promotions.quantity_scope')}
                              value={c.quantity_scope}
                              onChange={(v) =>
                                setCondition(n, {
                                  quantity_scope:
                                    v as PromotionCondition['quantity_scope'],
                                })
                              }
                              options={['cart', 'products', 'categories'].map(
                                (value) => ({
                                  value,
                                  label: t(`promotions.quantity_${value}`),
                                }),
                              )}
                            />
                          </div>
                        </div>
                      )}
                      {(
                        [
                          'products',
                          'categories',
                          'groups',
                          'shipping',
                        ] as const
                      )
                        .filter(
                          (kind) =>
                            c.type === kind ||
                            (c.type === 'quantity' &&
                              c.quantity_scope === kind),
                        )
                        .map((kind) => (
                          <TargetPicker
                            key={kind}
                            label={t(`promotions.${kind}`)}
                            kind={kind}
                            values={c[kind]}
                            options={options[kind]}
                            path={path}
                            onChange={(v) => setCondition(n, { [kind]: v })}
                          />
                        ))}
                    </div>
                  ))}
                </div>
                {form.data.conditions.length < conditionTypes.length && (
                  <div className="mt-5 flex gap-3">
                    <Select
                      aria-label={t('promotions.add_condition')}
                      value={
                        availableTypes.includes(newType)
                          ? newType
                          : (availableTypes[0] ?? 'subtotal')
                      }
                      onChange={(v) =>
                        setNewType(v as PromotionCondition['type'])
                      }
                      options={conditionTypes
                        .filter(
                          (type) =>
                            !form.data.conditions.some((c) => c.type === type),
                        )
                        .map((value) => ({
                          value,
                          label: t(`promotions.condition_${value}`),
                        }))}
                    />
                    <button
                      type="button"
                      className="button-secondary shrink-0"
                      onClick={() => {
                        const type =
                          conditionTypes.find(
                            (v) =>
                              v === newType &&
                              !form.data.conditions.some((c) => c.type === v),
                          ) ??
                          conditionTypes.find(
                            (v) =>
                              !form.data.conditions.some((c) => c.type === v),
                          );
                        if (type)
                          form.setData('conditions', [
                            ...form.data.conditions,
                            {
                              type,
                              minimum: '',
                              maximum: '',
                              quantity: '',
                              quantity_scope: 'cart',
                              match: 'any',
                              products: [],
                              categories: [],
                              groups: [],
                              shipping: [],
                            },
                          ]);
                      }}
                    >
                      {t('promotions.add_condition')}
                    </button>
                  </div>
                )}
                <p className="mt-4 text-sm text-slate-500">
                  {t('promotions.category_help')}
                </p>
              </AdminCard>
            </section>
          )}
          {tab === 'benefits' && (
            <section
              role="tabpanel"
              id="promotion-panel-benefits"
              aria-labelledby="promotion-tab-benefits"
            >
              <AdminCard
                title={t('promotions.benefits')}
                iconKey="benefits"
                bodyClassName="p-5 "
              >
                {form.data.mode === 'coupon' ? (
                  <p className="text-sm leading-6 text-slate-600">
                    {t('promotions.coupon_help')}
                  </p>
                ) : (
                  <>
                    <div className="grid gap-6 md:grid-cols-2">
                      <div className="space-y-2">
                        <label
                          htmlFor="promotion-benefit"
                          className="block text-sm font-medium text-slate-800"
                        >
                          {t('promotions.benefit')}
                        </label>
                        <Select
                          id="promotion-benefit"
                          aria-label={t('promotions.benefit')}
                          value={form.data.benefit}
                          onChange={(v) =>
                            form.setData({
                              ...form.data,
                              benefit: v as PromotionData['benefit'],
                              amount:
                                v === 'free_shipping' ? '' : form.data.amount,
                            })
                          }
                          options={['percentage', 'fixed', 'free_shipping'].map(
                            (value) => ({
                              value,
                              label: t(`promotions.${value}`),
                            }),
                          )}
                        />
                      </div>
                      {form.data.benefit !== 'free_shipping' && (
                        <>
                          <FormField
                            id="amount"
                            label={`${t('promotions.amount')} (${form.data.benefit === 'percentage' ? '%' : currency.code})`}
                            value={form.data.amount}
                            onChange={(e) =>
                              form.setData('amount', e.target.value)
                            }
                            error={form.errors.amount}
                          />
                          <div className="space-y-2">
                            <label
                              htmlFor="promotion-scope"
                              className="block text-sm font-medium text-slate-800"
                            >
                              {t('promotions.scope')}
                            </label>
                            <Select
                              id="promotion-scope"
                              aria-label={t('promotions.scope')}
                              value={form.data.scope}
                              onChange={(v) =>
                                form.setData('scope', v as 'order' | 'products')
                              }
                              options={['order', 'products'].map((value) => ({
                                value,
                                label: t(`promotions.scope_${value}`),
                              }))}
                            />
                            <p className="text-sm leading-6 text-slate-500">
                              {t(`promotions.scope_${form.data.scope}_help`)}
                            </p>
                          </div>
                        </>
                      )}
                    </div>
                    {form.data.benefit === 'free_shipping' ? (
                      <div className="mt-5 space-y-5">
                        <label className="flex items-center gap-3 text-sm">
                          <input
                            type="checkbox"
                            checked={form.data.all_shipping}
                            onChange={(e) =>
                              form.setData('all_shipping', e.target.checked)
                            }
                          />
                          {t('promotions.all_shipping')}
                        </label>
                        {!form.data.all_shipping &&
                          pick('shipping', 'shipping')}
                      </div>
                    ) : (
                      form.data.scope === 'products' && (
                        <div className="mt-5 space-y-5">
                          <label className="flex items-center gap-3 text-sm">
                            <input
                              type="checkbox"
                              checked={form.data.all_products}
                              onChange={(e) =>
                                form.setData('all_products', e.target.checked)
                              }
                            />
                            {t('promotions.all_products')}
                          </label>
                          {!form.data.all_products && (
                            <div className="grid gap-6 md:grid-cols-2">
                              {pick(
                                'products',
                                'products',
                                t('promotions.benefit_products'),
                              )}
                              {pick(
                                'categories',
                                'categories',
                                t('promotions.benefit_categories'),
                              )}
                            </div>
                          )}
                          <label className="flex items-center gap-3 text-sm">
                            <input
                              type="checkbox"
                              checked={form.data.exclude_sale}
                              onChange={(e) =>
                                form.setData('exclude_sale', e.target.checked)
                              }
                            />
                            {t('promotions.exclude_sale')}
                          </label>
                          <div className="grid gap-6 md:grid-cols-2">
                            {pick('products', 'excluded_products')}
                            {pick('categories', 'excluded_categories')}
                          </div>
                          <p className="text-sm text-slate-500">
                            {t('promotions.line_help')}
                          </p>
                        </div>
                      )
                    )}
                  </>
                )}
              </AdminCard>
            </section>
          )}
        </fieldset>
      </form>
    </AdminLayout>
  );
}
