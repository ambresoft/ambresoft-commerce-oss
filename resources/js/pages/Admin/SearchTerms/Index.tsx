import EditorHeader from '../../../components/admin/EditorHeader';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import type { AdminShell, Pagination as Page } from '../../../types/admin';

type TermRow = {
  id: string;
  term: string;
  searches: number;
  results: number;
  hidden: boolean;
  last_searched_at: string;
};

/** Reports → Popular searches: what shoppers search for; the switch keeps a term out of the Popular searches block. */
export default function SearchTermsIndex({
  terms,
  filters,
  ...shell
}: AdminShell & { terms: Page<TermRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const apply = useListQuery('/admin/search-terms', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const filtered = ['search', 'visibility', 'results'].some(
    (key) => filters[key],
  );
  return (
    <AdminLayout title={t('search.terms_title')} {...shell}>
      <EditorHeader
        heading={t('search.terms_title')}
        iconKey="seo"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('search.terms_intro')}
            </p>
          </>
        }
      />
      <section className="list-card" aria-label={t('search.terms_title')}>
        <FilterBar
          resetHref="/admin/search-terms"
          active={filtered}
          search={
            <SearchField
              id="term-search"
              value={search}
              onChange={setSearch}
              placeholder={t('search.terms_search')}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'searches'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={['searches', 'last_searched_at', 'term'].map(
                (value) => ({ value, label: t(`search.terms_sort_${value}`) }),
              )}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('search.terms_in_block')}
                value={filters.visibility ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'shown', label: t('search.terms_shown') },
                  { value: 'hidden', label: t('search.terms_hidden') },
                ]}
                onChange={(visibility) => apply({ visibility })}
              />
              <FilterChip
                label={t('search.terms_results')}
                value={filters.results ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'with', label: t('search.terms_with_results') },
                  { value: 'none', label: t('search.terms_no_results') },
                ]}
                onChange={(results) => apply({ results })}
              />
            </>
          }
        />
        <div className="relative max-w-full overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('search.terms_term')}</th>
                <th>{t('search.terms_searches')}</th>
                <th>{t('search.terms_results')}</th>
                <th>{t('search.terms_last')}</th>
                <th>{t('search.terms_in_block')}</th>
              </tr>
            </thead>
            <tbody>
              {terms.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm font-medium">
                    <a
                      href={'/search?q=' + encodeURIComponent(row.term)}
                      target="_blank"
                      rel="noreferrer"
                      className="hover:underline"
                    >
                      {row.term}
                    </a>
                  </td>
                  <td className="text-sm tabular-nums">{row.searches}</td>
                  <td className="text-sm tabular-nums">
                    {row.results > 0 ? (
                      row.results
                    ) : (
                      <StatusBadge tone="warning">
                        {t('search.terms_no_results')}
                      </StatusBadge>
                    )}
                  </td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {when(row.last_searched_at)}
                  </td>
                  <td>
                    <Switch
                      checked={!row.hidden}
                      label={t('search.terms_toggle', { term: row.term })}
                      onChange={(shown) =>
                        router.patch(
                          `/admin/search-terms/${row.id}/visibility`,
                          { hidden: !shown },
                          { preserveScroll: true },
                        )
                      }
                    />
                  </td>
                </tr>
              ))}
              {terms.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-slate-500">
                    {t(
                      filtered
                        ? 'search.terms_empty_filtered'
                        : 'search.terms_empty',
                    )}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination
          page={terms}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </section>
    </AdminLayout>
  );
}
