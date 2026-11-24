import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import { SingleImageUploader } from '../../../components/admin/ImageUploader';
import Select from '../../../components/admin/Select';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  NamedOption,
  ShippingMethodEditor,
  ShippingProviderOption,
  ShippingRateInput,
} from '../../../types/shipping';

type Text = { name: string; description: string; delivery_time: string };

// Literal keys (translation keys are never built at runtime).
const rateFields = [
  ['weight_from', 'shipping.rate_field_weight_from'],
  ['weight_to', 'shipping.rate_field_weight_to'],
  ['base', 'shipping.rate_field_base'],
  ['per_unit', 'shipping.rate_field_per_unit'],
] as const;

function initial(
  method: ShippingMethodEditor | null,
  locales: { code: string }[],
) {
  return {
    version: method?.version ?? 1,
    calculation: method?.calculation ?? ('manual' as 'manual' | 'carrier'),
    provider: method?.provider ?? '',
    account_id: method?.account_id ?? '',
    is_active: method?.active ?? false,
    min_weight: method?.min_weight ?? '',
    max_weight: method?.max_weight ?? '',
    free_shipping_applies: method?.free_shipping_applies ?? true,
    value_limit_enabled: method?.value_limit_enabled ?? false,
    max_shipment_value: method?.max_shipment_value ?? '',
    carrier_amount: method?.carrier_amount ?? ('gross' as 'gross' | 'net'),
    carrier_surcharge: method?.carrier_surcharge ?? '0',
    translations: Object.fromEntries(
      locales.map((l) => [
        l.code,
        method?.translations[l.code] ?? {
          name: '',
          description: '',
          delivery_time: '',
        },
      ]),
    ) as Record<string, Text>,
    rates: method?.rates ?? ([] as ShippingRateInput[]),
    area_ids: method?.area_ids ?? ([] as string[]),
    tax_ids: method?.tax_ids ?? ([] as string[]),
    payment_method_ids: method?.payment_method_ids ?? ([] as string[]),
    customer_group_ids: method?.customer_group_ids ?? ([] as string[]),
    image: null as File | null,
    remove_image: false,
  };
}

function Checklist({
  legend,
  hint,
  options,
  selected,
  onChange,
}: {
  legend: string;
  hint?: string;
  options: NamedOption[];
  selected: string[];
  onChange: (ids: string[]) => void;
}) {
  const t = useTranslation();
  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium text-slate-800">{legend}</legend>
      {hint && <p className="text-xs text-slate-500">{hint}</p>}
      {options.length === 0 ? (
        <p className="text-sm text-slate-500">{t('shipping.none_available')}</p>
      ) : (
        <div className="grid gap-1 sm:grid-cols-2">
          {options.map((option) => (
            <label key={option.id} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={selected.includes(option.id)}
                onChange={(e) =>
                  onChange(
                    e.target.checked
                      ? [...selected, option.id]
                      : selected.filter((id) => id !== option.id),
                  )
                }
              />
              {option.name}
            </label>
          ))}
        </div>
      )}
    </fieldset>
  );
}

export default function ShippingMethodForm({
  method,
  providers,
  areas,
  taxes,
  paymentMethods,
  customerGroups,
  weightUnit,
  currency,
  defaultLocale,
  ...shell
}: AdminShell & {
  method: ShippingMethodEditor | null;
  providers: ShippingProviderOption[];
  areas: NamedOption[];
  taxes: NamedOption[];
  paymentMethods: NamedOption[];
  customerGroups: NamedOption[];
  weightUnit: string;
  currency: { code: string; decimals: number };
  defaultLocale: string;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [locale, setLocale] = useState(defaultLocale);
  const form = useForm(initial(method, i18n.locales));
  const errors = form.errors as Record<string, string>;
  const text = form.data.translations[locale] ?? {
    name: '',
    description: '',
    delivery_time: '',
  };
  const setText = (patch: Partial<Text>) =>
    form.setData('translations', {
      ...form.data.translations,
      [locale]: { ...text, ...patch },
    });
  const carrier = form.data.calculation === 'carrier';
  const eligible = providers.filter((p) => (carrier ? p.quotes : p.shipments));
  const provider = providers.find((p) => p.id === form.data.provider);
  const title = method ? method.name : t('shipping.create');
  const setRate = (index: number, patch: Partial<ShippingRateInput>) =>
    form.setData(
      'rates',
      form.data.rates.map((r, i) => (i === index ? { ...r, ...patch } : r)),
    );
  const sync = (page: { props: unknown }) => {
    const next = initial(
      (page.props as { method: ShippingMethodEditor | null }).method,
      i18n.locales,
    );
    form.setDefaults(next);
    form.setData(next);
  };

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        image={method?.image?.url}
        heading={title}
        iconKey="shipping"
        backHref="/admin/shipping-methods"
        backLabel={t('shipping.back')}
        actions={
          <>
            <EditorActionButton
              disabled={form.processing}
              form="admin-shippingmethods-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          const url =
            '/admin/shipping-methods' + (method ? '/' + method.id : '');
          const options = { preserveScroll: true, onSuccess: sync };
          // A chosen image travels as multipart, which PHP only parses for POST (the update is method-spoofed).
          if (form.data.image) {
            form.transform((data) =>
              method ? { ...data, _method: 'put' } : data,
            );
            form.post(url, { ...options, forceFormData: true });
          } else {
            form.transform((data) => data);
            if (method) form.put(url, options);
            else form.post(url, options);
          }
        }}
        id="admin-shippingmethods-form-0"
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
              requiredIndicator={locale === defaultLocale}
              maxLength={100}
              value={text.name}
              error={errors['translations.' + locale + '.name']}
              onChange={(e) => setText({ name: e.target.value })}
            />
            <FormField
              id={'delivery-' + locale}
              label={t('shipping.delivery_time')}
              hint={t('shipping.delivery_time_hint')}
              maxLength={100}
              value={text.delivery_time}
              error={errors['translations.' + locale + '.delivery_time']}
              onChange={(e) => setText({ delivery_time: e.target.value })}
            />
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="description"
              >
                {t('shipping.description')}
              </label>
              <textarea
                id="description"
                className="field min-h-20"
                maxLength={500}
                value={text.description}
                onChange={(e) => setText({ description: e.target.value })}
              />
            </div>
            <SingleImageUploader
              title={t('shipping.image')}
              help={t('shipping.image_help')}
              current={method?.image ?? null}
              alt={method?.name ?? t('shipping.image')}
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
                ) : undefined
              }
            />
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
                onChange={(e) => form.setData('is_active', e.target.checked)}
              />
              <span>
                <span className="font-medium">{t('shipping.active')}</span>
                <span className="block text-xs text-slate-500">
                  {t('shipping.active_hint')}
                </span>
              </span>
            </label>
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="calculation"
              >
                {t('shipping.calculation')}
              </label>
              <Select
                id="calculation"
                aria-label={t('shipping.calculation')}
                value={form.data.calculation}
                onChange={(value) =>
                  form.setData({
                    ...form.data,
                    calculation: value as 'manual' | 'carrier',
                    provider: '',
                    account_id: '',
                  })
                }
                options={[
                  { value: 'manual', label: t('shipping.calculation_manual') },
                  {
                    value: 'carrier',
                    label: t('shipping.calculation_carrier'),
                  },
                ]}
              />
              <p className="text-xs text-slate-500">
                {t(
                  carrier
                    ? 'shipping.calculation_carrier_hint'
                    : 'shipping.calculation_manual_hint',
                )}
              </p>
            </div>
            <div className="space-y-2">
              <label className="block text-sm font-medium" htmlFor="provider">
                {t(
                  carrier
                    ? 'shipping.provider'
                    : 'shipping.provider_for_shipments',
                )}
              </label>
              <Select
                id="provider"
                aria-label={t('shipping.provider')}
                value={form.data.provider}
                invalid={!!errors.provider}
                onChange={(value) =>
                  form.setData({
                    ...form.data,
                    provider: value,
                    account_id: '',
                  })
                }
                options={[
                  ...(carrier
                    ? []
                    : [{ value: '', label: t('shipping.no_carrier') }]),
                  ...eligible.map((p) => ({ value: p.id, label: p.name })),
                ]}
              />
              {eligible.length === 0 && (
                <p className="text-xs text-amber-700">
                  {t('shipping.no_providers')}
                </p>
              )}
            </div>
            {provider && (
              <div className="space-y-2">
                <label className="block text-sm font-medium" htmlFor="account">
                  {t('shipping.account')}
                </label>
                <Select
                  id="account"
                  aria-label={t('shipping.account')}
                  value={form.data.account_id}
                  invalid={!!errors.account_id}
                  onChange={(value) => form.setData('account_id', value)}
                  options={[
                    { value: '', label: t('shipping.choose_account') },
                    ...provider.accounts.map((a) => ({
                      value: a.id,
                      label: a.usable
                        ? a.name
                        : a.name +
                          ' · ' +
                          t('shipping.account_incomplete_short'),
                    })),
                  ]}
                />
                {provider.accounts.length === 0 && (
                  <p className="text-xs text-amber-700">
                    <Link
                      className="text-link"
                      href={
                        '/admin/shipping-carriers/' +
                        provider.id +
                        '?tab=accounts'
                      }
                    >
                      {t('shipping.add_account')}
                    </Link>
                  </p>
                )}
              </div>
            )}
          </UnifiedCard>
        </section>

        {!carrier && (
          <section>
            <UnifiedCard
              title={t('shipping.rates_title')}
              iconKey="rates_title"
              bodyClassName="p-5 space-y-4"
            >
              <p className="mt-1 text-xs text-slate-500">
                {t('shipping.rates_hint', {
                  unit: weightUnit,
                  currency: currency.code,
                })}
              </p>

              {errors.rates && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.rates}
                </p>
              )}
              {form.data.rates.length === 0 ? (
                <p className="text-sm text-slate-500">
                  {t('shipping.rates_empty')}
                </p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[42rem] text-left text-sm">
                    <thead className="text-xs text-slate-500">
                      <tr>
                        <th className="pb-2 font-normal">
                          {t('shipping.rate_area')}
                        </th>
                        <th className="pb-2 font-normal">
                          {t('shipping.weight_from', { unit: weightUnit })}
                        </th>
                        <th className="pb-2 font-normal">
                          {t('shipping.weight_to', { unit: weightUnit })}
                        </th>
                        <th className="pb-2 font-normal">
                          {t('shipping.base', { currency: currency.code })}
                        </th>
                        <th className="pb-2 font-normal">
                          {t('shipping.per_unit', { unit: weightUnit })}
                        </th>
                        <th className="pb-2" />
                      </tr>
                    </thead>
                    <tbody>
                      {form.data.rates.map((rate, index) => (
                        <tr key={index} className="align-top">
                          <td className="py-1 pr-2">
                            <Select
                              aria-label={t('shipping.rate_area')}
                              value={rate.rate_area_id}
                              invalid={
                                !!errors['rates.' + index + '.rate_area_id']
                              }
                              onChange={(value) =>
                                setRate(index, { rate_area_id: value })
                              }
                              options={[
                                { value: '', label: t('shipping.choose_area') },
                                ...areas.map((a) => ({
                                  value: a.id,
                                  label: a.name,
                                })),
                              ]}
                            />
                          </td>
                          {rateFields.map(([field, label]) => (
                            <td key={field} className="py-1 pr-2">
                              <input
                                className="field tabular-nums"
                                inputMode="decimal"
                                aria-label={t(label)}
                                aria-invalid={
                                  errors['rates.' + index + '.' + field]
                                    ? true
                                    : undefined
                                }
                                placeholder={field === 'weight_to' ? '∞' : '0'}
                                value={rate[field]}
                                onChange={(e) =>
                                  setRate(index, { [field]: e.target.value })
                                }
                              />
                              {errors['rates.' + index + '.' + field] && (
                                <span
                                  role="alert"
                                  className="mt-1 block text-xs text-red-700"
                                >
                                  {errors['rates.' + index + '.' + field]}
                                </span>
                              )}
                            </td>
                          ))}
                          <td className="py-1">
                            <IconButton
                              icon="trash"
                              danger
                              label={t('shipping.remove_rate')}
                              onClick={() =>
                                form.setData(
                                  'rates',
                                  form.data.rates.filter((_, i) => i !== index),
                                )
                              }
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <button
                type="button"
                className="button-secondary gap-2"
                onClick={() =>
                  form.setData('rates', [
                    ...form.data.rates,
                    {
                      rate_area_id: '',
                      weight_from: '0',
                      weight_to: '',
                      base: '0',
                      per_unit: '0',
                    },
                  ])
                }
              >
                <Icon name="plus" />
                {t('shipping.add_rate')}
              </button>
            </UnifiedCard>
          </section>
        )}

        {carrier && (
          <section>
            <AdminCard
              title={t('shipping.carrier_rates_title')}
              iconKey="carrier_rates_title"
              bodyClassName="p-5 space-y-5"
            >
              <div className="grid gap-5 sm:grid-cols-2">
                <div className="space-y-2">
                  <label
                    className="block text-sm font-medium"
                    htmlFor="carrier-amount"
                  >
                    {t('shipping.carrier_amount')}
                  </label>
                  <Select
                    id="carrier-amount"
                    aria-label={t('shipping.carrier_amount')}
                    value={form.data.carrier_amount}
                    onChange={(value) =>
                      form.setData('carrier_amount', value as 'gross' | 'net')
                    }
                    options={[
                      { value: 'gross', label: t('shipping.carrier_gross') },
                      { value: 'net', label: t('shipping.carrier_net') },
                    ]}
                  />
                  <p className="text-xs text-slate-500">
                    {t('shipping.carrier_amount_hint')}
                  </p>
                </div>
                <FormField
                  id="surcharge"
                  label={t('shipping.surcharge', { currency: currency.code })}
                  hint={t('shipping.surcharge_hint')}
                  inputMode="decimal"
                  value={form.data.carrier_surcharge}
                  error={errors.carrier_surcharge}
                  onChange={(e) =>
                    form.setData('carrier_surcharge', e.target.value)
                  }
                />
              </div>
              <Checklist
                legend={t('shipping.areas')}
                hint={t('shipping.areas_hint')}
                options={areas}
                selected={form.data.area_ids}
                onChange={(ids) => form.setData('area_ids', ids)}
              />
            </AdminCard>
          </section>
        )}

        <section>
          <AdminCard
            title={t('shipping.restrictions_title')}
            iconKey="restrictions_title"
            bodyClassName="p-5 space-y-5"
          >
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField
                id="min-weight"
                label={t('shipping.min_weight', { unit: weightUnit })}
                inputMode="decimal"
                value={form.data.min_weight}
                error={errors.min_weight}
                onChange={(e) => form.setData('min_weight', e.target.value)}
              />
              <FormField
                id="max-weight"
                label={t('shipping.max_weight', { unit: weightUnit })}
                inputMode="decimal"
                value={form.data.max_weight}
                error={errors.max_weight}
                onChange={(e) => form.setData('max_weight', e.target.value)}
              />
            </div>
            <div className="space-y-3">
              <label className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={form.data.value_limit_enabled}
                  onChange={(e) =>
                    form.setData('value_limit_enabled', e.target.checked)
                  }
                />
                <span>
                  <span className="font-medium">
                    {t('shipping.value_limit_enabled')}
                  </span>
                  <span className="block text-xs text-slate-500">
                    {t('shipping.value_limit_hint')}
                  </span>
                </span>
              </label>
              {form.data.value_limit_enabled && (
                <FormField
                  id="max-shipment-value"
                  label={t('shipping.max_shipment_value', {
                    currency: currency.code,
                  })}
                  inputMode="decimal"
                  value={form.data.max_shipment_value}
                  error={errors.max_shipment_value}
                  onChange={(e) =>
                    form.setData('max_shipment_value', e.target.value)
                  }
                />
              )}
            </div>
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={form.data.free_shipping_applies}
                onChange={(e) =>
                  form.setData('free_shipping_applies', e.target.checked)
                }
              />
              <span>
                <span className="font-medium">
                  {t('shipping.free_shipping_applies')}
                </span>
                <span className="block text-xs text-slate-500">
                  {t('shipping.free_shipping_applies_hint')}
                </span>
              </span>
            </label>
            <Checklist
              legend={t('shipping.taxes')}
              hint={t('shipping.taxes_hint')}
              options={taxes}
              selected={form.data.tax_ids}
              onChange={(ids) => form.setData('tax_ids', ids)}
            />
            <Checklist
              legend={t('shipping.payment_methods')}
              hint={t('shipping.payment_methods_hint')}
              options={paymentMethods}
              selected={form.data.payment_method_ids}
              onChange={(ids) => form.setData('payment_method_ids', ids)}
            />
            <Checklist
              legend={t('shipping.customer_groups')}
              hint={t('shipping.customer_groups_hint')}
              options={customerGroups}
              selected={form.data.customer_group_ids}
              onChange={(ids) => form.setData('customer_group_ids', ids)}
            />
          </AdminCard>
        </section>
      </form>
    </AdminLayout>
  );
}
