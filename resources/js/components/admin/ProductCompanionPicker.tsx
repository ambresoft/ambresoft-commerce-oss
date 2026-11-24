import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { SearchField } from './list/FilterBar';
import IconButton from './list/IconButton';
import { useTranslation } from '../../lib/useTranslation';

export type CompanionRow = {
  id: string;
  name: string;
  sku: string | null;
  image: string | null;
  price: string | null;
  status: string;
  deleted: boolean;
};

/**
 * Frequently bought together: search the catalog (server-side, like the other product pickers) and keep up to
 * `max` complementary products, each shown with its picture, SKU and current price.
 */
export default function ProductCompanionPicker({
  productId,
  values,
  rows,
  options,
  max,
  disabled = false,
  onChange,
}: {
  productId: string;
  values: string[];
  rows: CompanionRow[];
  options: CompanionRow[];
  max: number;
  disabled?: boolean;
  onChange: (ids: string[]) => void;
}) {
  const t = useTranslation();
  const searchId = useId();
  const [search, setSearch] = useState('');
  const [known, setKnown] = useState<Record<string, CompanionRow>>({});
  const describe = (id: string) =>
    rows.find((r) => r.id === id) ??
    known[id] ??
    options.find((r) => r.id === id);
  const full = values.length >= max;
  const candidates = options.filter(
    (o) => o.id !== productId && !values.includes(o.id),
  );
  const row = (r: CompanionRow, action: React.ReactNode) => (
    <li key={r.id} className="flex items-center gap-3 py-3">
      <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50">
        {r.image ? (
          <img src={r.image} alt="" className="size-full object-cover" />
        ) : (
          <span className="text-xs text-slate-400">—</span>
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">{r.name}</span>
        <span className="block text-xs text-slate-500">
          {[r.sku, r.price].filter(Boolean).join(' · ')}
          {(r.deleted || r.status !== 'active') && (
            <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">
              {t('products.companion_unavailable')}
            </span>
          )}
        </span>
      </span>
      {action}
    </li>
  );
  return (
    <div className="space-y-5">
      <div>
        <h3 className="mb-1 text-sm font-medium">
          {t('products.companions_selected', {
            count: String(values.length),
            max: String(max),
          })}
        </h3>
        {values.length === 0 ? (
          <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
            {t('products.companions_empty')}
          </p>
        ) : (
          <ol className="divide-y divide-slate-100">
            {values.map((id) => {
              const r = describe(id) ?? {
                id,
                name: `#${id}`,
                sku: null,
                image: null,
                price: null,
                status: 'active',
                deleted: false,
              };
              return row(
                r,
                !disabled && (
                  <IconButton
                    icon="trash"
                    label={t('products.companion_remove', { name: r.name })}
                    onClick={() => onChange(values.filter((v) => v !== id))}
                  />
                ),
              );
            })}
          </ol>
        )}
      </div>
      {!disabled && (
        <div className="space-y-3">
          <label htmlFor={searchId} className="block text-sm font-medium">
            {t('products.companion_search')}
          </label>
          <SearchField
            id={searchId}
            value={search}
            onChange={setSearch}
            placeholder={t('products.companion_search_placeholder')}
            onSubmit={() =>
              router.get(
                `/admin/products/${productId}/edit`,
                { kind: 'companions', search, selected: values },
                {
                  only: ['companions', 'companionOptions'],
                  preserveState: true,
                  preserveScroll: true,
                },
              )
            }
          />
          {full && (
            <p className="text-sm text-slate-500">
              {t('products.companions_full', { max: String(max) })}
            </p>
          )}
          {candidates.length > 0 && (
            <ul className="divide-y divide-slate-100">
              {candidates.map((r) =>
                row(
                  r,
                  <button
                    type="button"
                    className="button-secondary"
                    disabled={full}
                    onClick={() => {
                      setKnown({ ...known, [r.id]: r });
                      onChange([...values, r.id]);
                    }}
                  >
                    {t('products.companion_add')}
                  </button>,
                ),
              )}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
