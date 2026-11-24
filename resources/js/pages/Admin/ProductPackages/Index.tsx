import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import ContentLanguageSelector from '../../../components/admin/ContentLanguageSelector';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import Select from '../../../components/admin/Select';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import { SearchField } from '../../../components/admin/list/FilterBar';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { CompanionRow } from '../../../components/admin/ProductCompanionPicker';

type Props = AdminShell & {
  packages: { id: string; name: string }[];
  selected: {
    id: string;
    version: number;
    translations: Record<string, string>;
    items: string[];
    assigned: CompanionRow[];
    assigned_count: number;
    assigned_page: number;
  } | null;
  items: CompanionRow[];
  candidates: CompanionRow[];
  productSearch?: string;
  categories: { id: string; name: string }[];
};

function ProductRow({
  product,
  action,
}: {
  product: CompanionRow;
  action?: ReactNode;
}) {
  const t = useTranslation();
  return (
    <div className="flex min-w-0 items-center gap-3 py-3">
      <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 text-slate-400">
        {product.image ? (
          <img
            src={product.image}
            alt=""
            className="size-full object-contain"
          />
        ) : (
          <Icon name="box" />
        )}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium leading-5 text-slate-900">
          {product.name}
        </p>
        <p className="mt-1 text-xs text-slate-500">
          {[product.sku, product.price].filter(Boolean).join(' · ')}
        </p>
        {(product.deleted || product.status !== 'active') && (
          <p className="mt-1 text-xs text-amber-700">
            {t('products.companion_unavailable')}
          </p>
        )}
      </div>
      {action}
    </div>
  );
}

export default function Packages(props: Props) {
  return (
    <Editor
      key={`${props.selected?.id}:${props.selected?.version}`}
      {...props}
    />
  );
}

function Editor({
  packages,
  selected,
  items,
  candidates,
  productSearch = '',
  categories,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n, capabilities } = usePage<SharedProps>().props;
  const canEdit = !!capabilities['products.update'];
  const form = useForm({
    id: selected?.id ?? null,
    version: selected?.version ?? null,
    translations: Object.fromEntries(
      i18n.locales.map((l) => [l.code, selected?.translations[l.code] ?? '']),
    ),
    items: selected?.items ?? [],
  });
  useDirtyForm(form);
  const assignment = useForm({
    package: selected?.id ?? '',
    products: [] as string[],
    category: '',
  });
  const [tab, setTab] = useState<'contents' | 'assignment'>('contents');
  const [locale, setLocale] = useState(i18n.locale);
  const activeLocale = i18n.locales.some((l) => l.code === locale)
    ? locale
    : (i18n.locales[0]?.code ?? 'en');
  const [mode, setMode] = useState<'products' | 'category'>('products');
  const [search, setSearch] = useState(productSearch);
  const [packageSearch, setPackageSearch] = useState('');
  const [searching, setSearching] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [known, setKnown] = useState<Record<string, CompanionRow>>({});
  const lookup = (id: string): CompanionRow =>
    items.find((p) => p.id === id) ??
    known[id] ??
    candidates.find((p) => p.id === id) ?? {
      id,
      name: `#${id}`,
      sku: null,
      price: null,
      image: null,
      status: 'active',
      deleted: false,
    };
  const remember = (product: CompanionRow) =>
    setKnown((current) => ({ ...current, [product.id]: product }));
  const searchProducts = () =>
    router.get(
      '/admin/product-packages',
      { package: selected?.id, search: search.trim() },
      {
        preserveState: true,
        preserveScroll: true,
        only: ['candidates', 'productSearch'],
        onStart: () => setSearching(true),
        onFinish: () => setSearching(false),
      },
    );
  const toggleAssignment = (product: CompanionRow) => {
    remember(product);
    assignment.setData(
      'products',
      assignment.data.products.includes(product.id)
        ? assignment.data.products.filter((id) => id !== product.id)
        : [...assignment.data.products, product.id],
    );
  };
  const shownPackages = packages.filter((p) =>
    p.name.toLocaleLowerCase().includes(packageSearch.toLocaleLowerCase()),
  );
  const packageName = selected
    ? packages.find((p) => p.id === selected.id)?.name
    : t('packages.new');
  const empty = (message: string) => (
    <div className="flex flex-col items-center gap-3 px-6 py-10 text-center text-sm text-slate-500">
      <span className="rounded-xl bg-slate-50 p-3 text-slate-400">
        <Icon name="box" />
      </span>
      <p className="max-w-sm leading-6">{message}</p>
    </div>
  );
  const productFinder = (forAssignment: boolean) => (
    <section
      className="overflow-hidden rounded-xl border border-slate-200"
      aria-label={t('packages.find_products')}
    >
      <div className="space-y-3 border-b border-slate-200 bg-slate-50/60 p-4">
        <h3 className="text-sm font-semibold">{t('packages.find_products')}</h3>
        <div className="flex gap-2">
          <div className="min-w-0 flex-1">
            <SearchField
              embedded
              id={forAssignment ? 'assignment-search' : 'contents-search'}
              value={search}
              onChange={setSearch}
              placeholder={t('products.companion_search_placeholder')}
              onSubmit={searchProducts}
            />
          </div>
          <button
            type="button"
            className="button-secondary"
            disabled={searching}
            onClick={searchProducts}
          >
            {t('common.search')}
          </button>
        </div>
        <p className="text-xs leading-5 text-slate-500">
          {t('packages.search_help')}
        </p>
      </div>
      <div aria-busy={searching} className="max-h-[26rem] overflow-y-auto px-4">
        {!productSearch ? (
          empty(t('packages.search_empty'))
        ) : candidates.length === 0 ? (
          empty(t('packages.no_results'))
        ) : (
          <ul className="divide-y divide-slate-100">
            {candidates.map((p) => {
              const chosen = (
                forAssignment ? assignment.data.products : form.data.items
              ).includes(p.id);
              return (
                <li key={p.id}>
                  <ProductRow
                    product={p}
                    action={
                      forAssignment ? (
                        <input
                          type="checkbox"
                          className="size-4 shrink-0 accent-primary"
                          aria-label={t('packages.select_product', {
                            name: p.name,
                          })}
                          checked={chosen}
                          disabled={
                            !canEdit ||
                            assignment.processing ||
                            (!chosen && assignment.data.products.length >= 100)
                          }
                          onChange={() => toggleAssignment(p)}
                        />
                      ) : (
                        <button
                          type="button"
                          className="button-secondary gap-1.5 px-3"
                          disabled={
                            !canEdit ||
                            form.processing ||
                            chosen ||
                            form.data.items.length >= 30
                          }
                          onClick={() => {
                            remember(p);
                            form.setData('items', [...form.data.items, p.id]);
                          }}
                        >
                          <Icon name={chosen ? 'check' : 'plus'} />
                          <span>
                            {t(chosen ? 'packages.added' : 'packages.add')}
                          </span>
                        </button>
                      )
                    }
                  />
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </section>
  );

  return (
    <AdminLayout title={t('packages.title')} {...shell}>
      <EditorHeader
        heading={
          selected
            ? t('packages.title') + ': ' + packageName
            : t('packages.title')
        }
        iconKey="package"
        description={
          <>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
              {t('packages.intro')}
            </p>
          </>
        }
        actions={
          <>
            <Link
              href="/admin/product-packages"
              className="button-secondary gap-2"
            >
              <Icon name="plus" className="size-5" />
              {t('packages.new')}
            </Link>
            <EditorActionButton
              type="submit"
              form="package-details"

              disabled={!canEdit || form.processing}
            >
              {t('common.save')}
            </EditorActionButton>
          </>
        }
        menu={[
          selected && canEdit && (
            <button
              type="button"
              className="button-secondary px-3 text-red-700 hover:bg-red-50"
              aria-label={t('common.delete')}
              title={t('common.delete')}
              onClick={() => setDeleting(true)}
            >
              <Icon name="trash" />
              {t('common.delete')}
            </button>
          ),
        ]}
      />
      <div className="grid items-start gap-6 xl:grid-cols-[17rem_minmax(0,1fr)]">
        <aside
          className="list-card overflow-hidden"
          aria-label={t('packages.title')}
        >
          <div className="space-y-4 border-b border-slate-200 p-4">
            <SearchField
              id="package-search"
              value={packageSearch}
              onChange={setPackageSearch}
              placeholder={t('packages.search_packages')}
              onSubmit={() => {}}
            />
          </div>
          <nav
            aria-label={t('packages.title')}
            className="max-h-[36rem] overflow-y-auto p-2"
          >
            {shownPackages.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">
                {t('packages.no_packages')}
              </p>
            ) : (
              shownPackages.map((p) => (
                <Link
                  key={p.id}
                  href={`/admin/product-packages?package=${p.id}`}
                  aria-current={p.id === selected?.id ? 'page' : undefined}
                  className={`mb-1 flex items-center gap-3 rounded-lg px-3 py-3 text-sm ${p.id === selected?.id ? 'bg-primary-tint font-semibold text-primary-dark' : 'text-slate-700 hover:bg-slate-50'}`}
                >
                  <span className="shrink-0 text-slate-400">
                    <Icon name="box" />
                  </span>
                  <span className="min-w-0 break-words">{p.name}</span>
                </Link>
              ))
            )}
          </nav>
        </aside>
        <div className="min-w-0 space-y-5">
          <div className="list-card overflow-hidden">
            <div className="border-b border-slate-200 px-5 pt-5 sm:px-6">
              <AdminTabs
                label={t('packages.edit')}
                language={
                  <ContentLanguageSelector
                    languages={i18n.locales}
                    value={activeLocale}
                    onChange={setLocale}
                  />
                }
              >
                <AdminTab
                  id="package-contents-tab"
                  role="tab"
                  aria-selected={tab === 'contents'}
                  aria-controls="package-details"
                  onClick={() => setTab('contents')}
                  iconKey={'contents'}
                >
                  {t('packages.contents')}{' '}
                  <span className="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                    {form.data.items.length}
                  </span>
                </AdminTab>
                <AdminTab
                  id="package-assignment-tab"
                  role="tab"
                  aria-selected={tab === 'assignment'}
                  aria-controls="package-assignment-panel"
                  disabled={!selected}
                  title={!selected ? t('packages.save_first') : undefined}
                  onClick={() => setTab('assignment')}
                  iconKey={'assignment'}
                >
                  {t('packages.assignments')}
                  {selected && (
                    <span className="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                      {selected.assigned_count}
                    </span>
                  )}
                </AdminTab>
              </AdminTabs>
            </div>
            <form
              id="package-details"
              hidden={tab !== 'contents'}
              role="tabpanel"
              aria-labelledby="package-contents-tab"
              className="space-y-6 p-5 sm:p-6"
              onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/product-packages');
              }}
            >
              <FormErrors errors={form.errors} />
              <div className="max-w-2xl space-y-4">
                <FormField
                  id="package-name"
                  label={t('packages.name')}
                  placeholder={t('packages.name_placeholder')}
                  requiredIndicator
                  maxLength={200}
                  value={form.data.translations[activeLocale] ?? ''}
                  disabled={!canEdit || form.processing}
                  error={
                    (form.errors as Record<string, string>)[
                      `translations.${activeLocale}`
                    ]
                  }
                  onChange={(e) =>
                    form.setData('translations', {
                      ...form.data.translations,
                      [activeLocale]: e.target.value,
                    })
                  }
                />
              </div>
              <div className="grid items-start gap-5 lg:grid-cols-2">
                <section
                  className="overflow-hidden rounded-xl border border-slate-200"
                  aria-label={t('packages.selected_products')}
                >
                  <div className="border-b border-slate-200 bg-slate-50/60 p-4">
                    <h3 className="text-sm font-semibold">
                      {t('packages.selected_products')}{' '}
                      <span className="font-normal text-slate-500">
                        {form.data.items.length} / 30
                      </span>
                    </h3>
                    <p className="mt-1 text-xs leading-5 text-slate-500">
                      {t('packages.contents_help')}
                    </p>
                  </div>
                  {form.data.items.length === 0 ? (
                    empty(t('packages.contents_empty'))
                  ) : (
                    <ol className="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto px-4">
                      {form.data.items.map((id) => (
                        <li key={id}>
                          <ProductRow
                            product={lookup(id)}
                            action={
                              <IconButton
                                icon="trash"
                                label={t('products.companion_remove', {
                                  name: lookup(id).name,
                                })}
                                disabled={!canEdit || form.processing}
                                onClick={() =>
                                  form.setData(
                                    'items',
                                    form.data.items.filter((v) => v !== id),
                                  )
                                }
                              />
                            }
                          />
                        </li>
                      ))}
                    </ol>
                  )}
                </section>
                {productFinder(false)}
              </div>
              {!selected && (
                <p className="text-xs text-slate-500">
                  {t('packages.save_first')}
                </p>
              )}
            </form>
            {selected && (
              <div
                id="package-assignment-panel"
                hidden={tab !== 'assignment'}
                role="tabpanel"
                aria-labelledby="package-assignment-tab"
                className="space-y-6 p-5 sm:p-6"
              >
                <form
                  className="space-y-5"
                  onSubmit={(e) => {
                    e.preventDefault();
                    assignment.post('/admin/product-packages/assign', {
                      preserveScroll: true,
                    });
                  }}
                >
                  <FormErrors errors={assignment.errors} />
                  <div>
                    <h3 className="font-semibold">{t('packages.assign')}</h3>
                    <p className="mt-1 text-sm leading-6 text-slate-500">
                      {t('packages.assignment_help')}
                    </p>
                  </div>
                  <AdminTabs label={t('packages.assign')}>
                    {(['products', 'category'] as const).map((value) => (
                      <AdminTab
                        type="button"
                        key={value}
                        aria-selected={mode === value}
                        iconKey={value}
                        onClick={() => {
                          setMode(value);
                          assignment.setData({
                            ...assignment.data,
                            products: [],
                            category: '',
                          });
                        }}
                      >
                        {t(
                          value === 'products'
                            ? 'packages.by_products'
                            : 'packages.by_category',
                        )}
                      </AdminTab>
                    ))}
                  </AdminTabs>
                  {mode === 'products' ? (
                    <div className="space-y-4">
                      {productFinder(true)}
                      {assignment.data.products.length > 0 && (
                        <div>
                          <h4 className="mb-2 text-sm font-medium">
                            {t('packages.selection_count', {
                              count: assignment.data.products.length,
                            })}
                          </h4>
                          <div className="flex flex-wrap gap-2">
                            {assignment.data.products.map((id) => (
                              <button
                                type="button"
                                key={id}
                                className="inline-flex max-w-full items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs"
                                onClick={() =>
                                  assignment.setData(
                                    'products',
                                    assignment.data.products.filter(
                                      (p) => p !== id,
                                    ),
                                  )
                                }
                              >
                                <span className="truncate">
                                  {lookup(id).name}
                                </span>
                                <Icon name="x" />
                              </button>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>
                  ) : (
                    <div className="max-w-2xl space-y-2">
                      <label
                        htmlFor="package-category"
                        className="block text-sm font-medium"
                      >
                        {t('categories.title')}
                      </label>
                      <Select
                        id="package-category"
                        value={assignment.data.category}
                        disabled={!canEdit || assignment.processing}
                        onChange={(value) =>
                          assignment.setData('category', value)
                        }
                        options={[
                          { value: '', label: t('packages.choose_category') },
                          ...categories.map((c) => ({
                            value: c.id,
                            label: c.name,
                          })),
                        ]}
                        search={{
                          label: t('common.search'),
                          emptyMessage: t('packages.no_results'),
                        }}
                      />
                      <p className="text-xs leading-5 text-slate-500">
                        {t('packages.category')}
                      </p>
                    </div>
                  )}
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4">
                    <p className="max-w-xl text-xs leading-5 text-slate-500">
                      {t('packages.replaces_assignment')}
                    </p>
                    <button
                      className="button-primary"
                      disabled={
                        !canEdit ||
                        assignment.processing ||
                        (mode === 'products'
                          ? assignment.data.products.length === 0
                          : !assignment.data.category)
                      }
                    >
                      {t('packages.assign')}
                    </button>
                  </div>
                </form>
                <section className="border-t border-slate-200 pt-5">
                  <h3 className="font-semibold">
                    {t('packages.assigned')}{' '}
                    <span className="ml-1 text-sm font-normal text-slate-500">
                      {selected.assigned_count}
                    </span>
                  </h3>
                  {selected.assigned.length === 0 ? (
                    empty(t('packages.assignments_empty'))
                  ) : (
                    <ul className="mt-3 divide-y divide-slate-100">
                      {selected.assigned.map((p) => (
                        <li key={p.id}>
                          <ProductRow
                            product={p}
                            action={
                              <Link
                                className="button-secondary px-3"
                                aria-label={p.name}
                                href={`/admin/products/${p.id}/edit`}
                              >
                                <Icon name="edit" />
                              </Link>
                            }
                          />
                        </li>
                      ))}
                    </ul>
                  )}
                  <nav
                    aria-label={t('packages.assigned')}
                    className="mt-4 flex justify-end gap-2"
                  >
                    {selected.assigned_page > 1 && (
                      <Link
                        className="button-secondary"
                        href={`/admin/product-packages?package=${selected.id}&assigned_page=${selected.assigned_page - 1}`}
                        preserveState
                        preserveScroll
                      >
                        {t('packages.previous')}
                      </Link>
                    )}
                    {selected.assigned_count > selected.assigned_page * 100 && (
                      <Link
                        className="button-secondary"
                        href={`/admin/product-packages?package=${selected.id}&assigned_page=${selected.assigned_page + 1}`}
                        preserveState
                        preserveScroll
                      >
                        {t('packages.next')}
                      </Link>
                    )}
                  </nav>
                </section>
              </div>
            )}
          </div>
        </div>
      </div>
      {deleting && selected && (
        <ConfirmDeleteDialog
          title={t('packages.delete_title')}
          facts={[
            t('packages.delete_help'),
            t('packages.selection_count', { count: selected.assigned_count }),
          ]}
          onCancel={() => setDeleting(false)}
          onConfirm={() =>
            router.delete(`/admin/product-packages/${selected.id}`, {
              data: { version: selected.version },
            })
          }
        />
      )}
    </AdminLayout>
  );
}
