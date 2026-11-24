import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import {
  featureTypes,
  type Feature,
  type FeatureGroup,
} from '../../../types/features';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
export default function Index({
  features,
  filters,
  groups,
  categories,
  remediationCount,
  ...shell
}: AdminShell & {
  features: Page<Feature>;
  filters: Record<string, string>;
  groups: FeatureGroup[];
  categories: { id: string; name: string }[];
  remediationCount: number;
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/features', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = Object.entries(filters).some(
    ([key, value]) => key !== 'page' && value,
  );
  const chip = (field: string, options: { id: string; name: string }[]) => (
    <FilterChip
      key={field}
      label={t('features.' + field)}
      value={filters[field] ?? ''}
      options={[
        { value: '', label: t('features.all') },
        ...options.map((o) => ({ value: o.id, label: o.name })),
      ]}
      onChange={(value) => apply({ [field]: value })}
    />
  );
  const yesNo = ['1', '0'].map((id) => ({
    id,
    name: t('features.' + (id === '1' ? 'yes' : 'no')),
  }));
  return (
    <AdminLayout title={t('features.title')} {...shell}>
      <EditorHeader
        heading={t('features.title')}
        iconKey="features"
        actions={
          <>
            <div className="flex flex-wrap gap-2">
              {capabilities['features.groups'] && (
                <Link
                  className="button-secondary"
                  href="/admin/features/groups"
                >
                  {t('features.groups')}
                </Link>
              )}
              {capabilities['features.order'] && (
                <Link
                  className="button-secondary"
                  href="/admin/features/ordering"
                >
                  {t('features.ordering')}
                </Link>
              )}
              {capabilities['features.create'] && (
                <Link className="button-primary" href="/admin/features/create">
                  <Icon name="plus" className="size-5 shrink-0" />
                  {t('features.create')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <p className="mb-5 text-sm text-slate-500">
        {t('features.remediation_count', { count: remediationCount })}{' '}
        {capabilities['products.view'] && (
          <Link className="text-link" href="/admin/features/remediation">
            {t('features.review')}
          </Link>
        )}
      </p>
      <section className="list-card" aria-label={t('features.title')}>
        <FilterBar
          resetHref="/admin/features"
          active={filtered}
          search={
            <SearchField
              id="feature-search"
              value={search}
              placeholder={t('common.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              {chip(
                'type',
                featureTypes.map((id) => ({
                  id,
                  name: t(`features.type_${id}`),
                })),
              )}
              {chip('group_id', groups)}
              {chip('category_id', categories)}
              {chip(
                'status',
                ['active', 'archived', 'incomplete'].map((id) => ({
                  id,
                  name: t('features.' + id),
                })),
              )}
              {chip('required', yesNo)}
              {chip('filterable', yesNo)}
            </>
          }
        />
        <div className="overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('features.name')}</th>
                <th>{t('features.type')}</th>
                <th>{t('features.status')}</th>
                <th>{t('features.required')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {features.data.map((f) => (
                <tr key={f.id}>
                  <td>
                    {capabilities['features.update'] ? (
                      <Link
                        className="font-semibold text-slate-900 hover:underline"
                        href={'/admin/features/' + f.id + '/edit'}
                      >
                        {f.name || f.code}
                      </Link>
                    ) : (
                      <span className="font-semibold">{f.name || f.code}</span>
                    )}
                    <p className="mt-0.5 font-mono text-xs text-slate-500">
                      {f.code}
                    </p>
                  </td>
                  <td className="text-slate-500">
                    {t(`features.type_${f.type}`)}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-1.5">
                      <StatusBadge
                        tone={f.status === 'active' ? 'success' : 'neutral'}
                      >
                        {t('features.' + f.status)}
                      </StatusBadge>
                      {f.incomplete && (
                        <StatusBadge tone="warning">
                          {t('features.incomplete')}
                        </StatusBadge>
                      )}
                    </div>
                  </td>
                  <td className="text-slate-500">
                    {t('features.' + (f.required ? 'yes' : 'no'))}
                  </td>
                  <td>
                    <div className="flex justify-end">
                      {capabilities['features.update'] && (
                        <IconButton
                          icon="edit"
                          href={'/admin/features/' + f.id + '/edit'}
                          label={t('common.edit') + ': ' + (f.name || f.code)}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {features.data.length === 0 && (
            <p className="p-12 text-center text-sm text-slate-500">
              {t('features.empty')}
            </p>
          )}
        </div>
        <Pagination page={features} />
      </section>
    </AdminLayout>
  );
}
