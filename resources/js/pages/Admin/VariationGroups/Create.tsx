import EditorHeader from '../../../components/admin/EditorHeader';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useForm, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import { SearchField } from '../../../components/admin/list/FilterBar';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { CreationData } from '../../../types/variations';

function Step({ number, title }: { number: number; title: string }) {
  return (
    <h2 className="flex items-center gap-3 font-semibold">
      <span
        aria-hidden="true"
        className="flex size-7 items-center justify-center rounded-full bg-primary-tint text-sm text-emerald-900"
      >
        {number}
      </span>
      {title}
    </h2>
  );
}

export default function VariationGroupCreate({
  creation,
  filters,
  ...shell
}: AdminShell & {
  creation: CreationData;
  filters: { product_search?: string; product_id?: string };
}) {
  const t = useTranslation();
  const [search, setSearch] = useState(filters.product_search ?? '');
  const selected = creation.selected;
  const form = useForm({
    product_id: selected?.id ?? '',
    product_version: selected?.version ?? 0,
    features: [] as string[],
    name: selected?.suggested_name ?? '',
  });
  useDirtyForm(form);
  const visit = (query: Record<string, string>) =>
    router.get('/admin/variation-groups/create', query, {
      preserveScroll: true,
      replace: true,
    });
  const blocked = !selected || selected.grouped || selected.problems.length > 0;
  const title = t('variations.create');

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="variations"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('variations.create_help')}
            </p>
          </>
        }
      />
      <div className="space-y-6">
        <section
          className="list-card space-y-4 p-5"
          aria-labelledby="step-product"
        >
          <div id="step-product">
            <Step number={1} title={t('variations.step_product')} />
          </div>
          <SearchField
            id="product-search"
            value={search}
            placeholder={t('products.search_placeholder')}
            onChange={setSearch}
            onSubmit={() => visit({ product_search: search })}
          />
          <p className="text-xs text-slate-500">
            {t('variations.step_product_hint')}
          </p>
          <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
            {creation.products.map((p) => (
              <li
                key={p.id}
                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              >
                <div className="min-w-0">
                  <p className="font-semibold">{p.name}</p>
                  <p className="text-xs text-slate-500">
                    {[p.sku, t('products.' + p.type), p.category.join(' › ')]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
                <button
                  type="button"
                  className={
                    selected?.id === p.id
                      ? 'button-primary'
                      : 'button-secondary'
                  }
                  aria-pressed={selected?.id === p.id}
                  onClick={() =>
                    visit({ product_search: search, product_id: p.id })
                  }
                >
                  {t(
                    selected?.id === p.id
                      ? 'variations.selected'
                      : 'variations.select',
                  )}
                </button>
              </li>
            ))}
            {creation.products.length === 0 && (
              <li className="px-4 py-6 text-center text-sm text-slate-500">
                {t('variations.no_products')}
              </li>
            )}
          </ul>
        </section>

        {selected && (
          <form
            className="list-card space-y-6 p-5"
            onSubmit={(event) => {
              event.preventDefault();
              form.post('/admin/variation-groups');
            }}
          >
            <FormErrors errors={form.errors} />
            <div className="rounded-lg bg-slate-50 p-4 text-sm">
              <p className="font-semibold">{selected.name}</p>
              <p className="text-slate-500">
                {[
                  selected.sku,
                  t('products.' + selected.type),
                  selected.category.join(' › '),
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
              {selected.grouped && (
                <p role="alert" className="mt-2 text-red-700">
                  {t('variations.already_grouped_hint')}
                </p>
              )}
              {selected.problems.length > 0 && (
                <div role="alert" className="mt-2 text-red-700">
                  <p>{t('variations.product_incomplete_hint')}</p>
                  <ul className="mt-1 list-inside list-disc">
                    {selected.problems.map((problem) => (
                      <li key={problem}>{problem}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            <fieldset className="space-y-3" disabled={blocked}>
              <legend>
                <Step number={2} title={t('variations.step_features')} />
              </legend>
              <p className="text-sm text-slate-500">
                {t('variations.step_features_hint')}
              </p>
              {selected.features.length === 0 && (
                <p className="text-sm text-amber-700">
                  {t('variations.no_eligible_features')}
                </p>
              )}
              <ul className="space-y-2">
                {selected.features.map((feature) => {
                  const checked = form.data.features.includes(feature.id);
                  return (
                    <li
                      key={feature.id}
                      className="flex flex-wrap items-start gap-3 rounded-lg border border-slate-200 p-3"
                    >
                      <input
                        id={'feature-' + feature.id}
                        type="checkbox"
                        className="mt-1 size-4"
                        disabled={!feature.eligible}
                        checked={checked}
                        onChange={(e) =>
                          form.setData(
                            'features',
                            e.target.checked
                              ? [...form.data.features, feature.id]
                              : form.data.features.filter(
                                  (id) => id !== feature.id,
                                ),
                          )
                        }
                      />
                      <label
                        htmlFor={'feature-' + feature.id}
                        className="min-w-0 flex-1"
                      >
                        <span className="font-medium">{feature.name}</span>
                        {/* Step 3: review the product's existing value; nothing is assigned automatically. */}
                        <span className="block text-sm text-slate-500">
                          {feature.value
                            ? t('variations.current_value', {
                                value: feature.value,
                              })
                            : feature.problem}
                        </span>
                      </label>
                    </li>
                  );
                })}
              </ul>
            </fieldset>

            <fieldset className="space-y-2" disabled={blocked}>
              <legend>
                <Step number={3} title={t('variations.step_name')} />
              </legend>
              <label
                htmlFor="group-name"
                className="block text-sm text-slate-500"
              >
                {t('variations.name_hint')}
              </label>
              <input
                id="group-name"
                className="field max-w-md"
                maxLength={200}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
              />
            </fieldset>

            <div className="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
              <button
                className="button-primary"
                disabled={
                  blocked || form.data.features.length === 0 || form.processing
                }
              >
                {t(
                  form.processing ? 'common.wait' : 'variations.create_submit',
                )}
              </button>
              <Link className="button-secondary" href="/admin/variation-groups">
                {t('common.cancel')}
              </Link>
            </div>
          </form>
        )}
      </div>
    </AdminLayout>
  );
}
