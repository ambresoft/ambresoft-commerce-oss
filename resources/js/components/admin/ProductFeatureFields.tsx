import AdminCard from './AdminCard';
import { usePage } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import {
  emptyFeatureValue,
  type Feature,
  type FeatureEditor,
  type FeatureValue,
} from '../../types/features';
import type { SharedProps } from '../../types/page';
import Select from './Select';

// Text and multiple choices need the full section width; other types share a two-column grid.
const wide = (f: Feature) => f.type === 'text' || f.type === 'multiple';

function CheckCircle({ checked }: { checked: boolean }) {
  return checked ? (
    <svg aria-hidden="true" viewBox="0 0 20 20" className="size-4 shrink-0">
      <circle cx="10" cy="10" r="9" fill="var(--color-primary)" />
      <path
        d="m6 10.5 2.5 2.5 5.5-6"
        fill="none"
        stroke="white"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  ) : (
    <span
      aria-hidden="true"
      className="size-4 shrink-0 rounded-full border-[1.5px] border-slate-300 bg-white"
    />
  );
}

export default function ProductFeatureFields({
  locale,
  editor,
  values,
  onChange,
  categoryChanged,
  errors = {},
}: {
  locale?: string;
  editor: FeatureEditor;
  values: Record<string, FeatureValue>;
  onChange: (values: Record<string, FeatureValue>) => void;
  categoryChanged: boolean;
  errors?: Record<string, string>;
}) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  return (
    <div className="space-y-6">
      {categoryChanged && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          {t('features.category_save_first')}
        </p>
      )}
      {editor.missing.length > 0 && (
        <p
          role="alert"
          className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
          {t('features.remediation_help')}
        </p>
      )}
      {editor.groups.map((g) => {
        const features = editor.definitions.filter((f) => f.group_id === g.id);
        if (!features.length) return null;
        return (
          <AdminCard
            key={g.id}
            id={'feature-group-' + g.id}
            title={g.name}
            iconKey="features"
          >
            <div className="grid gap-x-5 gap-y-5 sm:grid-cols-2">
              {features.map((f) => {
                const v =
                  values[f.id] ?? editor.values[f.id] ?? emptyFeatureValue();
                const set = (patch: Partial<FeatureValue>) =>
                  onChange({ ...values, [f.id]: { ...v, ...patch } });
                const readOnly = f.readonly || categoryChanged;
                const name = f.name || f.code;
                const choices = f.options.filter(
                  (o) => o.status === 'active' || v.options.includes(o.id),
                );
                const fieldErrors = Object.entries(errors).filter(
                  ([key]) =>
                    key === `features.${f.id}` ||
                    key.startsWith(`features.${f.id}.`),
                );
                const invalid =
                  fieldErrors.length > 0 || editor.missing.includes(f.id);
                return (
                  <fieldset
                    className={`relative min-w-0 space-y-2 ${wide(f) ? 'sm:col-span-2' : ''}`}
                    key={f.id}
                    disabled={readOnly}
                  >
                    {/* The legend stays the fieldset's first child so it names the group. */}
                    <legend className="pr-24 text-sm font-medium text-slate-700">
                      {name}
                      {f.required && (
                        <span aria-hidden="true" className="text-red-600">
                          {' '}
                          *
                        </span>
                      )}
                      {f.status === 'archived' &&
                        ' · ' + t('features.archived')}
                      {f.historical && ' · ' + t('features.historical')}
                    </legend>
                    {f.type === 'multiple' && v.options.length > 0 && (
                      <span className="absolute top-0 right-0 text-xs text-slate-500">
                        {t('common.selected', { count: v.options.length })}
                      </span>
                    )}

                    {f.type === 'text' && (
                      <div className="space-y-2">
                        {i18n.locales
                          .filter((l) => !locale || l.code === locale)
                          .map((l) => (
                            <div
                              key={l.code}
                              className={`flex items-center gap-3 rounded-lg border bg-white px-3 transition-colors focus-within:border-primary-hover ${invalid ? 'border-red-300' : 'border-slate-200'}`}
                            >
                              <label
                                htmlFor={`feature-${f.id}-${l.code}`}
                                className="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                              >
                                {l.name}
                              </label>
                              <input
                                id={`feature-${f.id}-${l.code}`}
                                lang={l.code}
                                className="h-10 min-w-0 flex-1 bg-transparent text-sm outline-none"
                                value={v.texts[l.code] ?? ''}
                                aria-invalid={invalid || undefined}
                                onChange={(e) =>
                                  set({
                                    texts: {
                                      ...v.texts,
                                      [l.code]: e.target.value,
                                    },
                                  })
                                }
                              />
                            </div>
                          ))}
                      </div>
                    )}

                    {f.type === 'number' && (
                      <div className="relative">
                        <input
                          className={`field h-10 rounded-lg ${f.unit ? 'pr-14' : ''}`}
                          inputMode="decimal"
                          aria-label={name}
                          aria-invalid={invalid || undefined}
                          value={v.number ?? ''}
                          onChange={(e) => set({ number: e.target.value })}
                        />
                        {f.unit && (
                          <span
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-y-2 right-0 flex items-center border-l border-slate-200 px-3 text-sm text-slate-500"
                          >
                            {f.unit}
                          </span>
                        )}
                      </div>
                    )}

                    {f.type === 'boolean' && (
                      <Select
                        aria-label={name}
                        className="h-10 rounded-lg"
                        invalid={invalid}
                        value={v.boolean ?? ''}
                        onChange={(value) => set({ boolean: value })}
                        options={['', '1', '0'].map((value) => ({
                          value,
                          label: t(
                            value === ''
                              ? 'features.unset'
                              : value === '1'
                                ? 'features.yes'
                                : 'features.no',
                          ),
                        }))}
                      />
                    )}

                    {f.type === 'single' && (
                      <Select
                        aria-label={name}
                        className="h-10 rounded-lg"
                        invalid={invalid}
                        value={v.options[0] ?? ''}
                        onChange={(value) =>
                          set({ options: value ? [value] : [] })
                        }
                        options={[
                          { value: '', label: t('features.unset') },
                          ...choices.map((o) => ({
                            value: o.id,
                            label:
                              (o.label || o.id) +
                              (o.status === 'archived'
                                ? ' · ' + t('features.archived')
                                : ''),
                            disabled: o.status === 'archived',
                          })),
                        ]}
                      />
                    )}

                    {f.type === 'multiple' && (
                      <div className="flex flex-wrap gap-2">
                        {choices.map((o) => {
                          const checked = v.options.includes(o.id);
                          const locked = o.status === 'archived' && !checked;
                          return (
                            <label
                              key={o.id}
                              className={`inline-flex min-h-9 items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-hover ${checked ? 'border-emerald-200 bg-primary-tint font-semibold text-emerald-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'} ${locked || readOnly ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'}`}
                            >
                              <input
                                type="checkbox"
                                className="sr-only"
                                checked={checked}
                                disabled={locked}
                                onChange={(e) =>
                                  set({
                                    options: e.target.checked
                                      ? [...v.options, o.id]
                                      : v.options.filter((id) => id !== o.id),
                                  })
                                }
                              />
                              <CheckCircle checked={checked} />
                              {o.swatch_color && (
                                <span
                                  aria-hidden="true"
                                  className="size-3.5 rounded-full border border-slate-300"
                                  style={{ background: o.swatch_color }}
                                />
                              )}
                              {o.label || o.id}
                              {o.status === 'archived'
                                ? ' · ' + t('features.archived')
                                : ''}
                            </label>
                          );
                        })}
                      </div>
                    )}

                    {fieldErrors.map(([key, message]) => (
                      <p
                        key={key}
                        role="alert"
                        className="text-sm text-red-700"
                      >
                        {message}
                      </p>
                    ))}
                    {editor.missing.includes(f.id) && (
                      <p className="text-sm text-red-700">
                        {t('features.required_missing')}
                      </p>
                    )}
                    {f.options.some(
                      (o) =>
                        o.status === 'archived' && v.options.includes(o.id),
                    ) && (
                      <p className="text-sm text-amber-700">
                        {t('features.archived_option_warning')}
                      </p>
                    )}
                  </fieldset>
                );
              })}
            </div>
          </AdminCard>
        );
      })}
      {editor.definitions.length === 0 && (
        <p className="text-sm text-slate-500">{t('features.no_applicable')}</p>
      )}
    </div>
  );
}
