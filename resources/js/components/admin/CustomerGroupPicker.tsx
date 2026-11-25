import { Check } from './form/fields';
import { useTranslation } from '../../lib/useTranslation';

export type CustomerGroupOption = {
  id: string;
  name: string;
  active: boolean;
};

/** "All" (nothing selected) or members of at least one ticked group, as on Pages. */
export default function CustomerGroupPicker({
  id,
  label,
  help,
  allLabel,
  groups,
  value,
  onChange,
  error,
  disabled,
}: {
  id: string;
  label: string;
  help: string;
  allLabel: string;
  groups: CustomerGroupOption[];
  value: string[];
  onChange: (value: string[]) => void;
  error?: string;
  disabled?: boolean;
}) {
  const t = useTranslation();
  return (
    <fieldset aria-describedby={id + '-help'}>
      <legend className="text-sm font-medium">{label}</legend>
      <div className="flex flex-wrap gap-x-5 gap-y-1">
        <Check
          id={id + '-all'}
          label={allLabel}
          disabled={disabled}
          checked={value.length === 0}
          onChange={(on) => on && onChange([])}
        />
        {groups.map((group) => (
          <Check
            key={group.id}
            id={id + '-' + group.id}
            label={
              group.active
                ? group.name
                : group.name + ' (' + t('common.inactive') + ')'
            }
            disabled={disabled}
            checked={value.includes(group.id)}
            onChange={(on) =>
              onChange(
                on
                  ? [...value, group.id]
                  : value.filter((selected) => selected !== group.id),
              )
            }
          />
        ))}
      </div>
      <p id={id + '-help'} className="mt-2 text-sm text-slate-500">
        {help}
      </p>
      {error && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {error}
        </p>
      )}
    </fieldset>
  );
}
