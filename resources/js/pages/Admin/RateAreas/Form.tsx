import UnifiedCard from '../../../components/admin/AdminCard';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import HelpPopover from '../../../components/admin/HelpPopover';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { CountryOption, RateAreaEditor } from '../../../types/taxes';

type Props = AdminShell & {
  area: RateAreaEditor | null;
  countries: CountryOption[];
  defaultLocale: string;
};

export default function RateAreaForm({
  area,
  countries,
  defaultLocale,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const [filter, setFilter] = useState('');
  const [contentLocale, setContentLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : defaultLocale,
  );
  const form = useForm({
    version: area?.version,
    names: Object.fromEntries(
      i18n.locales.map((l) => [l.code, area?.names[l.code] ?? '']),
    ),
    status: area?.status ?? 'active',
    is_default: area?.is_default ?? false,
    countries: area?.countries ?? ([] as string[]),
    // Editable text per country; split into codes on submit.
    regions: Object.fromEntries(
      Object.entries(area?.regions ?? {}).map(([code, list]) => [
        code,
        list.join(', '),
      ]),
    ) as Record<string, string>,
  });
  useDirtyForm(form);
  const errors = form.errors as Record<string, string>;
  const selected = new Set(form.data.countries);
  const visible = countries.filter(
    (c) =>
      filter === '' ||
      c.name.toLowerCase().includes(filter.toLowerCase()) ||
      c.code.toLowerCase() === filter.toLowerCase(),
  );
  const toggle = (code: string, on: boolean) => {
    const next = on
      ? [...form.data.countries, code]
      : form.data.countries.filter((c) => c !== code);
    const regions = { ...form.data.regions };
    if (!on) delete regions[code];
    form.setData({ ...form.data, countries: next, regions });
  };
  const submit = () => {
    form.transform((data) => ({
      ...data,
      is_default: data.is_default,
      countries: data.is_default ? [] : data.countries,
      regions: Object.fromEntries(
        Object.entries(data.regions)
          .filter(([code]) => !data.is_default && data.countries.includes(code))
          .map(([code, text]) => [
            code,
            text
              .split(/[\s,;]+/)
              .map((r) => r.trim().toUpperCase())
              .filter(Boolean),
          ])
          .filter(([, list]) => list.length > 0),
      ),
    }));
    if (area === null) form.post('/admin/rate-areas');
    else form.put('/admin/rate-areas/' + area.id);
  };
  const title = area === null ? t('taxes.area_create') : t('taxes.area_edit');
  const nameOf = (code: string) =>
    countries.find((c) => c.code === code)?.name ?? code;
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <div className="flex items-center gap-2">
              <EditorStatusSelect
                disabled={form.processing}
                aria-label={t('common.status')}
                id="area-status"
                value={form.data.status}
                onChange={(value) =>
                  form.setData('status', value as 'active' | 'disabled')
                }
                options={[
                  { value: 'active', label: t('taxes.active') },
                  { value: 'disabled', label: t('taxes.disabled') },
                ]}
              />
              <HelpPopover label={t('common.status')}>
                {t('taxes.area_status_hint')}
              </HelpPopover>
            </div>
          </>
        }
        heading={title}
        iconKey="core.website"
        backHref="/admin/rate-areas"
        backLabel={t('taxes.areas_title')}
        actions={
          capabilities['rate-areas.manage'] && (
            <>
              <div className="flex gap-3">
                <EditorActionButton
                  disabled={form.processing}
                  form="admin-rateareas-form-0"
                >
                  {t(form.processing ? 'common.wait' : 'common.save')}
                </EditorActionButton>
                <Link className="button-secondary" href="/admin/rate-areas">
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
        id="admin-rateareas-form-0"
      >
        <FormErrors
          errors={Object.fromEntries(
            Object.entries(errors).filter(
              ([key]) => !/^(names|status|countries|regions)/.test(key),
            ),
          )}
        />
        <section>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
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
                    id={'area-name-' + l.code}
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
            {errors.names && (
              <p role="alert" className="text-sm text-red-700">
                {errors.names}
              </p>
            )}

            <label className="flex items-start gap-3 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={form.data.is_default}
                onChange={(e) => form.setData('is_default', e.target.checked)}
              />
              <span>
                <span className="font-medium">{t('taxes.area_default')}</span>
                <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                  {t('taxes.area_default_help')}
                </span>
              </span>
            </label>
            {errors.is_default && (
              <p role="alert" className="text-sm text-red-700">
                {errors.is_default}
              </p>
            )}
          </UnifiedCard>
        </section>
        {!form.data.is_default && (
          <section aria-labelledby="area-countries">
            <UnifiedCard
              id="area-countries"
              title={t('taxes.area_countries')}
              iconKey="area_countries"
              bodyClassName="p-5 space-y-4"
            >
              <p className="mt-1 text-xs leading-5 text-slate-500">
                {t('taxes.area_countries_help')}
              </p>

              {(errors.countries || errors.regions) && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.countries ?? errors.regions}
                </p>
              )}
              <input
                type="search"
                className="field"
                aria-label={t('taxes.area_search_countries')}
                placeholder={t('taxes.area_search_countries')}
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
              />
              <ul className="grid max-h-72 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-2">
                {visible.map((c) => (
                  <li key={c.code}>
                    <label className="flex items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50">
                      <input
                        type="checkbox"
                        className="size-4"
                        checked={selected.has(c.code)}
                        onChange={(e) => toggle(c.code, e.target.checked)}
                      />
                      <span>{c.name}</span>
                      <span className="ml-auto font-mono text-xs text-slate-500">
                        {c.code}
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
              <p className="text-xs text-slate-500">
                {t('taxes.area_selected', {
                  count: form.data.countries.length,
                })}
              </p>
              {form.data.countries.length > 0 && (
                <div className="space-y-3 border-t border-slate-100 pt-4">
                  <div>
                    <h3 className="text-sm font-semibold">
                      {t('taxes.area_regions')}
                    </h3>
                    <p className="mt-1 text-xs leading-5 text-slate-500">
                      {t('taxes.area_regions_help')}
                    </p>
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    {form.data.countries.map((code) => (
                      <div key={code} className="space-y-2">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                          <label
                            htmlFor={'regions-' + code}
                            className="text-sm font-medium text-slate-800"
                          >
                            {nameOf(code) + ' (' + code + ')'}
                          </label>
                          <span className="group relative inline-flex">
                            <button
                              type="button"
                              aria-label={t('taxes.area_regions_tip_label', {
                                country: nameOf(code),
                              })}
                              aria-describedby={'regions-tip-' + code}
                              className="flex size-5 items-center justify-center rounded-full border border-slate-300 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-primary"
                            >
                              ?
                            </button>
                            <span
                              id={'regions-tip-' + code}
                              role="tooltip"
                              className="pointer-events-none invisible absolute bottom-full left-0 z-20 mb-2 w-72 rounded-lg bg-slate-900 p-3 text-xs leading-5 text-white opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
                            >
                              {t('taxes.area_regions_tip', { code })}
                            </span>
                          </span>
                          <a
                            href={
                              'https://en.wikipedia.org/wiki/ISO_3166-2:' + code
                            }
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-xs text-slate-600 underline underline-offset-4 hover:text-slate-900"
                          >
                            {t('taxes.area_regions_reference', { code })}
                            <span aria-hidden="true"> ↗</span>
                          </a>
                        </div>
                        <input
                          id={'regions-' + code}
                          className="field"
                          placeholder={t(
                            code === 'GR'
                              ? 'taxes.area_regions_placeholder_gr'
                              : 'taxes.area_regions_placeholder',
                          )}
                          value={form.data.regions[code] ?? ''}
                          aria-invalid={
                            errors['regions.' + code] ? true : undefined
                          }
                          onChange={(e) =>
                            form.setData('regions', {
                              ...form.data.regions,
                              [code]: e.target.value,
                            })
                          }
                        />
                        {errors['regions.' + code] && (
                          <p role="alert" className="text-sm text-red-700">
                            {errors['regions.' + code]}
                          </p>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </UnifiedCard>
          </section>
        )}
      </form>
    </AdminLayout>
  );
}
