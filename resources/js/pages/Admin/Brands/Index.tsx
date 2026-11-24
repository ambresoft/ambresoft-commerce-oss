import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import ExportLink from '../../../components/admin/ExportLink';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import Switch from '../../../components/admin/Switch';
import Pagination from '../../../components/admin/Pagination';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { BrandRow } from '../../../types/brands';
import type { SharedProps } from '../../../types/page';

export default function BrandIndex({
  brands,
  filters,
  ...shell
}: AdminShell & { brands: Page<BrandRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/brands', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [removing, setRemoving] = useState<BrandRow | null>(null);
  const allowed = !!capabilities['brands.manage'];
  return (
    <AdminLayout title={t('brands.title')} {...shell}>
      <EditorHeader
        heading={t('brands.title')}
        iconKey="brand"
        actions={
          <>
            <ExportLink entity="brands" filters={filters} />
            {capabilities['brands.import'] && (
              <Link
                href="/admin/brands/import"
                className="button-secondary gap-2"
              >
                <Icon name="upload" />
                {t('brand_import.title')}
              </Link>
            )}
            {allowed && (
              <Link className="button-primary" href="/admin/brands/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('brands.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <section className="list-card" aria-label={t('brands.title')}>
        <FilterBar
          resetHref="/admin/brands"
          active={!!(filters.search || filters.status)}
          search={
            <SearchField
              id="brands-search"
              value={search}
              onChange={setSearch}
              placeholder={t('brands.search')}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <FilterChip
              label={t('common.status')}
              value={filters.status ?? ''}
              options={[
                { value: '', label: t('common.all') },
                { value: 'active', label: t('common.active') },
                { value: 'disabled', label: t('common.inactive') },
              ]}
              onChange={(status) => apply({ status })}
            />
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'name',
                  'code',
                  'position',
                  'products_count',
                  'status',
                  'updated_at',
                ].map((key) => (
                  <th key={key}>
                    <button
                      type="button"
                      onClick={() =>
                        apply({
                          sort: key,
                          direction:
                            filters.sort === key && filters.direction !== 'desc'
                              ? 'desc'
                              : 'asc',
                        })
                      }
                    >
                      {t(`brands.${key}`)}
                    </button>
                  </th>
                ))}
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {brands.data.map((brand) => (
                <tr key={brand.id}>
                  <td>
                    <Link
                      href={`/admin/brands/${brand.id}/edit`}
                      className="flex items-center gap-3 font-medium"
                    >
                      {brand.image && (
                        <img
                          src={brand.image}
                          alt=""
                          className="size-10 rounded-md object-contain"
                        />
                      )}
                      {brand.name}
                    </Link>
                  </td>
                  <td>{brand.code}</td>
                  <td>{brand.position}</td>
                  <td>
                    <Link
                      className="text-link"
                      href={`/admin/products?brand_id=${brand.id}`}
                    >
                      {brand.products_count}
                    </Link>
                  </td>
                  <td>
                    <StatusBadge
                      tone={brand.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        brand.status === 'active'
                          ? 'common.active'
                          : 'common.inactive',
                      )}
                    </StatusBadge>
                  </td>
                  <td>{brand.updated_at?.slice(0, 16).replace('T', ' ')}</td>
                  <td>
                    <div className="flex items-center justify-end gap-2">
                      {allowed && (
                        <>
                          <IconButton
                            icon="trash"
                            danger
                            label={t('brands.delete_title', {
                              name: brand.name,
                            })}
                            onClick={() => setRemoving(brand)}
                          />
                          <IconButton
                            icon="edit"
                            label={t('brands.edit_title', { name: brand.name })}
                            href={`/admin/brands/${brand.id}/edit`}
                          />
                          <Switch
                            checked={brand.status === 'active'}
                            label={t('brands.toggle', { name: brand.name })}
                            onChange={(enabled) =>
                              router.patch(
                                `/admin/brands/${brand.id}/status`,
                                {
                                  version: brand.version,
                                  status: enabled ? 'active' : 'disabled',
                                },
                                { preserveScroll: true },
                              )
                            }
                          />
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {brands.data.length === 0 && (
            <p className="p-8 text-center text-sm text-slate-500">
              {t('common.no_results')}
            </p>
          )}
        </div>
        <Pagination
          page={brands}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </section>
      {removing && (
        <ConfirmDeleteDialog
          title={t('brands.delete_title', { name: removing.name })}
          facts={[
            t('brands.assigned_products', { count: removing.products_count }),
            t('brands.delete_used'),
          ]}
          onCancel={() => setRemoving(null)}
          onArchive={() => {
            router.patch(`/admin/brands/${removing.id}/status`, {
              version: removing.version,
              status: 'disabled',
            });
            setRemoving(null);
          }}
          onConfirm={() =>
            router.delete(`/admin/brands/${removing.id}`, {
              data: { version: removing.version },
              onFinish: () => setRemoving(null),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
