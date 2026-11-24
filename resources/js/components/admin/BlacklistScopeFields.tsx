import { useTranslation } from '../../lib/useTranslation';

export type BlacklistScope = 'full' | 'payment_methods';

/**
 * What a blacklist entry does: a full block (the original behaviour) or only taking some payment methods away at
 * checkout, e.g. cash on delivery for a buyer who refuses deliveries.
 */
export default function BlacklistScopeFields({
  scope,
  methods,
  options,
  onScope,
  onMethods,
  error,
}: {
  scope: BlacklistScope;
  methods: string[];
  options: { id: string; name: string }[];
  onScope: (scope: BlacklistScope) => void;
  onMethods: (ids: string[]) => void;
  error?: string;
}) {
  const t = useTranslation();
  return (
    <fieldset className="space-y-3">
      <legend className="mb-2 text-sm font-medium">
        {t('customers.blacklist_scope')}
      </legend>
      {(['full', 'payment_methods'] as const).map((value) => (
        <label
          key={value}
          className={`flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 text-sm transition-colors ${
            scope === value
              ? 'border-primary bg-primary-tint/50 ring-2 ring-primary/20'
              : 'border-slate-200 hover:bg-slate-50'
          }`}
        >
          <input
            type="radio"
            name="blacklist-scope"
            className="mt-0.5 size-4 accent-[var(--color-primary)]"
            checked={scope === value}
            onChange={() => onScope(value)}
          />
          <span>
            <span className="block font-semibold">
              {t(
                value === 'full'
                  ? 'customers.blacklist_scope_full'
                  : 'customers.blacklist_scope_payment',
              )}
            </span>
            <span className="mt-0.5 block text-xs text-slate-500">
              {t(
                value === 'full'
                  ? 'customers.blacklist_scope_full_help'
                  : 'customers.blacklist_scope_payment_help',
              )}
            </span>
          </span>
        </label>
      ))}
      {scope === 'payment_methods' && (
        <div
          role="group"
          aria-label={t('customers.blacklist_methods')}
          className="space-y-2 rounded-lg border border-slate-200 p-4"
        >
          <p className="text-sm font-medium">
            {t('customers.blacklist_methods')}
          </p>
          {options.map((option) => (
            <label key={option.id} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-[var(--color-primary)]"
                checked={methods.includes(option.id)}
                onChange={(e) =>
                  onMethods(
                    e.target.checked
                      ? [...methods, option.id]
                      : methods.filter((id) => id !== option.id),
                  )
                }
              />
              {option.name}
            </label>
          ))}
        </div>
      )}
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
    </fieldset>
  );
}
