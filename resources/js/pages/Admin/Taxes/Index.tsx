import EditorHeader from '../../../components/admin/EditorHeader';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { ProductTaxOption, TaxRow } from '../../../types/taxes';

type Props = AdminShell & {
  taxes: Page<TaxRow>;
  filters: Record<string, string>;
  assignable: ProductTaxOption[];
  productCount: number;
};

export default function TaxesIndex({
  taxes,
  filters,
  assignable,
  productCount,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/taxes', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'status'].some((key) => filters[key]);
  const assign = useForm({
    tax_ids: [] as string[],
    confirmed: false,
  });
  useDirtyForm(assign);
  const remove = (tax: TaxRow) => {
    if (!window.confirm(t('taxes.delete_confirm', { name: tax.name }))) return;
    router.delete('/admin/taxes/' + tax.id, {
      data: { version: tax.version },
      preserveScroll: true,
    });
  };
  return (
    <AdminLayout title={t('taxes.title')} {...shell}>
      <EditorHeader
        heading={t('taxes.title')}
        iconKey="taxes"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('taxes.description')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['taxes.create'] && (
              <Link className="button-primary gap-2" href="/admin/taxes/create">
                <Icon name="plus" />
                {t('taxes.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('taxes.title')}>
        <FilterBar
          resetHref="/admin/taxes"
          active={filtered}
          search={
            <SearchField
              id="tax-search"
              value={search}
              placeholder={t('taxes.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'priority'}
              direction={filters.direction === 'desc' ? 'desc' : 'asc'}
              options={[
                { value: 'priority', label: t('taxes.sort_priority') },
                { value: 'name', label: t('taxes.sort_name') },
                { value: 'status', label: t('taxes.sort_status') },
              ]}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <FilterChip
              label={t('common.status')}
              value={filters.status ?? ''}
              options={[
                { value: '', label: t('common.all') },
                { value: 'active', label: t('taxes.active') },
                { value: 'disabled', label: t('taxes.disabled') },
              ]}
              onChange={(value) => apply({ status: value })}
            />
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th className="text-right">{t('taxes.priority')}</th>
                <th>{t('common.status')}</th>
                <th>{t('taxes.price_mode')}</th>
                <th>{t('taxes.address_basis')}</th>
                <th>{t('taxes.rates')}</th>
                <th className="text-right">{t('taxes.products')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {taxes.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'taxes.no_matches' : 'taxes.empty')}
                  </td>
                </tr>
              )}
              {taxes.data.map((tax) => (
                <tr key={tax.id}>
                  <td>
                    {capabilities['taxes.view'] ? (
                      <Link
                        className="font-semibold hover:underline"
                        href={'/admin/taxes/' + tax.id + '/edit'}
                      >
                        {tax.name}
                      </Link>
                    ) : (
                      <span className="font-semibold">{tax.name}</span>
                    )}
                    {tax.registration_number && (
                      <p className="mt-0.5 font-mono text-xs text-slate-500">
                        {tax.registration_number}
                      </p>
                    )}
                  </td>
                  <td className="text-right tabular-nums">{tax.priority}</td>
                  <td>
                    <StatusBadge
                      tone={tax.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        tax.status === 'active'
                          ? 'taxes.active'
                          : 'taxes.disabled',
                      )}
                    </StatusBadge>
                  </td>
                  <td>
                    {t(
                      tax.price_includes_tax
                        ? 'taxes.price_included'
                        : 'taxes.price_added',
                    )}
                  </td>
                  <td>
                    {t(
                      tax.address_basis === 'billing'
                        ? 'taxes.basis_billing'
                        : 'taxes.basis_shipping',
                    )}
                  </td>
                  <td>
                    {tax.rates.length === 0 ? (
                      <span className="text-slate-500">
                        {t('taxes.no_rates')}
                      </span>
                    ) : (
                      <ul className="space-y-0.5 text-sm">
                        {tax.rates.map((rate) => (
                          <li key={rate.area}>
                            <span className="text-slate-500">{rate.area}:</span>{' '}
                            <span className="font-medium tabular-nums">
                              {rate.label}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </td>
                  <td className="text-right tabular-nums">{tax.products}</td>
                  <td>
                    <div className="flex justify-end gap-1">
                      {capabilities['taxes.view'] && (
                        <IconButton
                          icon="edit"
                          label={t('common.edit') + ': ' + tax.name}
                          href={'/admin/taxes/' + tax.id + '/edit'}
                        />
                      )}
                      {capabilities['taxes.delete'] && (
                        <IconButton
                          icon="trash"
                          danger
                          disabled={tax.products > 0}
                          label={
                            (tax.products > 0
                              ? t('taxes.delete_assigned_hint')
                              : t('taxes.delete')) +
                            ': ' +
                            tax.name
                          }
                          onClick={() => remove(tax)}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={taxes} />
      </section>
      {assignable.length > 0 && (
        <section
          aria-labelledby="assign-taxes"
          className="mt-6 w-full space-y-4 rounded-xl border border-slate-200 bg-white p-5"
        >
          <div>
            <h2 id="assign-taxes" className="text-base font-semibold">
              {t('taxes.assign_title')}
            </h2>
            <p className="mt-1 text-sm text-slate-600">
              {t('taxes.assign_help')}
            </p>
          </div>
          <FormErrors errors={assign.errors} />
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium">
              {t('taxes.assign_select')}
            </legend>
            {assignable.map((tax) => (
              <label key={tax.id} className="flex items-center gap-3 text-sm">
                <input
                  type="checkbox"
                  className="size-4"
                  checked={assign.data.tax_ids.includes(tax.id)}
                  onChange={(e) =>
                    assign.setData(
                      'tax_ids',
                      e.target.checked
                        ? [...assign.data.tax_ids, tax.id]
                        : assign.data.tax_ids.filter((id) => id !== tax.id),
                    )
                  }
                />
                {tax.name}
              </label>
            ))}
          </fieldset>
          <label className="flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 size-4"
              checked={assign.data.confirmed}
              onChange={(e) => assign.setData('confirmed', e.target.checked)}
            />
            {t('taxes.assign_confirm', { count: productCount })}
          </label>
          <button
            type="button"
            className="button-primary"
            disabled={
              assign.processing ||
              assign.data.tax_ids.length === 0 ||
              !assign.data.confirmed
            }
            onClick={() =>
              assign.post('/admin/taxes/assign-all', {
                preserveScroll: true,
                onSuccess: () => assign.reset(),
              })
            }
          >
            {t('taxes.assign_action')}
          </button>
        </section>
      )}
    </AdminLayout>
  );
}
