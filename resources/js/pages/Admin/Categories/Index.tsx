import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import ExportLink from '../../../components/admin/ExportLink';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import CategoryTree from '../../../components/admin/CategoryTree';
import CategoryActions from '../../../components/admin/CategoryActions';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  CategoryBranch,
  CategoryResults,
} from '../../../types/categories';
import type { SharedProps } from '../../../types/page';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';

type Props = AdminShell & {
  branch: CategoryBranch;
  results: CategoryResults | null;
  filtered: boolean;
  filters: { search?: string; status?: string };
};
export default function CategoriesIndex({
  branch,
  results,
  filtered,
  filters,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities, i18n } = usePage<SharedProps>().props;
  const errors =
    usePage<SharedProps & { errors?: Record<string, string> }>().props.errors ??
    {};
  const apply = useListQuery('/admin/categories', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  return (
    <AdminLayout title={t('categories.title')} {...shell}>
      <EditorHeader
        heading={t('categories.title')}
        iconKey="categories"
        description={
          <>
            <p className="mt-2 max-w-2xl text-sm text-slate-500">
              {t('categories.intro')}
            </p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-3">
              <ExportLink entity="categories" filters={filters} />
              {capabilities['categories.import'] && (
                <Link
                  href="/admin/categories/import"
                  className="button-secondary px-3"
                  aria-label={t('category_import.title')}
                  title={t('category_import.title')}
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
              {capabilities['categories.create'] && (
                <Link
                  href="/admin/categories/create"
                  className="button-primary"
                >
                  <Icon name="plus" className="size-5 shrink-0" />
                  {t('categories.create')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <section
        className="list-card mb-6"
        aria-label={t(
          filtered ? 'categories.search_results' : 'common.filters',
        )}
      >
        <FilterBar
          resetHref="/admin/categories"
          active={filtered}
          search={
            <>
              <SearchField
                id="search"
                value={search}
                placeholder={t('common.search_placeholder')}
                onChange={setSearch}
                onSubmit={() => apply({ search, parent: undefined })}
              />
              <p id="search-hint" className="mt-1.5 text-xs text-slate-500">
                {t('categories.search_hint')}
              </p>
            </>
          }
          chips={
            <FilterChip
              label={t('common.status')}
              value={filters.status ?? ''}
              options={[
                { value: '', label: t('common.all') },
                { value: 'active', label: t('categories.active') },
                { value: 'disabled', label: t('categories.disabled') },
              ]}
              onChange={(value) => apply({ status: value, parent: undefined })}
            />
          }
        />
        {Object.keys(errors).length > 0 && (
          <div className="px-4 pb-4 sm:px-5">
            <FormErrors errors={errors} />
          </div>
        )}
        {filtered && results && (
          <>
            <div className="overflow-x-auto border-t border-slate-200">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('common.name')}</th>
                    <th>{t('common.status')}</th>
                    <th>{t('categories.position')}</th>
                    <th className="text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {results.data.map((category) => (
                    <tr key={category.id}>
                      <td className="min-w-56">
                        <p className="font-semibold">{category.name}</p>
                        <p className="mt-0.5 text-xs text-slate-500">
                          {category.path?.join(' › ')}
                        </p>
                        {category.translationState === 'fallback' && (
                          <p className="mt-0.5 text-xs text-amber-700">
                            {t('categories.fallback', {
                              locale: category.resolvedLocale ?? '—',
                            })}
                          </p>
                        )}
                      </td>
                      <td>
                        <StatusBadge
                          tone={
                            category.status === 'active' ? 'success' : 'neutral'
                          }
                        >
                          {t('categories.' + category.status)}
                        </StatusBadge>
                      </td>
                      <td className="text-slate-500 tabular-nums">
                        {category.position}
                      </td>
                      <td>
                        <div className="flex justify-end">
                          <CategoryActions category={category} />
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!results.data.length && (
                <p className="p-12 text-center text-sm text-slate-500">
                  {t('common.no_results')}
                </p>
              )}
            </div>
            <Pagination page={results} />
          </>
        )}
      </section>
      {!filtered && <CategoryTree key={i18n.locale} initialBranch={branch} />}
    </AdminLayout>
  );
}
