import FormField from '../auth/FormField';
import RequiredMark from './RequiredMark';
import CountrySelect from './CountrySelect';
import Select from './Select';
import { useTranslation } from '../../lib/useTranslation';
import type {
  AddressInput,
  CustomerAddress,
  RegionOptions,
} from '../../types/customers';
import type { CountryOption } from '../../types/taxes';

export const emptyAddress = (
  first_name = '',
  last_name = '',
  delivery = false,
): AddressInput => ({
  label: '',
  first_name,
  last_name,
  company: '',
  line1: '',
  line2: '',
  city: '',
  postal_code: '',
  country_code: '',
  region_code: '',
  phone: '',
  ...(delivery
    ? { street_number: '', floor: '', bell_name: '', notes: '' }
    : {}),
});

export const addressInput = (a: CustomerAddress): AddressInput => ({
  label: a.label ?? '',
  first_name: a.first_name,
  last_name: a.last_name,
  company: a.company ?? '',
  line1: a.line1,
  line2: a.line2 ?? '',
  city: a.city,
  postal_code: a.postal_code ?? '',
  country_code: a.country_code,
  region_code: a.region_code ?? '',
  phone: a.phone ?? '',
  street_number: a.street_number ?? '',
  floor: a.floor ?? '',
  bell_name: a.bell_name ?? '',
  notes: a.notes ?? '',
});

/**
 * Postal address fields. `prefix` maps server errors such as "address.city". The region is chosen from the country's
 * Geography regions and the field is hidden for countries without regions (a saved value is kept until changed).
 */
export default function AddressFields({
  id,
  value,
  onChange,
  errors,
  countries,
  regions = {},
  prefix = '',
  delivery = false,
  streetNumber = true,
}: {
  id: string;
  value: AddressInput;
  onChange: (value: AddressInput) => void;
  errors: Record<string, string>;
  countries: CountryOption[];
  regions?: RegionOptions;
  prefix?: string;
  /** Also ask for the delivery details of a saved address: floor, name on the bell and a note. */
  delivery?: boolean;
  /** Order snapshots already include the street number in line1. */
  streetNumber?: boolean;
}) {
  const t = useTranslation();
  const available = regions[value.country_code] ?? [];
  const unlisted =
    value.region_code !== '' &&
    !available.some((r) => r.code === value.region_code);
  const field = (
    name: keyof AddressInput,
    label: string,
    extra: { required?: boolean; hint?: string; maxLength?: number } = {},
  ) => (
    <FormField
      id={id + '-' + name}
      label={label}
      requiredIndicator={extra.required}
      hint={extra.hint}
      maxLength={extra.maxLength}
      value={value[name] ?? ''}
      error={errors[prefix + name]}
      onChange={(e) => onChange({ ...value, [name]: e.target.value })}
    />
  );
  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        {field('label', t('customers.address_label'), {
          hint: t('customers.address_label_hint'),
          maxLength: 60,
        })}
        {field('company', t('customers.company'), { maxLength: 150 })}
        {field('first_name', t('customers.first_name'), {
          required: true,
          maxLength: 100,
        })}
        {field('last_name', t('customers.last_name'), {
          required: true,
          maxLength: 100,
        })}
        {field('line1', t('customers.line1'), {
          required: true,
          maxLength: 200,
        })}
        {delivery &&
          streetNumber &&
          field('street_number', t('customers.street_number'), {
            maxLength: 20,
          })}
        {field('line2', t('customers.line2'), { maxLength: 200 })}
        {field('city', t('customers.city'), { required: true, maxLength: 100 })}
        {field('postal_code', t('customers.postal_code'), {
          required: true,
          hint: t('customers.postal_code_hint'),
          maxLength: 20,
        })}
        <div className="space-y-2">
          <label
            htmlFor={id + '-country_code'}
            className="block text-sm font-medium text-slate-800"
          >
            {t('customers.country')}
            <RequiredMark />
          </label>
          <CountrySelect
            id={id + '-country_code'}
            value={value.country_code}
            invalid={!!errors[prefix + 'country_code']}
            aria-required
            onChange={(code) =>
              onChange({
                ...value,
                country_code: code,
                // A region belongs to one country; changing the country clears it.
                region_code:
                  code === value.country_code ? value.region_code : '',
              })
            }
            options={[
              {
                value: '',
                label: t('customers.country_choose'),
                disabled: true,
              },
              ...countries.map((c) => ({ value: c.code, label: c.name })),
            ]}
          />
          {errors[prefix + 'country_code'] && (
            <p role="alert" className="text-sm text-red-700">
              {errors[prefix + 'country_code']}
            </p>
          )}
        </div>
        {(value.country_code === '' || available.length > 0 || unlisted) && (
          <div className="space-y-2">
            <label
              htmlFor={id + '-region_code'}
              className="block text-sm font-medium text-slate-800"
            >
              {t('customers.region')}
              <RequiredMark />
            </label>
            <Select
              id={id + '-region_code'}
              value={value.region_code}
              disabled={value.country_code === ''}
              invalid={!!errors[prefix + 'region_code']}
              search={{
                label: t('common.search_options'),
                emptyMessage: t('common.no_options'),
              }}
              onChange={(code) => onChange({ ...value, region_code: code })}
              options={[
                {
                  value: '',
                  label: t(
                    value.country_code === ''
                      ? 'customers.region_choose_country'
                      : 'customers.region_choose',
                  ),
                  disabled: true,
                },
                ...(unlisted
                  ? [
                      {
                        value: value.region_code,
                        label: t('customers.region_unlisted', {
                          code: value.region_code,
                        }),
                      },
                    ]
                  : []),
                ...available.map((r) => ({ value: r.code, label: r.name })),
              ]}
            />
            {errors[prefix + 'region_code'] && (
              <p role="alert" className="text-sm text-red-700">
                {errors[prefix + 'region_code']}
              </p>
            )}
          </div>
        )}
        {field('phone', t('customers.phone'), { maxLength: 40 })}
        {delivery && (
          <>
            {field('floor', t('customers.floor'), { maxLength: 20 })}
            {field('bell_name', t('customers.bell_name'), {
              hint: t('customers.bell_name_hint'),
              maxLength: 100,
            })}
            <div className="space-y-2 sm:col-span-2">
              <label
                htmlFor={id + '-notes'}
                className="block text-sm font-medium text-slate-800"
              >
                {t('customers.address_notes')}
              </label>
              <textarea
                id={id + '-notes'}
                className="field min-h-24"
                maxLength={500}
                value={value.notes ?? ''}
                onChange={(e) => onChange({ ...value, notes: e.target.value })}
              />
              <p className="text-xs text-slate-500">
                {t('customers.address_notes_hint')}
              </p>
              {errors[prefix + 'notes'] && (
                <p role="alert" className="text-sm text-red-700">
                  {errors[prefix + 'notes']}
                </p>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
