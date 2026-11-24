import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import ExportLink from '../../../components/admin/ExportLink';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import CountrySelect from '../../../components/admin/CountrySelect';
import FormField from '../../../components/auth/FormField';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import BulkBar from '../../../components/admin/list/BulkBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { RegionRow } from '../../../types/geography';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  regions: Page<RegionRow>;
  filters: Record<string, string>;
  countries: { code: string; name: string }[];
  defaultLocale: string;
};

type Draft = {
  country_code: string;
  code: string;
  names: Record<string, string>;
  status: 'active' | 'disabled';
  version?: number;
};

export default function RegionsIndex({
  regions,
  filters,
  countries,
  defaultLocale,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const manage = !!capabilities['regions.manage'];
  const country = filters.country ?? '';
  const countryChosen = new URLSearchParams(
    usePage().url.split('?')[1] ?? '',
  ).has('country');
  const apply = useListQuery('/admin/regions', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [picked, setPicked] = useState<string[]>([]);
  // `open` mounts the drawer; `shown` drives the slide so the browser animates from the closed position.
  const [open, setOpen] = useState(false);
  const [shown, setShown] = useState(false);
  const [editing, setEditing] = useState<RegionRow | null>(null);
  const codeField = useRef<HTMLInputElement>(null);
  const blank = (): Draft => ({
    country_code: country,
    code: '',
    names: Object.fromEntries(i18n.locales.map((l) => [l.code, ''])),
    status: 'active',
  });
  const [contentLocale, setContentLocale] = useState(
    i18n.locales.some((l) => l.code === i18n.locale)
      ? i18n.locale
      : defaultLocale,
  );
  const form = useForm<Draft>(blank());
  useDirtyForm(form);
  const filtered = ['search', 'status'].some((key) => filters[key]);
  const pageIds = regions.data.map((r) => r.id);
  // Only rows on the current page count, so a page or country change drops stale picks.
  const selected = picked.filter((id) => pageIds.includes(id));
  const setSelected = setPicked;
  const allSelected =
    pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

  const dismiss = () => {
    setShown(false);
    form.clearErrors();
    const instant = window.matchMedia?.(
      '(prefers-reduced-motion: reduce)',
    )?.matches;
    setTimeout(() => setOpen(false), instant ? 0 : 200);
  };

  useEffect(() => {
    if (!open) return;
    const frame = requestAnimationFrame(() => {
      setShown(true);
      codeField.current?.focus({ preventScroll: true });
    });
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') dismiss();
    };
    document.addEventListener('keydown', escape);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('keydown', escape);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const openCreate = () => {
    setEditing(null);
    form.setData(blank());
    setOpen(true);
  };
  const openEdit = (region: RegionRow) => {
    setEditing(region);
    form.setData({
      country_code: country,
      code: region.code,
      names: Object.fromEntries(
        i18n.locales.map((l) => [l.code, region.names[l.code] ?? '']),
      ),
      status: region.status,
      version: region.version,
    });
    setOpen(true);
  };
  const submit = () => {
    const options = {
      preserveScroll: true,
      onSuccess: () => dismiss(),
    };
    if (editing) form.put('/admin/regions/' + editing.id, options);
    else form.post('/admin/regions', options);
  };
  const remove = (region: RegionRow) => {
    if (
      !window.confirm(
        t('geography.region_delete_confirm', { name: region.name }),
      )
    )
      return;
    router.delete('/admin/regions/' + region.id, {
      data: { version: region.version },
      preserveScroll: true,
    });
  };
  const bulk = (status: 'active' | 'disabled') =>
    router.post(
      '/admin/regions/status',
      { ids: selected, status },
      { preserveScroll: true, onSuccess: () => setSelected([]) },
    );

  return (
    <AdminLayout title={t('geography.regions_title')} {...shell}>
      <EditorHeader
        heading={t('geography.regions_title')}
        iconKey="core.website"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('geography.regions_description')}
            </p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-3">
              <ExportLink
                entity="regions"
                filters={{
                  search: filters.search,
                  status: filters.status,
                  country: countryChosen ? filters.country : undefined,
                }}
              />
              {manage && (
                <Link
                  href="/admin/regions/import"
                  className="button-secondary px-3"
                  aria-label={t('region_import.title')}
                  title={t('region_import.title')}
                >
                  <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    fill="none"
                    className="size-6"
                  >
                    <path
                      d="M14 2H5a1 1 0 0 0-1 1v18a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V8l-6-6Z"
                      stroke="currentColor"
                      strokeWidth="1.5"
                      strokeLinejoin="round"
                    />
                    <path
                      d="M14 2v6h6"
                      stroke="currentColor"
                      strokeWidth="1.5"
                      strokeLinejoin="round"
                    />
                    <text
                      x="6"
                      y="17"
                      fill="currentColor"
                      fontSize="6"
                      fontWeight="700"
                    >
                      CSV
                    </text>
                  </svg>
                </Link>
              )}
              {manage && country && (
                <button
                  type="button"
                  className="button-primary gap-2"
                  onClick={openCreate}
                >
                  <Icon name="plus" />
                  {t('geography.region_add')}
                </button>
              )}
            </div>
          </>
        }
      />
      {countries.length === 0 ? (
        <p className="list-card p-8 text-center text-slate-500">
          {t('geography.regions_no_countries')}
        </p>
      ) : (
        <section
          className="list-card"
          aria-label={t('geography.regions_title')}
        >
          <FilterBar
            resetHref={'/admin/regions?country=' + country}
            active={filtered}
            search={
              <SearchField
                id="regions-search"
                value={search}
                placeholder={t('geography.region_search')}
                onChange={setSearch}
                onSubmit={() => apply({ search })}
              />
            }
            sort={
              <div className="w-64">
                <CountrySelect
                  id="regions-country"
                  aria-label={t('geography.region_country')}
                  value={country}
                  options={countries.map((c) => ({
                    value: c.code,
                    label: c.name,
                  }))}
                  onChange={(code) =>
                    apply({ country: code, search: '', status: '' })
                  }
                />
              </div>
            }
            chips={
              <FilterChip
                label={t('geography.region_status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('geography.region_active') },
                  { value: 'disabled', label: t('geography.region_disabled') },
                ]}
                onChange={(value) => apply({ status: value })}
              />
            }
          />
          {manage && selected.length > 0 && (
            <BulkBar count={selected.length} onClear={() => setSelected([])}>
              <button
                type="button"
                className="button-secondary"
                onClick={() => bulk('active')}
              >
                {t('geography.bulk_activate')}
              </button>
              <button
                type="button"
                className="button-secondary"
                onClick={() => bulk('disabled')}
              >
                {t('geography.bulk_disable')}
              </button>
            </BulkBar>
          )}
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  {manage && (
                    <th className="w-10">
                      <input
                        type="checkbox"
                        aria-label={t('geography.select_all')}
                        checked={allSelected}
                        onChange={() => setSelected(allSelected ? [] : pageIds)}
                      />
                    </th>
                  )}
                  <th>{t('geography.region_code')}</th>
                  <th>{t('geography.region_name')}</th>
                  <th>{t('geography.region_status')}</th>
                  {manage && (
                    <th className="text-right">{t('common.actions')}</th>
                  )}
                </tr>
              </thead>
              <tbody>
                {regions.data.length === 0 && (
                  <tr>
                    <td
                      colSpan={5}
                      className="py-10 text-center text-slate-500"
                    >
                      {t('geography.regions_empty')}
                    </td>
                  </tr>
                )}
                {regions.data.map((region) => (
                  <tr
                    key={region.id}
                    className={region.status === 'disabled' ? 'opacity-70' : ''}
                  >
                    {manage && (
                      <td>
                        <input
                          type="checkbox"
                          aria-label={t('geography.select_row', {
                            name: region.name,
                          })}
                          checked={selected.includes(region.id)}
                          onChange={() =>
                            setSelected((current) =>
                              current.includes(region.id)
                                ? current.filter((id) => id !== region.id)
                                : [...current, region.id],
                            )
                          }
                        />
                      </td>
                    )}
                    <td className="text-sm font-medium tabular-nums">
                      {region.code}
                    </td>
                    <td className="font-semibold">{region.name}</td>
                    <td>
                      <StatusBadge
                        tone={
                          region.status === 'active' ? 'success' : 'neutral'
                        }
                      >
                        {t(
                          region.status === 'active'
                            ? 'geography.region_active'
                            : 'geography.region_disabled',
                        )}
                      </StatusBadge>
                    </td>
                    {manage && (
                      <td>
                        <div className="flex justify-end gap-1">
                          <IconButton
                            icon="edit"
                            label={
                              t('geography.region_edit') + ': ' + region.name
                            }
                            onClick={() => openEdit(region)}
                          />
                          <IconButton
                            icon="trash"
                            danger
                            label={
                              t('geography.region_delete') + ': ' + region.name
                            }
                            onClick={() => remove(region)}
                          />
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={regions} />
        </section>
      )}
      {open && (
        <div className="fixed inset-0 z-50 flex justify-end">
          <button
            type="button"
            aria-label={t('geography.region_close')}
            className={`absolute inset-0 bg-slate-900/15 transition-opacity duration-200 ease-out motion-reduce:transition-none ${shown ? 'opacity-100' : 'opacity-0'}`}
            onClick={dismiss}
          />
          <form
            role="dialog"
            aria-modal="true"
            aria-labelledby="region-drawer-title"
            className={`relative flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl transition-transform duration-200 ease-out motion-reduce:transition-none ${shown ? 'translate-x-0' : 'translate-x-full'}`}
            onSubmit={(e) => {
              e.preventDefault();
              submit();
            }}
          >
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
              <h2 id="region-drawer-title" className="text-lg font-semibold">
                {t(editing ? 'geography.region_edit' : 'geography.region_add')}
              </h2>
              <button
                type="button"
                aria-label={t('geography.region_close')}
                className="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"
                onClick={dismiss}
              >
                <svg
                  aria-hidden="true"
                  viewBox="0 0 20 20"
                  className="size-4"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                >
                  <path d="m5 5 10 10M15 5 5 15" />
                </svg>
              </button>
            </header>
            <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
              <div className="space-y-2">
                <label
                  htmlFor="region-code"
                  className="block text-sm font-medium"
                >
                  {t('geography.region_code')}
                </label>
                <input
                  id="region-code"
                  ref={codeField}
                  className="field uppercase"
                  maxLength={10}
                  value={form.data.code}
                  aria-invalid={form.errors.code ? true : undefined}
                  onChange={(e) =>
                    form.setData('code', e.target.value.toUpperCase())
                  }
                />
                <p className="text-xs text-slate-500">
                  {t('geography.region_code_hint')}
                </p>
                {form.errors.code && (
                  <p role="alert" className="text-sm text-red-700">
                    {form.errors.code}
                  </p>
                )}
              </div>
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
                    id={'region-name-' + l.code}
                    label={t('geography.region_name') + ' · ' + l.code}
                    requiredIndicator={l.code === defaultLocale}
                    maxLength={120}
                    value={form.data.names[l.code] ?? ''}
                    error={
                      (form.errors as Record<string, string>)['names.' + l.code]
                    }
                    onChange={(e) =>
                      form.setData('names', {
                        ...form.data.names,
                        [l.code]: e.target.value,
                      })
                    }
                  />
                ))}
              <div className="space-y-2">
                <label
                  htmlFor="region-status"
                  className="block text-sm font-medium"
                >
                  {t('geography.region_status')}
                </label>
                <EditorStatusSelect
                  disabled={form.processing}
                  aria-label={t('common.status')}
                  id="region-status"
                  value={form.data.status}
                  options={[
                    { value: 'active', label: t('geography.region_active') },
                    {
                      value: 'disabled',
                      label: t('geography.region_disabled'),
                    },
                  ]}
                  onChange={(value) =>
                    form.setData('status', value as Draft['status'])
                  }
                />
              </div>
              {(form.errors.country_code ||
                (form.errors as Record<string, string>).country ||
                (form.errors as Record<string, string>).version) && (
                <p role="alert" className="text-sm text-red-700">
                  {form.errors.country_code ??
                    (form.errors as Record<string, string>).country ??
                    (form.errors as Record<string, string>).version}
                </p>
              )}
            </div>
            <footer className="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
              <button
                type="button"
                className="button-secondary"
                onClick={dismiss}
              >
                {t('common.cancel')}
              </button>
              <EditorActionButton disabled={form.processing}>
                {t('common.save')}
              </EditorActionButton>
            </footer>
          </form>
        </div>
      )}
    </AdminLayout>
  );
}
