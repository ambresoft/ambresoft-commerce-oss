import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { CountryRow } from '../../../types/geography';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  countries: Page<CountryRow>;
  filters: Record<string, string>;
  shippingTotal: number;
};

export default function CountriesIndex({
  countries,
  filters,
  shippingTotal,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['countries.manage'];
  const canRegions = !!capabilities['regions.view'];
  const apply = useListQuery('/admin/countries', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'shipping'].some((key) => filters[key]);

  const toggle = (country: CountryRow) =>
    router.put(
      '/admin/countries/' + country.code,
      { shipping: !country.shipping },
      { preserveScroll: true },
    );

  return (
    <AdminLayout title={t('geography.countries_title')} {...shell}>
      <EditorHeader
        heading={t('geography.countries_title')}
        iconKey="core.website"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('geography.countries_description')}
            </p>
            <p className="mt-2 text-sm font-medium text-slate-700">
              {t('geography.shipping_summary', { count: shippingTotal })}
            </p>
          </>
        }
      />
      <section
        className="list-card"
        aria-label={t('geography.countries_title')}
      >
        <FilterBar
          resetHref="/admin/countries"
          active={filtered}
          search={
            <SearchField
              id="countries-search"
              value={search}
              placeholder={t('geography.countries_search')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <FilterChip
              label={t('geography.shipping_filter')}
              value={filters.shipping ?? ''}
              options={[
                { value: '', label: t('common.all') },
                { value: 'yes', label: t('geography.shipping_yes') },
                { value: 'no', label: t('geography.shipping_no') },
              ]}
              onChange={(value) => apply({ shipping: value })}
            />
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('geography.country')}</th>
                <th>{t('geography.country_code')}</th>
                <th>{t('geography.regions_count')}</th>
                <th>{t('geography.country_shipping')}</th>
              </tr>
            </thead>
            <tbody>
              {countries.data.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-10 text-center text-slate-500">
                    {t('geography.countries_empty')}
                  </td>
                </tr>
              )}
              {countries.data.map((country) => (
                <tr key={country.code}>
                  <td className="font-semibold">{country.name}</td>
                  <td className="text-sm text-slate-600">{country.code}</td>
                  <td className="text-sm">
                    {country.regions > 0 && canRegions && country.shipping ? (
                      <Link
                        className="underline underline-offset-4"
                        href={'/admin/regions?country=' + country.code}
                        aria-label={
                          t('geography.manage_regions') + ': ' + country.name
                        }
                      >
                        {country.regions}
                      </Link>
                    ) : (
                      country.regions
                    )}
                  </td>
                  <td>
                    {manage ? (
                      <label className="inline-flex cursor-pointer items-center gap-3">
                        <input
                          type="checkbox"
                          role="switch"
                          className="size-4 accent-[var(--color-primary)]"
                          checked={country.shipping}
                          aria-label={t('geography.shipping_toggle', {
                            country: country.name,
                          })}
                          onChange={() => toggle(country)}
                        />
                        <StatusBadge
                          tone={country.shipping ? 'success' : 'neutral'}
                        >
                          {t(
                            country.shipping
                              ? 'geography.shipping_yes'
                              : 'geography.shipping_no',
                          )}
                        </StatusBadge>
                      </label>
                    ) : (
                      <StatusBadge
                        tone={country.shipping ? 'success' : 'neutral'}
                      >
                        {t(
                          country.shipping
                            ? 'geography.shipping_yes'
                            : 'geography.shipping_no',
                        )}
                      </StatusBadge>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={countries} />
      </section>
    </AdminLayout>
  );
}
