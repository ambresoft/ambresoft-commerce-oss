import UnifiedCard from '../../../components/admin/AdminCard';
import AdminCard from '../../../components/admin/AdminCard';
import { useState } from 'react';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import HelpPopover from '../../../components/admin/HelpPopover';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import { Icon } from '../../../components/admin/list/IconButton';
import Select from '../../../components/admin/Select';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { AreaOption, TaxEditor, TaxRateInput } from '../../../types/taxes';

type Props = AdminShell & {
  tax: TaxEditor | null;
  areas: AreaOption[];
  defaultLocale: string;
  currency: { code: string; symbol: string; decimals: number };
};

export default function TaxForm({
  tax,
  areas,
  defaultLocale,
  currency,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const editable =
    tax === null ? capabilities['taxes.create'] : capabilities['taxes.update'];
  const [contentLocale, setContentLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : defaultLocale,
  );
  const form = useForm({
    version: tax?.version,
    names: Object.fromEntries(
      i18n.locales.map((l) => [l.code, tax?.names[l.code] ?? '']),
    ),
    registration_number: tax?.registration_number ?? '',
    priority: String(tax?.priority ?? 0),
    status: tax?.status ?? 'active',
    price_includes_tax: tax?.price_includes_tax ?? false,
    address_basis: tax?.address_basis ?? 'shipping',
    rates: (tax?.rates ?? []) as TaxRateInput[],
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const used = new Set(form.data.rates.map((r) => r.area_id));
  const free = areas.filter((a) => !used.has(a.id));
  const setRate = (index: number, change: Partial<TaxRateInput>) =>
    form.setData(
      'rates',
      form.data.rates.map((r, i) => (i === index ? { ...r, ...change } : r)),
    );
  const submit = () => {
    if (tax === null) form.post('/admin/taxes');
    else form.put('/admin/taxes/' + tax.id);
  };
  const title = tax === null ? t('taxes.create') : t('taxes.edit');
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div className="flex items-center gap-2">
              <EditorStatusSelect
                disabled={form.processing}
                aria-label={t('common.status')}
                id="status"
                value={form.data.status}
                invalid={!!errors.status}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
                options={[
                  { value: 'active', label: t('taxes.active') },
                  { value: 'disabled', label: t('taxes.disabled') },
                ]}
              />
              <HelpPopover label={t('common.status')}>
                {t('taxes.status_hint')}
              </HelpPopover>
            </div>
          </>
        }
        heading={title}
        iconKey="taxes"
        backHref="/admin/taxes"
        backLabel={t('taxes.title')}
        actions={
          editable && (
            <>
              <div className="flex gap-3">
                <EditorActionButton
                  disabled={form.processing}
                  form="admin-taxes-form-0"
                >
                  {t(form.processing ? 'common.wait' : 'common.save')}
                </EditorActionButton>
                <Link className="button-secondary" href="/admin/taxes">
                  {t('common.cancel')}
                </Link>
              </div>
            </>
          )
        }
      />
      <form
        className="w-full space-y-6"
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
        id="admin-taxes-form-0"
      >
        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(
              ([key]) =>
                !/^(names|rates|priority|status|registration_number|address_basis|price_includes_tax)/.test(
                  key,
                ),
            ),
          )}
        />
        <section>
          <AdminCard
            id="tax-general"
            title={t('taxes.section_general')}
            iconKey="general"
            bodyClassName="p-5 space-y-5"
          >
            <div className="grid gap-5 sm:grid-cols-2">
              <div className="col-span-full flex justify-end">
                <ContentLanguageSelector
                  languages={i18n.locales}
                  value={contentLocale}
                  onChange={setContentLocale}
                  errors={form.errors as Record<string, string>}
                />
              </div>
              {i18n.locales
                .filter((l) => l.code === contentLocale)
                .map((l) => (
                  <FormField
                    key={l.code}
                    id={'tax-name-' + l.code}
                    label={t('common.name') + ' · ' + l.code}
                    requiredIndicator={l.code === defaultLocale}
                    maxLength={120}
                    value={form.data.names[l.code] ?? ''}
                    error={errors['names.' + l.code]}
                    onChange={(e) =>
                      form.setData('names', {
                        ...form.data.names,
                        [l.code]: e.target.value,
                      })
                    }
                  />
                ))}
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField
                id="registration_number"
                label={t('taxes.registration_number')}
                hint={t('taxes.registration_number_hint')}
                maxLength={100}
                value={form.data.registration_number}
                error={errors.registration_number}
                onChange={(e) =>
                  form.setData('registration_number', e.target.value)
                }
              />
              <FormField
                id="priority"
                label={t('taxes.priority')}
                hint={t('taxes.priority_hint')}
                type="number"
                min={0}
                max={9999}
                step={1}
                inputMode="numeric"
                requiredIndicator
                value={form.data.priority}
                error={errors.priority}
                onChange={(e) => form.setData('priority', e.target.value)}
              />
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              <div className="space-y-2">
                <label
                  htmlFor="address_basis"
                  className="block text-sm font-medium"
                >
                  {t('taxes.address_basis')}
                </label>
                <Select
                  id="address_basis"
                  value={form.data.address_basis}
                  invalid={!!errors.address_basis}
                  onChange={(value) =>
                    form.setData(
                      'address_basis',
                      value as 'shipping' | 'billing',
                    )
                  }
                  options={[
                    { value: 'shipping', label: t('taxes.basis_shipping') },
                    { value: 'billing', label: t('taxes.basis_billing') },
                  ]}
                />
                <p className="text-xs leading-5 text-slate-500">
                  {t('taxes.address_basis_hint')}
                </p>
              </div>
            </div>
            <label className="flex items-start gap-3 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={form.data.price_includes_tax}
                onChange={(e) =>
                  form.setData('price_includes_tax', e.target.checked)
                }
              />
              <span>
                <span className="font-medium">
                  {t('taxes.price_includes_tax')}
                </span>
                <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                  {t('taxes.price_includes_tax_hint')}
                </span>
              </span>
            </label>
          </AdminCard>
        </section>
        <section aria-labelledby="tax-rates">
          <UnifiedCard
            id="tax-rates"
            title={t('taxes.rates')}
            iconKey="rates"
            action={
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div></div>
                <button
                  type="button"
                  className="button-secondary gap-2"
                  disabled={free.length === 0}
                  onClick={() =>
                    form.setData('rates', [
                      ...form.data.rates,
                      { area_id: free[0].id, type: 'percent', value: '' },
                    ])
                  }
                >
                  <Icon name="plus" />
                  {t('taxes.add_rate')}
                </button>
              </div>
            }
            bodyClassName="p-5 space-y-4"
          >
            <p className="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
              {t('taxes.rates_help')}
            </p>

            {errors.rates && (
              <p role="alert" className="text-sm text-red-700">
                {errors.rates}
              </p>
            )}
            {areas.length === 0 && (
              <p className="text-sm text-amber-800">
                {t('taxes.no_areas')}
                <Link className="text-link" href="/admin/rate-areas">
                  {t('taxes.areas_title')}
                </Link>
              </p>
            )}
            {form.data.rates.length === 0 && areas.length > 0 && (
              <p className="text-sm text-slate-500">{t('taxes.no_rates')}</p>
            )}
            <ul className="space-y-3">
              {form.data.rates.map((rate, index) => (
                <li
                  key={index}
                  className="grid items-start gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
                >
                  <div className="space-y-2">
                    <label
                      htmlFor={'rate-area-' + index}
                      className="block text-sm font-medium"
                    >
                      {t('taxes.rate_area')}
                    </label>
                    <Select
                      id={'rate-area-' + index}
                      value={rate.area_id}
                      invalid={!!errors['rates.' + index + '.area_id']}
                      onChange={(value) => setRate(index, { area_id: value })}
                      options={areas
                        .filter((a) => a.id === rate.area_id || !used.has(a.id))
                        .map((a) => ({
                          value: a.id,
                          label: a.default
                            ? a.name + ' · ' + t('taxes.area_default')
                            : a.name,
                        }))}
                    />
                    {errors['rates.' + index + '.area_id'] && (
                      <p role="alert" className="text-sm text-red-700">
                        {errors['rates.' + index + '.area_id']}
                      </p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <label
                      htmlFor={'rate-type-' + index}
                      className="block text-sm font-medium"
                    >
                      {t('taxes.rate_type')}
                    </label>
                    <Select
                      id={'rate-type-' + index}
                      value={rate.type}
                      onChange={(value) =>
                        setRate(index, { type: value as 'percent' | 'fixed' })
                      }
                      options={[
                        { value: 'percent', label: t('taxes.rate_percent') },
                        { value: 'fixed', label: t('taxes.rate_fixed') },
                      ]}
                    />
                  </div>
                  <FormField
                    id={'rate-value-' + index}
                    label={
                      rate.type === 'percent'
                        ? t('taxes.rate_value_percent')
                        : t('taxes.rate_value_fixed', { code: currency.code })
                    }
                    inputMode="decimal"
                    value={rate.value}
                    error={errors['rates.' + index + '.value']}
                    onChange={(e) => setRate(index, { value: e.target.value })}
                  />
                  <div className="pt-7">
                    <button
                      type="button"
                      className="button-secondary"
                      aria-label={t('taxes.remove_rate')}
                      onClick={() =>
                        form.setData(
                          'rates',
                          form.data.rates.filter((_, i) => i !== index),
                        )
                      }
                    >
                      <Icon name="trash" className="size-4 text-red-600" />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          </UnifiedCard>
        </section>
      </form>
    </AdminLayout>
  );
}
