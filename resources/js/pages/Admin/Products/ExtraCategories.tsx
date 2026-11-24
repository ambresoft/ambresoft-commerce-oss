import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Select from '../../../components/admin/Select';
import { Icon } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';

type Option = { id: string; name: string };

/**
 * Extra categories a product is also listed in (CS-Cart's "additional categories"): chips in order, removable, and a
 * live-search select that adds any category. The main category alone keeps setting the address and breadcrumb.
 */
export default function ExtraCategories({
  value,
  options,
  mainId,
  onChange,
  error,
  disabled,
}: {
  value: string[];
  options: Option[];
  mainId: string;
  onChange: (value: string[]) => void;
  error?: string;
  disabled?: boolean;
}) {
  const t = useTranslation();
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
  // Names of chosen categories survive later searches that no longer return them.
  const [names, setNames] = useState<Record<string, string>>(() =>
    Object.fromEntries(options.map((o) => [o.id, o.name])),
  );
  const name = (id: string) =>
    names[id] ?? options.find((o) => o.id === id)?.name ?? '#' + id;
  return (
    <div className="space-y-3">
      <div>
        <span id="extra-categories-label" className="block text-sm font-medium">
          {t('products.extra_categories')}
        </span>
        <p className="mt-1 text-xs text-slate-500">
          {t('products.extra_categories_help')}
        </p>
      </div>
      {value.length > 0 && (
        <ul
          className="flex flex-wrap gap-2"
          aria-labelledby="extra-categories-label"
        >
          {value.map((id) => (
            <li
              key={id}
              className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 py-1 pr-1 pl-3 text-sm"
            >
              {name(id)}
              <button
                type="button"
                disabled={disabled}
                className="rounded-full p-0.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                aria-label={t('products.extra_category_remove', {
                  name: name(id),
                })}
                onClick={() => onChange(value.filter((v) => v !== id))}
              >
                <Icon name="x" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <Select
        id="extra-category-add"
        aria-label={t('products.extra_category_add')}
        value=""
        disabled={disabled || value.length >= 50}
        options={[
          { value: '', label: t('products.extra_category_add') },
          ...options
            .filter((o) => o.id !== mainId && !value.includes(o.id))
            .map((o) => ({ value: o.id, label: o.name })),
        ]}
        search={{
          label: t('common.search'),
          emptyMessage: t('categories.parent_none'),
          onChange: (query) => {
            clearTimeout(timer.current);
            timer.current = setTimeout(
              () =>
                router.reload({
                  data: { extra_category_search: query },
                  only: ['extraCategoryOptions'],
                  preserveUrl: true,
                }),
              300,
            );
          },
        }}
        onChange={(id) => {
          if (!id) return;
          const option = options.find((o) => o.id === id);
          if (option)
            setNames((current) => ({ ...current, [id]: option.name }));
          onChange([...value, id]);
        }}
      />
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
