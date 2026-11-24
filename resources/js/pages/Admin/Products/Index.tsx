import EditorHeader from '../../../components/admin/EditorHeader';
import ExportLink from '../../../components/admin/ExportLink';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import NavigationIcon from '../../../components/admin/NavigationIcon';
import Select from '../../../components/admin/Select';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import BulkBar from '../../../components/admin/list/BulkBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge, {
  type Tone,
} from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { ProductOption, ProductRow } from '../../../types/products';

type Props = AdminShell & {
  products: Page<ProductRow>;
  filters: Record<string, string>;
  trash: boolean;
  catalogCount: number;
  categories: ProductOption[];
  brands?: ProductOption[];
  bulkResults: { id: string; success: boolean; reason: string }[];
  currency: { code: string };
};

const statusTone: Record<string, Tone> = {
  active: 'success',
  disabled: 'neutral',
  hidden: 'warning',
};
const sortFields = [
  'updated_at',
  'name',
  'sku',
  'type',
  'price',
  'quantity',
  'status',
];

export default function ProductsIndex({
  products,
  filters,
  trash,
  catalogCount,
  categories,
  brands = [],
  bulkResults,
  currency,
  ...shell
}: Props) {
  const t = useTranslation();
  const page = usePage<SharedProps>();
  const can = page.props.capabilities;
  const base = trash ? '/admin/products/trash' : '/admin/products';
  const apply = useListQuery(base, filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [categorySearch, setCategorySearch] = useState(
    filters.category_search ?? '',
  );
  const categoryTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
  const [selected, setSelected] = useState<string[]>([]);
  const [status, setStatus] = useState('disabled');
  const onPage = selected.filter((id) =>
    products.data.some((p) => p.id === id),
  );
  const filtered = [
    'search',
    'status',
    'type',
    'category_id',
    'brand_id',
    'incomplete',
    'variation',
    'stock_action',
  ].some((key) => filters[key]);
  const action = useForm({
    version: 1,
    action: '',
    confirmation: '',
    return_to: page.url,
  });
  useDirtyForm(action);
  const run = (product: ProductRow, name: string) => {
    let confirmation = '';
    if (name === 'trash' && !window.confirm(t('products.confirm_trash')))
      return;
    if (name === 'purge') {
      const answer = window.prompt(
        t('products.confirm_exact') + '\n' + product.confirmationName,
      );
      if (answer === null) return;
      confirmation = answer;
    }
    action.transform(() => ({
      version: product.version,
      action: name,
      confirmation,
      return_to: page.url,
    }));
    action.post('/admin/products/' + product.id + '/action');
  };
  const options = (values: string[], label: (value: string) => string) => [
    { value: '', label: t('products.all') },
    ...values.map((value) => ({ value, label: label(value) })),
  ];
  const { date: date, time } = useDateDisplay();
  const selectable = !trash && can['products.status'];
  const title = t(trash ? 'products.trash' : 'products.title');

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="products"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t(
                (trash ? 'products.trash_count_' : 'products.count_') +
                  (catalogCount === 1 ? 'one' : 'other'),
                { count: catalogCount },
              )}
            </p>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-3">
              {!trash && (
                <ExportLink entity="products" filters={filters} ids={onPage} />
              )}
              {!trash && can['products.import'] && (
                <Link
                  href="/admin/products/import"
                  className="button-secondary gap-2"
                >
                  <Icon name="upload" />
                  {t('product_import.title')}
                </Link>
              )}
              {(trash ? can['products.view'] : can['products.trash-view']) && (
                <Link
                  className="button-secondary gap-2"
                  href={trash ? '/admin/products' : '/admin/products/trash'}
                >
                  {!trash && (
                    <Icon name="trash" className="size-4 text-red-600" />
                  )}
                  {t(trash ? 'products.back' : 'products.trash')}
                </Link>
              )}
              {!trash && can['products.create'] && (
                <button
                  className="button-primary gap-2"
                  onClick={() =>
                    router.post('/admin/products', { return_to: page.url })
                  }
                >
                  <Icon name="plus" />
                  {t('products.create')}
                </button>
              )}
            </div>
          </>
        }
      />
      <FormErrors errors={action.errors} />
      {bulkResults.length > 0 && (
        <div
          role="status"
          className="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-sm"
        >
          <p>
            {t('products.success')}:{' '}
            {bulkResults.filter((r) => r.success).length} ·{' '}
            {t('products.failed')}:{' '}
            {bulkResults.filter((r) => !r.success).length}
          </p>
          {bulkResults
            .filter((r) => !r.success)
            .map((r) => (
              <p key={r.id} className="mt-2 text-sm text-red-700">
                #{r.id}: {r.reason}
              </p>
            ))}
        </div>
      )}
      <section className="list-card" aria-label={title}>
        <FilterBar
          resetHref={base}
          active={filtered}
          search={
            <SearchField
              id="product-search"
              value={search}
              placeholder={t('products.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'updated_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={sortFields.map((value) => ({
                value,
                label: t('products.' + value),
              }))}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={options(['active', 'disabled', 'hidden'], (v) =>
                  t('products.' + v),
                )}
                onChange={(value) => apply({ status: value })}
              />
              <FilterChip
                label={t('products.type')}
                value={filters.type ?? ''}
                options={options(['physical', 'digital'], (v) =>
                  t('products.' + v),
                )}
                onChange={(value) => apply({ type: value })}
              />
              <FilterChip
                label={t('brands.brand')}
                value={filters.brand_id ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...brands.map((b) => ({ value: b.id, label: b.name })),
                ]}
                onChange={(brand_id) => apply({ brand_id })}
              />
              <FilterChip
                label={t('products.categories')}
                value={filters.category_id ?? ''}
                options={[
                  { value: '', label: t('products.all') },
                  ...categories.map((c) => ({
                    value: String(c.id),
                    label: c.name,
                  })),
                ]}
                search={{
                  value: categorySearch,
                  onChange: (value) => {
                    setCategorySearch(value);
                    clearTimeout(categoryTimer.current);
                    categoryTimer.current = setTimeout(
                      () =>
                        router.reload({
                          data: { category_search: value },
                          only: ['categories'],
                          preserveUrl: true,
                        }),
                      300,
                    );
                  },
                }}
                onChange={(value) =>
                  apply({ category_id: value, category_search: undefined })
                }
              />
              <FilterChip
                label={t('variations.group_column')}
                value={filters.variation ?? ''}
                options={options(['grouped', 'standalone'], (v) =>
                  t('variations.' + v),
                )}
                onChange={(value) => apply({ variation: value })}
              />
              <FilterChip
                label={t('products.stock_action_filter')}
                value={filters.stock_action ?? ''}
                options={options(['general', 'custom'], (v) =>
                  t(
                    v === 'custom'
                      ? 'products.stock_action_custom'
                      : 'products.stock_action_general',
                  ),
                )}
                onChange={(value) => apply({ stock_action: value })}
              />
              <FilterChip
                label={t('products.incomplete')}
                value={filters.incomplete ?? ''}
                options={options(['1', '0'], (v) =>
                  t(v === '1' ? 'products.incomplete' : 'products.complete'),
                )}
                onChange={(value) => apply({ incomplete: value })}
              />
            </>
          }
        />
        {selectable && onPage.length > 0 && (
          <BulkBar count={onPage.length} onClear={() => setSelected([])}>
            <div className="w-48">
              <Select
                aria-label={t('common.status')}
                value={status}
                onChange={setStatus}
                options={['active', 'disabled', 'hidden'].map((s) => ({
                  value: s,
                  label: t('products.' + s),
                }))}
              />
            </div>
            <button
              type="button"
              className="button-primary"
              onClick={() =>
                router.post(
                  '/admin/products/bulk',
                  { ids: onPage, status, return_to: page.url },
                  { onSuccess: () => setSelected([]) },
                )
              }
            >
              {t('products.apply')}
            </button>
          </BulkBar>
        )}
        <div className="overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                {selectable && (
                  <th className="w-12">
                    <input
                      aria-label={t('products.select_page')}
                      type="checkbox"
                      className="size-4"
                      checked={
                        products.data.length > 0 &&
                        products.data.every((p) => selected.includes(p.id))
                      }
                      onChange={(e) =>
                        setSelected(
                          e.target.checked
                            ? products.data.map((p) => p.id)
                            : [],
                        )
                      }
                    />
                  </th>
                )}
                <th>{t('common.name')}</th>
                <th>{t('products.categories')}</th>
                <th className="text-right">
                  {t('products.price_currency', { code: currency.code })}
                </th>
                <th className="text-right">{t('products.stock')}</th>
                <th>{t('common.status')}</th>
                <th>{t('variations.group_column')}</th>
                <th>{t('products.updated')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {products.data.map((p) => {
                const checked = selected.includes(p.id);
                const updated = new Date(p.updated_at);
                return (
                  <tr key={p.id} data-selected={checked || undefined}>
                    {selectable && (
                      <td>
                        <input
                          aria-label={p.name}
                          type="checkbox"
                          className="size-4"
                          checked={checked}
                          onChange={(e) =>
                            setSelected(
                              e.target.checked
                                ? [...selected, p.id]
                                : selected.filter((id) => id !== p.id),
                            )
                          }
                        />
                      </td>
                    )}
                    <td className="min-w-56">
                      <div className="flex items-center gap-3">
                        {p.image ? (
                          <img
                            src={p.image}
                            alt=""
                            className="size-11 shrink-0 rounded-lg object-cover"
                          />
                        ) : (
                          <span
                            aria-hidden="true"
                            className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-primary"
                          >
                            <NavigationIcon id="core.catalog-products" />
                          </span>
                        )}
                        <div className="min-w-0">
                          {!trash && can['products.update'] ? (
                            <Link
                              className="font-semibold text-slate-900 hover:underline"
                              href={
                                '/admin/products/' +
                                p.id +
                                '/edit?return_to=' +
                                encodeURIComponent(page.url)
                              }
                            >
                              {p.name}
                            </Link>
                          ) : (
                            <span className="font-semibold">{p.name}</span>
                          )}
                          <p className="mt-0.5 text-xs text-slate-500">
                            {[p.sku, t('products.' + p.type)]
                              .filter(Boolean)
                              .join(' · ')}
                          </p>
                          {!!p.sticker_count && can['stickers.view'] && (
                            <span className="mt-1 block text-xs text-slate-500">
                              {t('stickers.assignment_count', {
                                count: p.sticker_count,
                              })}
                            </span>
                          )}
                          {p.incomplete && (
                            <p className="mt-0.5 text-xs text-amber-700">
                              {t('products.incomplete')}
                            </p>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="min-w-48 text-slate-500">
                      {p.category.length
                        ? p.category.map((name, index) => (
                            <span key={index}>
                              {index > 0 && (
                                <span aria-hidden="true" className="mx-1">
                                  ›
                                </span>
                              )}
                              <span
                                className={
                                  index === p.category.length - 1
                                    ? 'font-medium text-slate-900'
                                    : ''
                                }
                              >
                                {name}
                              </span>
                            </span>
                          ))
                        : '—'}
                    </td>
                    <td className="text-right font-semibold whitespace-nowrap tabular-nums">
                      {p.list_price_formatted && (
                        <del
                          className="mr-2 text-xs font-normal text-slate-500"
                          title={t('products.list_price_label')}
                        >
                          {p.list_price_formatted}
                        </del>
                      )}
                      {p.price_formatted ?? '—'}
                    </td>
                    <td className="text-right whitespace-nowrap tabular-nums">
                      {p.low_stock === true ? (
                        <span className="text-amber-700">
                          {p.quantity} · {t('settings.low_stock')}
                        </span>
                      ) : p.quantity > 0 || p.low_stock === false ? (
                        p.quantity
                      ) : (
                        <span className="text-amber-700">
                          0 · {t('products.out_of_stock')}
                        </span>
                      )}
                    </td>
                    <td>
                      <StatusBadge tone={statusTone[p.status] ?? 'neutral'}>
                        {t('products.' + p.status)}
                      </StatusBadge>
                    </td>
                    <td className="min-w-36">
                      {p.variation_group ? (
                        <Link
                          className="text-link"
                          href={
                            '/admin/variation-groups/' + p.variation_group.id
                          }
                        >
                          {p.variation_group.name}
                        </Link>
                      ) : (
                        <span className="text-slate-500">
                          {t('variations.standalone')}
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap">
                      <time dateTime={p.updated_at}>
                        <span className="block">{date.format(updated)}</span>
                        <span className="block text-xs text-slate-500 tabular-nums">
                          {time.format(updated)}
                        </span>
                      </time>
                    </td>
                    <td>
                      <div className="flex justify-end gap-2">
                        {!trash && can['products.create'] && (
                          <IconButton
                            icon="copy"
                            label={t('products.duplicate') + ': ' + p.name}
                            onClick={() =>
                              router.post(
                                '/admin/products/' + p.id + '/duplicate',
                                { version: p.version, return_to: page.url },
                              )
                            }
                          />
                        )}
                        {!trash && can['products.delete'] && (
                          <IconButton
                            icon="trash"
                            danger
                            label={t('products.trash') + ': ' + p.name}
                            onClick={() => run(p, 'trash')}
                          />
                        )}
                        {trash && can['products.restore'] && (
                          <IconButton
                            icon="restore"
                            label={t('products.restore') + ': ' + p.name}
                            onClick={() => run(p, 'restore')}
                          />
                        )}
                        {trash && can['products.force-delete'] && (
                          <IconButton
                            icon="trash"
                            danger
                            label={t('products.purge') + ': ' + p.name}
                            onClick={() => run(p, 'purge')}
                          />
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {products.data.length === 0 && (
            <div className="p-12 text-center text-sm text-slate-500">
              {t(catalogCount === 0 ? 'products.empty' : 'products.no_results')}
              {filtered && (
                <Link className="text-link ml-3" href={base}>
                  {t('products.reset')}
                </Link>
              )}
            </div>
          )}
        </div>
        <Pagination
          page={products}
          perPage={{
            value: filters.per_page ?? '50',
            options: ['25', '50', '100'],
            onChange: (value) => apply({ per_page: value }),
          }}
        />
      </section>
    </AdminLayout>
  );
}
