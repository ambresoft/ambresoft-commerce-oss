import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import Switch from '../../../components/admin/Switch';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { ShippingMethodRow } from '../../../types/shipping';

function Grip() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 20 20"
      className="size-5"
      fill="currentColor"
    >
      {[5, 10, 15].map((y) => (
        <g key={y}>
          <circle cx="7" cy={y} r="1.4" />
          <circle cx="13" cy={y} r="1.4" />
        </g>
      ))}
    </svg>
  );
}

export default function ShippingMethodsIndex({
  methods,
  filters,
  carriers,
  ...shell
}: AdminShell & {
  methods: Page<ShippingMethodRow>;
  filters: Record<string, string>;
  carriers: { id: string; name: string }[];
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const { date } = useDateDisplay();
  const apply = useListQuery('/admin/shipping-methods', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'calculation', 'status'].some(
    (key) => filters[key],
  );
  const canManage = capabilities['shipping-methods.manage'];
  // Reordering needs every method in view.
  const sortable = canManage && !filtered && methods.last_page === 1;
  const [pending, setPending] = useState<string[] | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const rows = pending
    ? pending
        .map((id) => methods.data.find((m) => m.id === id))
        .filter((m): m is ShippingMethodRow => m !== undefined)
    : methods.data;
  const place = (id: string, target: number) => {
    const from = rows.findIndex((m) => m.id === id);
    if (from < 0 || target < 0 || target >= rows.length || from === target)
      return;
    const next = [...rows];
    next.splice(target, 0, next.splice(from, 1)[0]!);
    setPending(next.map((m) => m.id));
    router.post(
      '/admin/shipping-methods/order',
      { ids: next.map((m) => m.id) },
      { preserveScroll: true, onFinish: () => setPending(null) },
    );
  };
  const remove = (method: ShippingMethodRow) => {
    if (!window.confirm(t('shipping.delete_confirm', { name: method.name })))
      return;
    router.delete('/admin/shipping-methods/' + method.id, {
      data: { version: method.version },
      preserveScroll: true,
    });
  };

  return (
    <AdminLayout title={t('shipping.title')} {...shell}>
      <EditorHeader
        heading={t('shipping.title')}
        iconKey="shipping"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">{t('shipping.intro')}</p>
            {carriers.length > 0 &&
              capabilities['shipping-carriers.manage'] && (
                <p className="mt-2 flex flex-wrap gap-3 text-sm">
                  {carriers.map((carrier) => (
                    <Link
                      key={carrier.id}
                      className="text-link"
                      href={'/admin/shipping-carriers/' + carrier.id}
                    >
                      {t('shipping.carrier_settings_of', {
                        name: carrier.name,
                      })}
                    </Link>
                  ))}
                </p>
              )}
          </>
        }
        actions={
          <>
            {canManage && (
              <Link
                className="button-primary gap-2"
                href="/admin/shipping-methods/create"
              >
                <Icon name="plus" />
                {t('shipping.create')}
              </Link>
            )}
          </>
        }
      />
      <div className="mb-4">
        <FormErrors errors={errors ?? {}} />
      </div>
      <section className="list-card" aria-label={t('shipping.title')}>
        <FilterBar
          resetHref="/admin/shipping-methods"
          active={filtered}
          search={
            <SearchField
              id="shipping-search"
              value={search}
              placeholder={t('shipping.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('shipping.calculation')}
                value={filters.calculation ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'manual', label: t('shipping.calculation_manual') },
                  {
                    value: 'carrier',
                    label: t('shipping.calculation_carrier'),
                  },
                ]}
                onChange={(value) => apply({ calculation: value })}
              />
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('shipping.active') },
                  { value: 'disabled', label: t('shipping.inactive') },
                ]}
                onChange={(value) => apply({ status: value })}
              />
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('shipping.order_position')}</th>
                <th>{t('common.name')}</th>
                <th>{t('shipping.calculation')}</th>
                <th>{t('shipping.carrier_account')}</th>
                <th>{t('common.status')}</th>
                <th>{t('shipping.updated')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'shipping.no_matches' : 'shipping.empty')}
                  </td>
                </tr>
              )}
              {rows.map((method, index) => (
                <tr
                  key={method.id}
                  draggable={sortable}
                  onDragStart={(e) => {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', method.id);
                    setDragging(method.id);
                  }}
                  onDragEnd={() => setDragging(null)}
                  onDragOver={(e) => {
                    if (dragging === null) return;
                    e.preventDefault();
                  }}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (dragging !== null) place(dragging, index);
                    setDragging(null);
                  }}
                  className={dragging === method.id ? 'opacity-50' : ''}
                >
                  <td>
                    {sortable ? (
                      <button
                        type="button"
                        aria-label={t('shipping.drag_handle', {
                          name: method.name,
                        })}
                        title={t('shipping.drag_handle', { name: method.name })}
                        className="flex cursor-grab items-center gap-2 rounded text-slate-400 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-emerald-600"
                        onKeyDown={(e) => {
                          if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                            e.preventDefault();
                            place(
                              method.id,
                              index + (e.key === 'ArrowUp' ? -1 : 1),
                            );
                          }
                        }}
                      >
                        <Grip />
                        <span className="text-sm text-slate-600 tabular-nums">
                          {index + 1}
                        </span>
                      </button>
                    ) : (
                      <span className="text-sm text-slate-500 tabular-nums">
                        {index + 1}
                      </span>
                    )}
                  </td>
                  <td className="text-sm">
                    <span className="flex items-start gap-3">
                      {method.image_url && (
                        <img
                          src={method.image_url}
                          alt=""
                          className="size-10 shrink-0 rounded border border-slate-200 bg-white object-contain"
                        />
                      )}
                      <span>
                        {canManage ? (
                          <Link
                            className="font-medium hover:underline"
                            href={
                              '/admin/shipping-methods/' + method.id + '/edit'
                            }
                          >
                            {method.name}
                          </Link>
                        ) : (
                          <span className="font-medium">{method.name}</span>
                        )}
                        {method.orders > 0 && (
                          <span className="mt-0.5 block text-xs text-slate-500">
                            {t('shipping.used_by', { count: method.orders })}
                          </span>
                        )}
                      </span>
                    </span>
                  </td>
                  <td className="text-sm">
                    {t(
                      method.calculation === 'carrier'
                        ? 'shipping.calculation_carrier'
                        : 'shipping.calculation_manual',
                    )}
                  </td>
                  <td className="text-sm">
                    {method.provider ? (
                      <>
                        <span className="block">{method.provider_name}</span>
                        {method.account_name && (
                          <span className="block text-xs text-slate-500">
                            {method.account_name}
                          </span>
                        )}
                        {!method.provider_available && (
                          <span className="block text-xs text-amber-700">
                            {t('shipping.provider_unavailable')}
                          </span>
                        )}
                      </>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td>
                    <span className="flex items-center gap-2 text-sm">
                      <Switch
                        checked={method.active}
                        disabled={!canManage}
                        label={t('shipping.active_of', { name: method.name })}
                        onChange={(active) =>
                          router.patch(
                            '/admin/shipping-methods/' + method.id + '/active',
                            { version: method.version, active },
                            { preserveScroll: true },
                          )
                        }
                      />
                      <span
                        className={
                          method.active ? 'text-slate-700' : 'text-slate-500'
                        }
                      >
                        {t(
                          method.active
                            ? 'shipping.active'
                            : 'shipping.inactive',
                        )}
                      </span>
                    </span>
                  </td>
                  <td className="text-sm text-slate-500">
                    {method.updated_at
                      ? date.format(new Date(method.updated_at))
                      : '—'}
                  </td>
                  <td>
                    {canManage && (
                      <span className="flex justify-end gap-1">
                        <IconButton
                          icon="edit"
                          label={t('shipping.edit_of', { name: method.name })}
                          href={
                            '/admin/shipping-methods/' + method.id + '/edit'
                          }
                        />
                        {method.orders === 0 && (
                          <IconButton
                            icon="trash"
                            danger
                            label={t('shipping.delete_of', {
                              name: method.name,
                            })}
                            onClick={() => remove(method)}
                          />
                        )}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={methods} />
      </section>
    </AdminLayout>
  );
}
