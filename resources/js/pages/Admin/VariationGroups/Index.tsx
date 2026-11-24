import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import { Icon } from '../../../components/admin/list/IconButton';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { VariationGroupList } from '../../../types/variations';

type Option = { id: string; name: string };

export default function VariationGroupsIndex({
  groups,
  filters,
  features,
  categories,
  ...shell
}: AdminShell & {
  groups: VariationGroupList;
  filters: Record<string, string>;
  features: Option[];
  categories: Option[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/variation-groups', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const all = { value: '', label: t('common.all') };
  const filtered = [
    'search',
    'category_id',
    'type',
    'feature_id',
    'members',
  ].some((key) => filters[key]);
  const title = t('variations.title');
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="variations"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('variations.description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['products.update'] && (
              <Link
                className="button-primary gap-2"
                href="/admin/variation-groups/create"
              >
                <Icon name="plus" />
                {t('variations.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={title}>
        <FilterBar
          resetHref="/admin/variation-groups"
          active={filtered}
          search={
            <SearchField
              id="variation-group-search"
              value={search}
              placeholder={t('variations.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'id'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={['id', 'name', 'members'].map((value) => ({
                value,
                label: t(`variations.sort_${value}`),
              }))}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('products.categories')}
                value={filters.category_id ?? ''}
                options={[
                  all,
                  ...categories.map((c) => ({ value: c.id, label: c.name })),
                ]}
                onChange={(value) => apply({ category_id: value })}
              />
              <FilterChip
                label={t('products.type')}
                value={filters.type ?? ''}
                options={[
                  all,
                  ...['physical', 'digital'].map((value) => ({
                    value,
                    label: t('products.' + value),
                  })),
                ]}
                onChange={(value) => apply({ type: value })}
              />
              <FilterChip
                label={t('variations.defining_feature')}
                value={filters.feature_id ?? ''}
                options={[
                  all,
                  ...features.map((f) => ({ value: f.id, label: f.name })),
                ]}
                onChange={(value) => apply({ feature_id: value })}
              />
              <FilterChip
                label={t('variations.members')}
                value={filters.members ?? ''}
                options={[
                  all,
                  ...['single', 'multiple'].map((value) => ({
                    value,
                    label: t(`variations.members_${value}`),
                  })),
                ]}
                onChange={(value) => apply({ members: value })}
              />
            </>
          }
        />
        <div className="overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('variations.id')}</th>
                <th>{t('variations.name')}</th>
                <th>{t('products.categories')}</th>
                <th>{t('variations.defining_features')}</th>
                <th className="text-right">{t('variations.members')}</th>
              </tr>
            </thead>
            <tbody>
              {groups.data.map((group) => (
                <tr key={group.id}>
                  <td className="text-slate-500 tabular-nums">#{group.id}</td>
                  <td className="min-w-48">
                    <Link
                      className="font-semibold text-slate-900 hover:underline"
                      href={'/admin/variation-groups/' + group.id}
                    >
                      {group.name}
                    </Link>
                    {group.type && (
                      <p className="mt-0.5 text-xs text-slate-500">
                        {t('products.' + group.type)}
                      </p>
                    )}
                  </td>
                  <td className="min-w-48 text-slate-500">
                    {group.category.join(' › ') || '—'}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-1.5">
                      {group.features.map((name) => (
                        <span
                          key={name}
                          className="rounded-full border border-slate-200 px-2.5 py-0.5 text-xs text-slate-600"
                        >
                          {name}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="text-right font-semibold tabular-nums">
                    {group.members}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {groups.data.length === 0 && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t(filtered ? 'common.no_results' : 'variations.empty')}
            </p>
          )}
        </div>
        <Pagination
          page={groups}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (value) => apply({ per_page: value }),
          }}
        />
      </section>
    </AdminLayout>
  );
}
