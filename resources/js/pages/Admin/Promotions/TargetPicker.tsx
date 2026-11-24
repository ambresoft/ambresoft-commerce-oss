import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import Select from '../../../components/admin/Select';
import { SearchField } from '../../../components/admin/list/FilterBar';
import { useTranslation } from '../../../lib/useTranslation';
import type { PromotionOption } from '../../../types/promotions';

export default function TargetPicker({
  label,
  kind,
  values,
  options,
  path,
  onChange,
  single = false,
  query = {},
  ordered = false,
}: {
  label: string;
  kind: string;
  values: string[];
  options: PromotionOption[];
  path: string;
  onChange: (values: string[]) => void;
  single?: boolean;
  query?: Record<string, string>;
  ordered?: boolean;
}) {
  const t = useTranslation();
  const searchId = useId();
  const [search, setSearch] = useState('');
  const [names, setNames] = useState<Record<string, string>>({});
  return (
    <div className="min-w-0 space-y-3">
      <span className="block text-sm font-medium">{label}</span>
      <SearchField
        id={searchId}
        value={search}
        onChange={setSearch}
        placeholder={t('promotions.search_choices')}
        onSubmit={() =>
          router.get(
            path,
            { ...query, kind, search, selected: values.filter(Boolean) },
            { only: ['options'], preserveState: true, preserveScroll: true },
          )
        }
      />
      <Select
        aria-label={label}
        value={single ? (values[0] ?? '') : ''}
        onChange={(id) => {
          if (!id) {
            if (single) onChange([]);
            return;
          }
          setNames({
            ...names,
            [id]: options.find((o) => o.id === id)?.name ?? `#${id}`,
          });
          onChange(single ? [id] : [...values, id]);
        }}
        options={[
          { value: '', label: t('promotions.select') },
          ...options
            .filter((o) => single || !values.includes(o.id))
            .map((o) => ({ value: o.id, label: o.name })),
        ]}
      />
      {!single && (
        <ul className="divide-y divide-slate-100">
          {values.map((id, n) => (
            <li
              key={id || `missing-${n}`}
              className="flex items-center justify-between gap-3 py-2 text-sm"
            >
              <span>
                {!id
                  ? t('promotions.deleted_reference')
                  : (options.find((o) => o.id === id)?.name ??
                    names[id] ??
                    `#${id}`)}
              </span>
              {ordered && (
                <span className="ml-auto flex gap-2">
                  {[-1, 1].map((direction) => (
                    <button
                      key={direction}
                      type="button"
                      className="icon-button"
                      disabled={
                        n + direction < 0 || n + direction >= values.length
                      }
                      aria-label={t(
                        direction < 0 ? 'layouts.move_up' : 'layouts.move_down',
                      )}
                      onClick={() => {
                        const next = [...values];
                        [next[n], next[n + direction]] = [
                          next[n + direction],
                          next[n],
                        ];
                        onChange(next);
                      }}
                    >
                      {direction < 0 ? '↑' : '↓'}
                    </button>
                  ))}
                </span>
              )}
              <button
                type="button"
                className="text-link"
                onClick={() => onChange(values.filter((_, i) => i !== n))}
              >
                {t('promotions.remove')}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
