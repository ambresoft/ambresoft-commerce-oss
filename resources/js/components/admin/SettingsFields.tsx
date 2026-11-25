import FormField from '../auth/FormField';
import RequiredMark from './RequiredMark';
import Select from './Select';
import { useTranslation } from '../../lib/useTranslation';
import type { SettingFieldView } from '../../types/shipping';

/**
 * Renders fields declared by an extension (carrier settings, carrier accounts, shipment forms). Secrets are
 * write-only: the input starts empty, a stored value is only announced and can be removed explicitly.
 */
export default function SettingsFields({
  fields,
  values,
  clear = {},
  errors,
  prefix,
  idPrefix = prefix.replace(/\W/g, '-'),
  onChange,
  onClear,
}: {
  fields: SettingFieldView[];
  values: Record<string, string>;
  clear?: Record<string, boolean>;
  errors: Record<string, string>;
  /** Error key prefix, e.g. "settings." */
  prefix: string;
  /** Element ID prefix, when the error prefix alone would not keep IDs unique on the page. */
  idPrefix?: string;
  onChange: (key: string, value: string) => void;
  onClear?: (key: string, value: boolean) => void;
}) {
  const t = useTranslation();
  return (
    <>
      {fields.map((field) => {
        const id = idPrefix + field.key;
        const error = errors[prefix + field.key];
        const value = values[field.key] ?? '';
        if (field.type === 'boolean') {
          return (
            <label key={field.key} className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={value === '1'}
                onChange={(e) =>
                  onChange(field.key, e.target.checked ? '1' : '0')
                }
              />
              <span>
                <span className="font-medium">{field.label}</span>
                {field.hint && (
                  <span className="block text-xs text-slate-500">
                    {field.hint}
                  </span>
                )}
                {error && (
                  <span role="alert" className="block text-sm text-red-700">
                    {error}
                  </span>
                )}
              </span>
            </label>
          );
        }
        if (['select', 'order_status', 'payment_method'].includes(field.type)) {
          const options =
            field.type === 'select'
              ? field.options
              : [
                  { value: '', label: t('shipping.none_option') },
                  ...field.options,
                ];
          return (
            <div key={field.key} className="space-y-2">
              <label
                className="block text-sm font-medium text-slate-800"
                htmlFor={id}
              >
                {field.label}
                {field.required && <RequiredMark />}
              </label>
              <Select
                id={id}
                aria-label={field.label}
                value={value}
                invalid={!!error}
                onChange={(next) => onChange(field.key, next)}
                options={options}
              />
              {field.hint && (
                <p className="text-xs text-slate-500">{field.hint}</p>
              )}
              {error && (
                <p role="alert" className="text-sm text-red-700">
                  {error}
                </p>
              )}
            </div>
          );
        }
        if (field.type === 'textarea') {
          return (
            <div key={field.key} className="space-y-2">
              <label
                className="block text-sm font-medium text-slate-800"
                htmlFor={id}
              >
                {field.label}
                {field.required && <RequiredMark />}
              </label>
              <textarea
                id={id}
                className="field min-h-20"
                maxLength={field.max_length}
                value={value}
                aria-invalid={error ? true : undefined}
                onChange={(e) => onChange(field.key, e.target.value)}
              />
              {field.hint && (
                <p className="text-xs text-slate-500">{field.hint}</p>
              )}
              {error && (
                <p role="alert" className="text-sm text-red-700">
                  {error}
                </p>
              )}
            </div>
          );
        }
        return (
          <div key={field.key} className="space-y-2">
            <FormField
              id={id}
              type={
                field.type === 'secret'
                  ? 'password'
                  : field.type === 'date'
                    ? 'date'
                    : 'text'
              }
              inputMode={
                field.type === 'integer'
                  ? 'numeric'
                  : field.type === 'decimal'
                    ? 'decimal'
                    : undefined
              }
              autoComplete={field.type === 'secret' ? 'new-password' : 'off'}
              label={field.label}
              requiredIndicator={field.required}
              maxLength={field.max_length}
              value={value}
              error={error}
              hint={
                field.type === 'secret' && field.set
                  ? t('shipping.secret_set')
                  : field.hint
              }
              onChange={(e) => onChange(field.key, e.target.value)}
            />
            {field.type === 'secret' && field.set && onClear && (
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={clear[field.key] ?? false}
                  onChange={(e) => onClear(field.key, e.target.checked)}
                />
                {t('shipping.secret_clear')}
              </label>
            )}
          </div>
        );
      })}
    </>
  );
}

/** Initial values of declared fields (secrets always start empty). */
export function fieldValues(
  fields: SettingFieldView[],
): Record<string, string> {
  return Object.fromEntries(
    fields.map((f) => [f.key, f.type === 'secret' ? '' : f.value]),
  );
}
